#!/usr/bin/env python3
"""Reverse/forward every source, lock, config and migration selection in isolated copies."""
import argparse
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location('p54', ROOT/'scripts/qualify-corex-postgres.py')
q = importlib.util.module_from_spec(spec); spec.loader.exec_module(q)
parser = argparse.ArgumentParser(); parser.add_argument('--run-manifest', type=Path, required=True); args = parser.parse_args()
lab = json.loads(args.run_manifest.read_text()); checkout_info = json.loads((q.ART/'checkout-manifest.json').read_text())
COREX = Path(checkout_info['checkout'])
old = ROOT/'plans/2026.10.24-№1-TENANT/artifacts/P5.1'
old_manifest=json.loads((old/'consumer-manifest.json').read_text())
reverse_tenant=old_manifest['heads']['tenant']
work = COREX.parent/'full-cohort-rollback'
if work.exists(): raise RuntimeError('Existing rollback state must be inspected; never reset it.')
work.mkdir()
out = q.ART/'full-rollback'; out.mkdir(exist_ok=True)


def git(root, *argv):
    return subprocess.check_output(['git', '-C', str(root), *argv])


def entry(path):
    mode = stat.S_IMODE(path.lstat().st_mode)
    if path.is_symlink(): return {'kind':'symlink', 'target':os.readlink(path), 'mode':mode}
    return {'kind':'file', 'sha256':q.sha(path), 'mode':mode}


def current(root, include_new):
    paths = set(git(root, 'ls-files', '-z').decode().strip('\0').split('\0'))-{''}
    new = set(git(root, 'ls-files', '-z', '--others', '--exclude-standard').decode().strip('\0').split('\0'))-{''}
    paths |= {p for p in new if include_new(p)}
    return {p:entry(root/p) for p in sorted(paths) if (root/p).is_file() or (root/p).is_symlink()}


def from_git(root, revision, destination):
    destination.mkdir(parents=True)
    data = git(root, 'archive', '--format=tar', revision)
    with tarfile.open(fileobj=io.BytesIO(data)) as archive: archive.extractall(destination, filter='data')
    return {str(p.relative_to(destination)):entry(p) for p in destination.rglob('*') if p.is_file() or p.is_symlink()}


def apply(source, target, inventory, union, foreign):
    assert not set(inventory)&set(foreign), 'Foreign/owned path collision'
    for relative in union-set(inventory):
        path=target/relative
        if path.is_symlink() or path.is_file(): path.unlink()
    for relative, info in inventory.items():
        path=target/relative; original=source/relative; path.parent.mkdir(parents=True,exist_ok=True)
        if path.is_symlink() or path.is_file(): path.unlink()
        if info['kind']=='symlink': path.symlink_to(info['target'])
        else: shutil.copy2(original,path)
    for relative, expected in inventory.items(): assert entry(target/relative)==expected, relative
    for relative, expected in foreign.items(): assert entry(target/relative)==expected, ('foreign drift',relative)


roots={'tenant':ROOT,'corex':COREX}
candidates={}; baselines={}; copies={}; baseline_roots={}; foreign={}
for label, root in roots.items():
    own = (lambda p:p.startswith(('packages/postgres/','tests/consumers/postgres/')) or p in ['scripts/qualify-corex-postgres.py','tests/postgres/consumer-fixtures.php']) if label=='tenant' else (lambda p:p.startswith('packages/'))
    candidates[label]=current(root,own)
    baseline_roots[label]=work/'baseline'/label
    base=reverse_tenant if label=='tenant' else checkout_info['baseline']
    baselines[label]=from_git(root,base,baseline_roots[label])
    target=work/label; target.mkdir(); copies[label]=target
    startup=json.loads((q.ART/'preservation-before.json').read_text())['tenant']['files'] if label=='tenant' else checkout_info['seedFingerprints']
    overlays={}
    for relative, meta in startup.items():
        if relative in candidates[label] or relative in baselines[label] or own(relative) or meta['kind']=='absent': continue
        path=root/relative
        if not path.is_file() and not path.is_symlink(): continue
        actual=entry(path)
        expected={k:v for k,v in meta.items() if k in ['kind','target','mode','sha256']}
        assert actual==expected,('original foreign drift',label,relative)
        dest=target/relative;dest.parent.mkdir(parents=True,exist_ok=True)
        if path.is_symlink():dest.symlink_to(os.readlink(path))
        else:shutil.copy2(path,dest)
        overlays[relative]=actual
    # Actual managed links remain symlinks even in disposable copies; never traverse to mutate.
    if label=='tenant':
        for name in ['plans','tasks']:
            assert (root/name).is_symlink();(target/name).symlink_to(os.readlink(root/name));overlays[name]=entry(root/name)
    extra=target/'foreign-owner-state';extra.mkdir();(extra/'bytes').write_bytes(b'foreign\x00unchanged\n');(extra/'bytes').chmod(0o640);(extra/'link').symlink_to('bytes')
    overlays['foreign-owner-state/bytes']=entry(extra/'bytes');overlays['foreign-owner-state/link']=entry(extra/'link')
    foreign[label]=overlays
    apply(root,target,candidates[label],set(candidates[label])|set(baselines[label]),overlays)

