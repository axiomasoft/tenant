#!/usr/bin/env python3
"""P5.4 source/installed qualification. Recorded producer passes never qualify this switch."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import subprocess
import time
import zipfile
import re
import shutil

ROOT = Path(__file__).resolve().parents[1]
PLAN = ROOT / 'plans/2026.10.24-№1-TENANT'
ART = PLAN / 'artifacts/P5.4'
LANES = {'php84-l13': '/usr/bin/php8.4', 'php85-l13': '/usr/bin/php8.5', 'php84-l12': '/usr/bin/php8.4'}


def sha(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


def save(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + '\n')


def command(argv, cwd, log, env=None):
    log.parent.mkdir(parents=True, exist_ok=True)
    if log.exists():
        raise RuntimeError('Immutable command log exists: ' + str(log))
    started = datetime.datetime.now(datetime.timezone.utc).isoformat()
    clock = time.monotonic()
    with log.open('xb') as stream:
        result = subprocess.run(argv, cwd=cwd, env={**os.environ, 'APP_ENV': 'testing', 'XDEBUG_MODE': 'off', **(env or {})},
                                stdout=stream, stderr=subprocess.STDOUT)
    record = {'argv': argv, 'cwd': str(cwd), 'started': started, 'durationSeconds': time.monotonic() - clock,
              'exit': result.returncode, 'status': 'passed' if result.returncode == 0 else 'failed',
              'raw': str(log), 'sha256': sha(log)}
    save(log.with_suffix('.command.json'), record)
    print(log.name, record['status'], flush=True)
    if result.returncode:
        raise RuntimeError('Command failed: ' + str(log))
    return record


def packages(checkout):
    return {'tenant': ROOT / 'packages/core', 'tenant-laravel': ROOT / 'packages/laravel',
            'tenant-postgres': ROOT / 'packages/postgres', 'corex': checkout / 'packages/core',
            'corex-tenancy': checkout / 'packages/tenancy', 'corex-tenant': checkout / 'packages/tenant',
            'corex-intl': checkout / 'packages/intl', 'flex-crm': checkout / 'products/flex-crm'}


def sources(checkout):
    rows = {}
    for label, directory in packages(checkout).items():
        for path in sorted(directory.rglob('*')):
            if path.is_file() and not any(x in path.relative_to(directory).parts for x in ['vendor', 'node_modules', '__pycache__']):
                rows[label + '/' + str(path.relative_to(directory))] = sha(path)
    for directory in [ROOT / 'tests/consumers/postgres']:
        for path in sorted(directory.rglob('*')):
            if path.is_file() and '__pycache__' not in path.parts:
                rows['tests/' + str(path.relative_to(ROOT / 'tests'))] = sha(path)
    for name in ['scripts/qualify-corex-postgres.py', 'tests/postgres/consumer-fixtures.php', 'tests/postgres/lifecycle-support.php', 'tests/postgres/lab/preflight.py']:
        rows[name] = sha(ROOT / name)
    return rows


def prepare(args, checkout, source):
    generation = ART / 'generations' / digest(source)
    archive_dir = generation / 'archives'
    archive_dir.mkdir(parents=True, exist_ok=True)
    archives = {}
    for label, directory in packages(checkout).items():
        target = archive_dir / (label + '.zip')
        content = {}
        for path in sorted(directory.rglob('*')):
            if not path.is_file() or any(x in path.relative_to(directory).parts for x in ['vendor', 'node_modules', 'tests', '__pycache__']):
                continue
            relative = str(path.relative_to(directory))
            body = path.read_bytes()
            if relative == 'composer.json':
                meta = json.loads(body)
                meta['version'] = '1.0.0'
                body = json.dumps(meta, indent=4).encode()
            content[relative] = body
        if not target.exists():
            with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as archive:
                for relative, body in content.items():
                    info = zipfile.ZipInfo(label + '/' + relative, date_time=(2026, 10, 29, 0, 0, 0))
                    info.compress_type = zipfile.ZIP_DEFLATED
                    info.external_attr = 0o100644 << 16
                    archive.writestr(info, body)
        with zipfile.ZipFile(target) as archive:
            assert set(archive.namelist()) == {label + '/' + x for x in content}
            for relative, body in content.items():
                assert archive.read(label + '/' + relative) == body
        archives[label] = {'path': str(target), 'sha256': sha(target),
                           'files': {k: hashlib.sha256(v).hexdigest() for k, v in content.items()}}
    save(generation / 'archives.json', archives)
    commands = []
    hosts = {}
    for lane in args.lanes.split(',') + ['php84-l12']:
        host = generation / 'hosts' / lane
        host.mkdir(parents=True, exist_ok=True)
        template = json.loads((ROOT / 'tests/laravel/hosts' / lane / 'composer.json').read_text())
        template.pop('require-dev', None)
        template.pop('autoload-dev', None)
        required = ['tenant', 'tenant-postgres', 'tenant-laravel'] if lane == 'php84-l12' else list(archives)
        for key in required:
            meta = json.loads((packages(checkout)[key] / 'composer.json').read_text())
            template['require'][meta['name']] = '1.0.0'
        template['repositories'] = [{'type': 'artifact', 'url': str(archive_dir)}]
        (host / 'composer.json').write_text(json.dumps(template, indent=4) + '\n')
        if not (host / 'vendor/autoload.php').exists():
            commands.append(command([LANES[lane], '/usr/local/bin/composer', 'update', '--no-dev', '--no-audit', '--no-scripts',
                                     '--no-interaction', '--prefer-dist'], host, generation / 'logs' / (lane + '-install.log'), {'COMPOSER_DISABLE_NETWORK': '1'}))
        installed = {}
        for key in required:
            meta = json.loads((packages(checkout)[key] / 'composer.json').read_text())
            root = host / 'vendor' / meta['name']
            for relative, expected in archives[key]['files'].items():
                assert sha(root / relative) == expected, (lane, key, relative)
            installed[key] = {'path': str(root), 'files': len(archives[key]['files'])}
        hosts[lane] = {'root': str(host), 'lockSha256': sha(host / 'composer.lock'), 'installed': installed,
                       'php': LANES[lane], 'phpSha256': sha(LANES[lane]),
                       'phpVersion': subprocess.check_output([LANES[lane], '-r', 'echo PHP_VERSION;'], text=True)}
    labs = {}
    for lane in args.lanes.split(','):
        labs[lane] = {}
        for mode in ['source', 'installed']:
            folder = generation / 'labs' / lane / mode
            path = folder / 'lab-manifest.json'
            if not path.exists():
                assert shutil.disk_usage('/tmp').free > 300 * 1024**2, 'Insufficient headroom; preserve existing labs.'
                commands.append(command(['python3', '-B', str(ROOT/'tests/consumers/postgres/setup-lab.py'), '--output', str(path)], ROOT, folder/'infrastructure.log'))
            env = {'TENANT_LAB_MANIFEST': str(path)}
            preflight = folder/'prepare-preflight.log'
            if not preflight.exists():
                commands.append(command(['python3', '-B', str(ROOT/'tests/consumers/postgres/preflight.py')], ROOT, preflight, env))
            labs[lane][mode] = {'path': str(path), 'sha256': sha(path)}
    lab = {'item': 'P5.4', 'sourceDigest': digest(source), 'generation': str(generation), 'labs': labs,
           'hosts': hosts, 'scope': 'Unique disposable PG16/Redis7; exact manifests carry identities, roles and DB allowlists; no working DSN',
           'diskHeadroom': shutil.disk_usage('/tmp').free}
    save(args.run_manifest, lab)
    result = {'stage': 'prepare', 'status': 'passed', 'sourceDigest': digest(source), 'sourceHashes': source,
              'archives': archives, 'hosts': hosts, 'commands': commands, 'labStatus': 'prepared', 'labManifestSha256': sha(args.run_manifest)}
    save(generation / 'prepare.json', result)
    save(args.output, result)
    print(json.dumps({'stage': 'prepare', 'status': 'passed', 'generation': str(generation)}))


def environment(lab, lane, mode, checkout):
    return {'TENANT_LAB_MANIFEST': lab['labs'][lane][mode]['path'],
            'TENANT_LANE_AUTOLOAD': lab['hosts'][lane]['root']+'/vendor/autoload.php',
            'COREX_CONSUMER_CHECKOUT': str(checkout), 'P54_SOURCE': '1' if mode == 'source' else '0',
            'P54_BOOTSTRAP': str(ROOT/'tests/consumers/postgres/bootstrap.php'),
            'TENANT_LAB_SUPPORT': str(ROOT/'tests/laravel/Support'), 'P54_PHP': LANES[lane],
            'P54_OWNING_FIXTURES': str(ROOT/'tests/postgres/consumer-fixtures.php'), 'COREX_PROBE_HOST_ONLY': '0'}


CASES = [
    ('api', 'tests/consumers/postgres/api.php'),
    ('workspace-frame', '@corex/packages/tenant/tests/WorkspaceFrameProbe.php'),
    ('session-sharing', 'tests/consumers/postgres/session-sharing.php'),
    ('workspace-http', 'tests/consumers/postgres/workspace-http.php'),
    ('binding', 'tests/consumers/postgres/binding.php'),
    ('ownership', 'tests/consumers/postgres/ownership.php'),
    ('schema', 'tests/consumers/postgres/schema-maintenance.php'),
    ('mapping', 'tests/consumers/postgres/lifecycle.php'),
    ('product', 'tests/consumers/postgres/product-lifecycle.php'),
    ('cohort', 'tests/consumers/postgres/cohort.py'),
    ('strict', '@corex/packages/tenant/tests/StrictReferencesProbe.php'),
    ('runtime', 'tests/consumers/corex/PhysicalOwnerProbe.php'),
    ('http', 'tests/consumers/corex/HttpProbe.php'),
    ('clone', 'tests/consumers/corex/CloneProbe.php'),
    ('recovery', 'tests/consumers/postgres/recovery.php'),
    ('physical', 'tests/consumers/postgres/reused-physical.py'),
    ('uncertain', 'tests/consumers/postgres/uncertain.php'),
]


def consumer(args, checkout, source, lab):
    generation = Path(lab['generation'])
    resume = lab.get('inspectedResume', {})
    commands = []
    observations = {}
    for lane in args.lanes.split(','):
        observations[lane] = {}
        for mode in ['source', 'installed']:
            folder = generation/'labs'/lane/mode
            env = environment(lab, lane, mode, checkout)
            fixture = folder/'fixture.log'
            if not fixture.exists():
                commands.append(command(['python3', '-B', str(ROOT/'tests/consumers/postgres/preflight.py')], ROOT, folder/'execution-preflight.log', env))
                commands.append(command([LANES[lane], str(ROOT/'tests/consumers/postgres/setup-fixtures.php')], checkout, fixture, env))
            elif not folder.joinpath('fixture.command.json').exists() or json.loads(folder.joinpath('fixture.command.json').read_text())['exit'] != 0:
                raise RuntimeError('Partial fixture setup: inspect existing state; do not repeat DDL.')
            else:
                fixture_record = json.loads(folder.joinpath('fixture.command.json').read_text())
                assert fixture_record['sha256'] == sha(fixture)
                commands.append(fixture_record)
            fixtures = {}
            for label, entry in CASES:
                path = checkout/entry.removeprefix('@corex/') if entry.startswith('@corex/') else ROOT/entry
                argv = ['python3', '-B', str(path)] if path.suffix == '.py' else [LANES[lane], str(path)]
                argv = resume.get('caseOverrides', {}).get(lane+'/'+mode+'/'+label, argv)
                log = folder/(resume.get('logNames', {}).get(lane+'/'+mode+'/'+label, label+'.log'))
                if log.exists():
                    expected = resume.get('acceptedCommands', {}).get(str(log))
                    receipt = log.with_suffix('.command.json')
                    assert expected and sha(receipt) == expected, 'Uninspected existing result: '+str(log)
                    record = json.loads(receipt.read_text())
                    assert record['exit'] == 0 and record['sha256'] == sha(log)
                    assert record['argv'] == argv and record['cwd'] == str(checkout)
                else:
                    record = command(argv, checkout, log, env)
                commands.append(record)
                body = log.read_text()
                assert 'SKIP' not in body and 'PHP Warning:' not in body, label
                if label != 'physical': assert 'PHP Fatal error:' not in body, label
                assertions = 0
                for line in body.splitlines():
                    if line.startswith('PASS '): assertions += 1
                    if not line.startswith('{'): continue
                    try: value = json.loads(line)
                    except json.JSONDecodeError: continue
                    assert value.get('skipped', 0) == 0
                    for name, values in value.get('fixtures', {}).items():
                        fixtures.setdefault(name, []).extend([{'assertion': x, 'raw': str(log.relative_to(PLAN)), 'sha256': record['sha256'], 'case': label} for x in values])
                if label in ['binding', 'ownership', 'mapping', 'product', 'cohort', 'strict', 'runtime', 'http', 'clone', 'recovery', 'uncertain', 'workspace-frame', 'workspace-http', 'session-sharing']:
                    assert assertions > 0, ('zero discovery', lane, mode, label)
                if label == 'physical':
                    assert '"cases": 9' in body and 'foreign ownership despite valid checksum' in body
                    for name in ['LIFE-03', 'LIFE-05', 'LIFE-06', 'RECOVERY-RETAIN-01']:
                        fixtures.setdefault(name, []).append({'assertion': 'Exact reused P4 observed barriers, late backend exclusion, real pg_dump/DROP death, original reconcile, archive ownership/newer-export/history checks passed', 'raw': str(log.relative_to(PLAN)), 'sha256': record['sha256'], 'case': label})
                save(folder/'progress.json', {'sourceDigest': digest(source), 'lane': lane, 'mode': mode, 'commands': [x for x in commands if str(folder) in x['raw']], 'lastCase': label})
            required = {x['fixtureId'] for x in json.loads((PLAN/'artifacts/P5-detail/P5.4-portions.json').read_text())['portions']}
            assert required <= set(fixtures), ('missing fixture observations', required-set(fixtures))
            observations[lane][mode] = fixtures
            save(folder/'result.json', {'status': 'passed', 'sourceDigest': digest(source), 'fixtures': fixtures, 'skipped': 0})
    result = {'stage': 'consumer', 'status': 'passed', 'sourceDigest': digest(source), 'sourceHashes': source, 'lanes': args.lanes.split(','),
              'observations': observations, 'commands': commands, 'skipped': 0}
    save(generation/'consumer.json', result)
    save(args.output, result)
    return result


def prior_consumer(source, lab):
    result = json.loads((Path(lab['generation'])/'consumer.json').read_text())
    assert result['status'] == 'passed' and result['sourceDigest'] == digest(source)
    for row in result['commands']:
        assert row['exit'] == 0 and sha(row['raw']) == row['sha256']
    return result


def trace(checkout, source, result):
    pattern = re.compile(r'CorexBindingOwner|ClaimAccountSignup|DatabaseTenantProvisioner|DatabaseTenantLifecycle|PendingPool|TemplateClonePostgreSQLManager|AccountTemplateSnapshot|root_clusters|root_templates|root_account_exports|CREATE DATABASE|DROP DATABASE|resource_incarnation|pool_cleanup')
    rows = []
    for package in ['core', 'tenancy', 'tenant', 'custom-fields', 'operations', 'tasks', 'tasks-workflow', 'transfer', 'webhooks', 'workflow']:
        for path in (checkout/'packages'/package/'src').rglob('*.php'):
            body = path.read_text()
            hits = [i for i, line in enumerate(body.splitlines(), 1) if pattern.search(line)]
            if re.search(r'(?:use|new)\s+Tenant\\Postgres\\Internal\\', body):
                raise AssertionError('Consumer private PG import: '+str(path))
            if re.search(r"(?:table|statement|exec)\([^\n]*(?:tenant_pg_|CREATE DATABASE|DROP DATABASE)", body):
                raise AssertionError('Active consumer physical writer/catalog lookup: '+str(path))
            if not hits: continue
            inactive = 'throw new \\Tenant\\Exceptions\\UnsupportedProfile' in body
            if not inactive and re.search(r"(?:protected \$table\s*=|->table\()[^\n]*(?:root_clusters|root_templates|root_account_exports)", body):
                raise AssertionError('Active old physical table: '+str(path))
            rows.append({'path': str(path.relative_to(checkout)), 'sha256': sha(path), 'lines': hits,
                         'disposition': 'unsupported-before-IO' if inactive else 'retained host documentation/types or public adapter; physical methods removed',
                         'test': 'ownership/product/clone plus actual provider and current caller scan'})
    test_seeds = json.loads((PLAN/'artifacts/P5.4-detail/source-inspection.json').read_text())['testCases']
    tests = []
    for path, meta in test_seeds.items():
        for case in meta['cases']:
            tests.append({'source': path, 'sourceSha256': meta['sha256'], 'case': case, 'disposition': 'adapt physical owner/shape; retain domain behavior',
                          'tests': [x[1] for x in CASES], 'evidence': 'consumer.json: original45 portions, real source/installed IO, recorded assertions'})
    value = {'item': 'P5.4', 'status': 'passed', 'sourceDigest': digest(source), 'source': rows, 'tests': tests,
             'newGaps': ['Public binding facade and schema path', 'Technical ready publication with original claim receipt', 'Public access to already composed recovery operator', 'Host original request/mapping repair'],
             'reused': ['P4 exact verifier/pool/journal/fence/recovery/archive algorithms', 'P5.1 real runtime, strict refs, queue/Outbox and HTTP sessions'],
             'rule': 'Whitebox fixture setup is confined to owning Tenant tests/postgres; all CoreX production source uses public PG ports.'}
    save(PLAN/'artifacts/reuse/P5.4.json' if ART == PLAN/'artifacts/P5.4' else ART/'reuse.json', value)
    save(ART/'caller-dispositions.json', value)
    return value


def ledgers(source, result, lab):
    portions = json.loads((PLAN/'artifacts/P5-detail/P5.4-portions.json').read_text())['portions']
    archives = json.loads((Path(lab['generation'])/'archives.json').read_text())
    for lane in result['lanes']:
        rows = []
        for portion in portions:
            observations = []
            for mode in ['source', 'installed']:
                observations.extend([{**x, 'mode': mode} for x in result['observations'][lane][mode][portion['fixtureId']]])
            observation = {'portionId': portion['id'], 'expected': portion['expectedObservations'], 'status': 'passed', 'observations': observations,
                           'sourceDigest': digest(source), 'proofKind': portion['proofKind'], 'lane': lane}
            path = Path(lab['generation'])/'observations'/lane/(portion['id']+'.json')
            save(path, observation)
            rows.append({'portionId': portion['id'], 'fixtureId': portion['fixtureId'], 'producerItem': 'P5.4', 'profile': portion['profile'], 'lane': {'php84-l13': 'php84-pg16', 'php85-l13': 'php85-pg16'}[lane],
                         'proofKind': portion['proofKind'], 'applicability': portion['applicability'], 'check': {'procedure': 'Actual CoreX public owner source/fresh no-dev IO; exact assertions and P4 physical regressions in raw observations', 'result': 0},
                         'expected': portion['expectedObservations'], 'observed': '\n'.join(x['mode']+': '+x['assertion'] for x in observations), 'status': 'passed',
                         'rawEvidence': '@plan/'+str(path.relative_to(PLAN)), 'artifactHashes': {k:v['sha256'] for k,v in archives.items()}})
        ledger = {'contract': 'tenant.fixture-results/1', 'corpusHash': sha(ROOT/'contracts/fixtures/index.json'), 'indexSchemaHash': sha(ROOT/'contracts/fixtures/index.schema.json'),
                  'resultSchemaHash': sha(ROOT/'contracts/fixtures/results.schema.json'), 'candidate': {'checkout': str(ROOT), 'dirtyContentHash': digest(source), 'artifactHashes': {k:v['sha256'] for k,v in archives.items()}}, 'results': rows}
        save(Path(lab['generation'])/'ledgers'/lane/'acceptance.json', ledger)
        if lane == result['lanes'][0]: save(PLAN/'artifacts/acceptance/P5.4.json' if ART == PLAN/'artifacts/P5.4' else ART/'acceptance.json', ledger)


def package_checks(args, checkout, source, lab):
    generation = Path(lab['generation'])
    archives = json.loads((generation/'archives.json').read_text())
    commands = []
    for lane, host in lab['hosts'].items():
        root = Path(host['root'])
        assert sha(root/'composer.lock') == host['lockSha256']
        for label, installed in host['installed'].items():
            assert sha(archives[label]['path']) == archives[label]['sha256']
            for path, expected in archives[label]['files'].items(): assert sha(Path(installed['path'])/path) == expected
        commands.append(command([LANES[lane], '/usr/local/bin/composer', 'validate', '--no-check-publish'], root, generation/'package'/(lane+'-composer.log')))
    for lane in ['php84-l13', 'php85-l13']:
        env = environment(lab, lane, 'source', checkout)
        paths = [str(p) for directory in [ROOT/'packages/postgres/src', ROOT/'packages/laravel/src', checkout/'packages/tenant/src'] for p in sorted(directory.rglob('*.php'))]
        script = generation/'package'/(lane+'-lint.py')
        script.write_text('import subprocess\npaths='+repr(paths)+'\nfor p in paths:\n subprocess.run('+repr([LANES[lane], '-l'])+'+[p],check=True,stdout=subprocess.DEVNULL)\nprint("Linted",len(paths),"files")\n')
        commands.append(command(['python3', '-B', str(script)], ROOT, generation/'package'/(lane+'-lint.log')))
        # New public seams and the actual CoreX bridge. Preserve defensive legacy PG checks.
        new_paths = [ROOT/'packages/postgres/src/Contracts/Provisioning/ResourceBindings.php', ROOT/'packages/postgres/src/Data/Binding/ResolvedBinding.php',
                     ROOT/'packages/postgres/src/Internal/Binding/CatalogResourceBindings.php', ROOT/'packages/postgres/src/PostgresFactory.php',
                     ROOT/'packages/postgres/src/PostgresSchema.php', ROOT/'packages/laravel/src/Internal/Facilities/SessionFacility.php',
                     ROOT/'packages/laravel/src/Internal/Facilities/SessionGeneration.php', checkout/'packages/tenant/src']
        commands.append(command([LANES[lane], str(ROOT/'vendor/bin/phpstan'), 'analyse', '--no-progress', '--level=5', '--autoload-file='+str(ROOT/'tests/consumers/postgres/bootstrap.php'), *map(str,new_paths)], ROOT, generation/'package'/(lane+'-phpstan.log'), env))
    for mode in ['source', 'installed']:
        env = environment(lab, 'php84-l13', mode, checkout)
        env['TENANT_LANE_AUTOLOAD'] = lab['hosts']['php84-l12']['root']+'/vendor/autoload.php'
        commands.append(command([LANES['php84-l12'], str(ROOT/'tests/consumers/postgres/binding.php')], ROOT, generation/'package'/('php84-l12-'+mode+'-facade.log'), env))
        commands.append(command([LANES['php84-l12'], str(ROOT/'tests/consumers/postgres/session-facility.php')], ROOT, generation/'package'/('php84-l12-'+mode+'-session.log'), env))
    rollback = ART/'full-rollback/report.json'
    if (ART/'full-rollback/final-report.json').exists():
        rollback = ART/'full-rollback/final-report.json'
    assert rollback.exists() and json.loads(rollback.read_text())['status'] == 'passed', 'Complete cohort reverse/forward remains required.'
    if ART != PLAN/'artifacts/P5.4':
        assert json.loads(rollback.read_text())['candidateSourceDigest'] == digest(source), 'Rollback must cover the exact current correction cohort.'
    report = {'stage': 'package', 'status': 'passed', 'sourceDigest': digest(source), 'commands': commands, 'rollbackSha256': sha(rollback), 'hosts': lab['hosts']}
    save(args.output, report)
    return report


def main():
    global ART
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--stage', required=True, choices=['prepare', 'consumer', 'trace', 'boundary', 'package', 'ledger'])
    parser.add_argument('--lanes', required=True)
    parser.add_argument('--checkout-manifest', required=True, type=Path)
    parser.add_argument('--run-manifest', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--artifact-dir', type=Path, help='Separate correction artifacts; original reviewed evidence remains immutable.')
    args = parser.parse_args()
    if args.artifact_dir:
        assert args.artifact_dir.resolve() == (PLAN/'artifacts/P5.4/correction-round1').resolve(), 'Only the owning bounded correction namespace.'
        ART = args.artifact_dir.absolute()
    assert args.lanes == 'php84-l13,php85-l13'
    manifest = json.loads(args.checkout_manifest.read_text())
    assert os.environ['CODEX_SESSION_ID'] == manifest['session'], 'Continue only the registered owner session.'
    checkout = Path(manifest['checkout'])
    assert checkout == Path('/tmp/tenant-p54-' + manifest['session'][:8]) / 'corex'
    source = sources(checkout)
    if args.stage == 'prepare':
        prepare(args, checkout, source)
    else:
        lab = json.loads(args.run_manifest.read_text())
        assert lab['sourceDigest'] == digest(source), 'Source drift after prepare; inspect exact changed inputs.'
        if args.stage == 'consumer':
            consumer(args, checkout, source, lab)
        else:
            result = prior_consumer(source, lab)
            if args.stage == 'trace':
                report = trace(checkout, source, result)
            elif args.stage == 'ledger':
                ledgers(source, result, lab)
                report = {'stage': 'ledger', 'status': 'passed', 'portions': 45, 'lanes': result['lanes']}
            elif args.stage == 'boundary':
                report = {'stage': 'boundary', 'status': 'passed', 'sourceDigest': digest(source), 'commands': [x for x in result['commands'] if Path(x['raw']).stem in ['binding','strict','runtime','http','clone','recovery','cohort','physical','workspace-frame','workspace-http','session-sharing']]}
            elif args.stage == 'package':
                report = package_checks(args, checkout, source, lab)
            else:
                raise AssertionError(args.stage)
            save(args.output, report)


if __name__ == '__main__':
    main()
