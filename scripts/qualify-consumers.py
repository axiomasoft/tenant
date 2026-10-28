#!/usr/bin/env python3
"""Consumer qualification. Required missing checks fail; partial probes never imply acceptance."""
import argparse
import datetime
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import time

ROOT = pathlib.Path(__file__).resolve().parents[1]
PLAN = next((ROOT / 'plans').glob('*TENANT')).resolve()
LANES = {'php84-l13': '/usr/bin/php8.4', 'php85-l13': '/usr/bin/php8.5'}


def sha(path):
    return hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest()


def save(path, value):
    path = pathlib.Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')


def command(argv, cwd, env, log):
    started = datetime.datetime.now(datetime.timezone.utc).isoformat()
    t = time.monotonic()
    result = subprocess.run(argv, cwd=cwd, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    pathlib.Path(log).parent.mkdir(parents=True, exist_ok=True)
    pathlib.Path(log).write_bytes(result.stdout)
    return {'argv': argv, 'cwd': str(cwd), 'started_at': started, 'duration': time.monotonic() - t,
            'exit': result.returncode, 'status': 'passed' if result.returncode == 0 else 'failed',
            'raw': str(log), 'sha256': sha(log)}


def preflight(manifest):
    for service, cid in manifest['containers'].items():
        observed = json.loads(subprocess.check_output(['docker', 'inspect', cid]))[0]
        assert observed['State']['Running']
        assert observed['Config']['Labels']['com.docker.compose.project'] == manifest['project']
        tag = 'postgres:16-alpine' if service == 'pg' else 'redis:7-alpine'
        assert observed['Image'] == manifest['images'][tag]['id']
        assert [m['Name'] for m in observed['Mounts'] if m['Type'] == 'volume'] == manifest['volumeIds'][service]
        assert list(observed['NetworkSettings']['Networks'].values())[0]['NetworkID'] == manifest['networkIds'][service]
    observed = subprocess.check_output(['docker', 'exec', manifest['containers']['pg'], 'psql', '-X', '-At', '-U',
                                        manifest['admin'], '-d', manifest['databases']['central'], '-c',
                                        'select system_identifier from pg_control_system()'], text=True).strip()
    assert observed == manifest['systemIdentifier']
    for label, database in manifest['databases'].items():
        assert database == 'tenant_' + manifest['runId'] + '_' + label + '_test'
    root = pathlib.Path(manifest['root'])
    assert root.parent == pathlib.Path('/tmp') and root.name.startswith('tenant_lab_') and not root.is_symlink()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--item', required=True, choices=['P5.1'])
    parser.add_argument('--stage', required=True, choices=['prepare', 'consumer', 'trace', 'boundary', 'package', 'ledger', 'aggregate'])
    parser.add_argument('--lanes', required=True)
    parser.add_argument('--checkout-manifest', type=pathlib.Path, required=True)
    parser.add_argument('--run-manifest', type=pathlib.Path, required=True)
    parser.add_argument('--output', type=pathlib.Path, required=True)
    args = parser.parse_args()
    art = PLAN / 'artifacts' / args.item
    manifest = json.loads(args.run_manifest.read_text())
    checkout_manifest = json.loads(args.checkout_manifest.read_text())
    checkout = pathlib.Path(checkout_manifest['checkout'])
    lanes = args.lanes.split(',')
    assert lanes and len(set(lanes)) == len(lanes) and all(lane in LANES for lane in lanes)
    preflight(manifest)
    source_hashes = {str(p.relative_to(checkout)): sha(p) for base in ['core', 'tenant', 'tenancy']
                     for p in (checkout / 'packages' / base).rglob('*.php') if 'vendor' not in p.parts}
    report = {'item': args.item, 'stage': args.stage, 'status': 'failed', 'commands': [],
              'sourceHashes': source_hashes, 'corpusHash': sha(ROOT / 'contracts/fixtures/index.json'),
              'checkoutManifestHash': sha(args.checkout_manifest), 'labManifestHash': sha(args.run_manifest),
              'missing': [], 'lanes': lanes}
    if args.stage in ['prepare', 'consumer', 'boundary']:
        for lane in lanes:
            env = {**os.environ, 'COREX_CONSUMER_CHECKOUT': str(checkout), 'APP_ENV': 'testing', 'TENANT_LAB_MANIFEST': str(args.run_manifest.resolve()),
                   'TENANT_LANE_AUTOLOAD': str(art / 'hosts' / lane / 'vendor/autoload.php'), 'TENANT_USE_SOURCE': '0'}
            record = command([LANES[lane], str(ROOT / 'tests/laravel/Support/preflight.php')], ROOT, env,
                             art / lane / (args.stage + '-preflight.log'))
            report['commands'].append(record)
            if record['exit'] != 0:
                report['missing'].append(lane + ': isolation preflight failed')
                continue
            if args.stage == 'consumer':
                report['commands'].append(command([LANES[lane], str(ROOT / 'tests/consumers/corex/PhysicalOwnerProbe.php')],
                                                  ROOT, env, art / lane / 'physical-owner.log'))
    if args.stage == 'package':
        archives = json.loads((art / 'archives.json').read_text())
        for lane in lanes:
            for package, metadata in archives.items():
                assert sha(metadata['path']) == metadata['sha256']
                for rel, expected in metadata['files'].items():
                    installed = art / 'hosts' / lane / 'vendor/axiomasoft' / package / rel
                    if not installed.is_file() or sha(installed) != expected:
                        report['missing'].append(f'{lane}: installed byte mismatch {package}/{rel}')
            report.setdefault('lockHashes', {})[lane] = sha(art / 'hosts' / lane / 'composer.lock')
    # These are mandatory source-cohort gates, never waived by the partial physical probe.
    registrar = (checkout / 'packages/tenancy/src/Laravel/RuntimeRegistrar.php').read_text()
    if 'TenancyRuntimeFactory::manager($app)' in registrar:
        report['missing'].append('Active RuntimeRegistrar still binds legacy DatabaseTenancyManager')
    provider = checkout / 'packages/tenant/src/CorexTenantServiceProvider.php'
    if not provider.is_file():
        report['missing'].append('Complete public provider/config/HTTP/queue cohort not implemented')
    report['missing'].append('Full caller dispositions, retained source-test adaptations and original P5.1 portions remain unqualified')
    if args.stage == 'boundary':
        report['missing'].append('Actual consumer worker/retry and file+Redis socket HTTP through Kernel terminate not yet executed')
    save(args.output, report)
    print(json.dumps({'stage': args.stage, 'status': report['status'], 'missing': report['missing']}, ensure_ascii=False))
    return 1


if __name__ == '__main__':
    sys.exit(main())