q.save(out/'before.json',{'candidates':candidates,'baselines':baselines,'foreign':foreign,'copies':{k:str(v) for k,v in copies.items()}})
# Freeze the full selected host graph, not merely the package currently under change.
host=work/'host';host.mkdir();lane='php84-l13'
forward_host=Path(lab['hosts'][lane]['root'])
old_host=old/'hosts'/lane
assert q.sha(old_host/'composer.lock')==old_manifest['lanes'][lane]['lockSha256']
old_archives=json.loads((old/'archives.json').read_text())
old_sources={'tenant':baseline_roots['tenant']/'packages/tenant','tenant-laravel':baseline_roots['tenant']/'packages/tenant-laravel',
 'corex':baseline_roots['corex']/'packages/core','corex-tenancy':baseline_roots['corex']/'packages/tenancy','corex-tenant':baseline_roots['corex']/'packages/tenant',
 'corex-intl':baseline_roots['corex']/'packages/intl','flex-crm':baseline_roots['corex']/'products/flex-crm'}
for label, meta in old_archives.items():
    assert q.sha(meta['path'])==meta['sha256']
    name=json.loads((old_sources[label]/'composer.json').read_text())['name']
    for relative, expected in meta['files'].items():
        assert q.sha(old_host/'vendor'/name/relative)==expected,(label,relative,'old installed drift')
        body=(old_sources[label]/relative).read_bytes()
        if relative=='composer.json':
            value=json.loads(body);value['version']='1.0.0';body=json.dumps(value,indent=4).encode()
        assert hashlib.sha256(body).hexdigest()==expected,(label,relative,'baseline/source/archive mismatch')
commands=[]
for label in roots:
    apply(baseline_roots[label],copies[label],baselines[label],set(candidates[label])|set(baselines[label]),foreign[label])
for name in ['composer.json','composer.lock']:shutil.copy2(old_host/name,host/name)
q.save(out/'reverse-state.json',{'status':'source-cohort-reversed','baselines':baselines,'lockSha256':q.sha(host/'composer.lock'),'optionalPgInstalled':False,'foreign':foreign})
manifest=out/'lab-manifest.json'
commands.append(q.command(['python3','-B',str(ROOT/'tests/consumers/postgres/setup-lab.py'),'--output',str(manifest)],ROOT,out/'reverse-infrastructure.log'))
env={'TENANT_LAB_MANIFEST':str(manifest),'TENANT_LANE_AUTOLOAD':str(old_host/'vendor/autoload.php'),'COREX_CONSUMER_CHECKOUT':str(copies['corex']),
     'TENANT_LAB_SUPPORT':str(copies['tenant']/'tests/laravel/Support'),'P54_BOOTSTRAP':'','P54_SOURCE':'0','COREX_PROBE_HOST_ONLY':'0'}
commands.append(q.command(['python3','-B',str(ROOT/'tests/consumers/postgres/preflight.py')],ROOT,out/'reverse-preflight.log',env))
commands.append(q.command(['/usr/bin/php8.4',str(ROOT/'tests/consumers/postgres/rollback-setup.php')],copies['corex'],out/'reverse-schema.log',env))
commands.append(q.command(['/usr/bin/php8.4',str(copies['corex']/'packages/tenancy/tests/Pg/TenantRuntimeConsumerProbe.php')],copies['corex'],out/'reverse-runtime.log',env))
commands.append(q.command(['/usr/bin/php8.4',str(copies['corex']/'packages/tenancy/tests/Pg/CorexHttpProbe.php')],copies['corex'],out/'reverse-http.log',env))
for label,root in roots.items():apply(root,copies[label],candidates[label],set(candidates[label])|set(baselines[label]),foreign[label])
for name in ['composer.json','composer.lock']:shutil.copy2(forward_host/name,host/name)
env=q.environment(lab,lane,'source',copies['corex']);env['P54_TENANT_CHECKOUT']=str(copies['tenant']);env['P54_BOOTSTRAP']=str(copies['tenant']/'tests/consumers/postgres/bootstrap.php');env['TENANT_LAB_SUPPORT']=str(copies['tenant']/'tests/laravel/Support')
commands.append(q.command(['python3','-B',str(ROOT/'tests/consumers/postgres/preflight.py')],ROOT,out/'forward-preflight.log',env))
commands.append(q.command(['/usr/bin/php8.4',str(copies['tenant']/'tests/consumers/postgres/cohort-smoke.php')],copies['corex'],out/'forward-runtime.log',env))
for label in roots:
    for relative,meta in candidates[label].items():assert entry(copies[label]/relative)==meta
    for relative,meta in foreign[label].items():assert entry(copies[label]/relative)==meta
assert q.sha(host/'composer.lock')==lab['hosts'][lane]['lockSha256']
q.save(out/'report.json',{'status':'passed','scope':'Complete two-repo source/lock/config/migration selections reverse then forward; actual old CoreX owner IO + actual restored PG owner IO',
 'copies':{k:str(v) for k,v in copies.items()},'candidateSourceDigest':lab['sourceDigest'],'baselineHeads':{'tenant':reverse_tenant,'corex':checkout_info['baseline']},
 'preImplementationTenant':'18290ba31462ffdef59ff476d35dab1bd22656d8',
 'foreignCounts':{k:len(v) for k,v in foreign.items()},'foreignPreserved':True,'baselineEquality':True,'forwardEquality':True,'lockSha256':q.sha(host/'composer.lock'),
 'commands':commands,'sourceFiles':{k:len(v) for k,v in candidates.items()},'retainedHistory':'Previous P5.1 source generation19143b7 is provenance; local reconstructed baseline used explicitly; no deployed-data rollback.'})
print(json.dumps({'status':'passed','commands':len(commands),'copies':str(work)}))
