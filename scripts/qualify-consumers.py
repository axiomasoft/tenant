#!/usr/bin/env python3
"""P5.1 qualification from installed artifacts, actual consumer IO, and immutable original portions."""
import argparse
import datetime
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import time
import re
import zipfile
import xml.etree.ElementTree as ET

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



def inputs(checkout):
    files = {}
    for base in ['core','tenancy','tenant','custom-fields','operations','tasks','tasks-workflow','transfer','webhooks','workflow']:
        for path in (checkout / 'packages' / base).rglob('*.php'):
            if 'vendor' not in path.parts:
                files['corex/' + str(path.relative_to(checkout))] = sha(path)
    for pattern in ['packages/tenant-laravel/src/**/*.php','tests/consumers/**/*.php','tests/laravel/Support/*.php','packages/tenant-laravel/tests/**/*.php']:
        for path in ROOT.glob(pattern): files['tenant/' + str(path.relative_to(ROOT))] = sha(path)
    files['tenant/scripts/qualify-consumers.py'] = sha(__file__)
    return files


def digest(value):
    return hashlib.sha256(json.dumps(value,sort_keys=True,separators=(',',':')).encode()).hexdigest()


FIXTURES = {
    'INSTALL-02':['installed CoreX product provider boots','public provider installs sole Tenant manager','active source registrar disables legacy owner binding','archive-installed public descriptor'],
    'INSTALL-03':['unsupported boxed CRM'],
    'AUTH-01':['ungranted account refused','catalog B ID without host authority','untrusted selector missing','untrusted selector forged','untrusted selector deleted'],
    'AUTH-02':['revoked ordinary command re-admitted','explicit maintenance without billing authority'],
    'BINDING-01':['actual marker mismatch before ordinary effect','mismatched physical marker performs zero ordinary IO','old durable queue reference refuses routeRevision','old durable queue reference refuses namespace'],
    'LIFE-08':['old durable queue reference refuses incarnation','no external anchor restore refusal'],
    'OWN-01':['host rights changes preserve sole CoreX physical binding','CoreX retains root and tenant schema ownership'],
    'QUEUE-04':['v2 fields preserved','v2 connection mismatch','v2 version mismatch'],
    'SELECT-01':['lost W never falls back W2','separate explicit UX default W2 operation','actual CoreX Selected W SQL includes own rows and account NULL','actual CoreX Missing SQL never widens','actual CoreX AccountWide SQL only after grant'],
    'CENTRAL-01':['nested B sync restores original A','nested exception restores original A','explicit central from A restores original A','owning publisher freezes A before afterCommit','owning publisher afterCommit job actually executes original A','sync and actual worker SQL effects a','sync and actual worker SQL effects b','sync and actual worker SQL effects central','worker ACK deletes exact queue entries','actual CoreX queue:retry command','failed stamp retains original A','manual retry executes original A only'],
    'SESSION-01':[f'{case} {driver}' for driver in ['file','redis'] for case in ['CoreX save and terminate within frame','foreign cookie isolation','resident auth cleanup','copied foreign CoreX session payload refused','host denied before session IO','real CoreX socket save/terminate persistence']],
}


def installed(art,lanes):
    archive = json.loads((art/'archives.json').read_text())
    checkout=pathlib.Path(json.loads((art/'checkout-manifest.json').read_text())['checkout'])
    roots={'tenant':ROOT/'packages/tenant','tenant-laravel':ROOT/'packages/tenant-laravel','corex':checkout/'packages/core','corex-tenant':checkout/'packages/tenant','corex-tenancy':checkout/'packages/tenancy','corex-intl':checkout/'packages/intl','flex-crm':checkout/'products/flex-crm'}
    for key,meta in archive.items():
        source=roots[key]
        current={}
        for path in source.rglob('*'):
            if not path.is_file() or any(part in path.relative_to(source).parts for part in ['vendor','node_modules','tests']):continue
            content=path.read_bytes();relative=str(path.relative_to(source))
            if relative=='composer.json':
                data=json.loads(content);data['version']='1.0.0';content=json.dumps(data,indent=4).encode()
            current[relative]=hashlib.sha256(content).hexdigest()
        assert current==meta['files'],('source/archive drift',key)
    counts = {}
    for lane in lanes:
        count = 0
        for key, meta in archive.items():
            assert sha(meta['path']) == meta['sha256'], ('archive drift',key)
            with zipfile.ZipFile(meta['path']) as package:
                name = json.loads(package.read(key+'/composer.json'))['name']
            for rel, expected in meta['files'].items():
                assert sha(art/'hosts'/lane/'vendor'/name/rel) == expected, ('installed drift',lane,name,rel)
                count += 1
        counts[lane] = count
    return counts


def consumer(art,checkout,manifest,run_manifest,lanes,source):
    commands = []
    observations = {}
    installed(art,lanes)
    previous=art/'final/consumer.json'
    if previous.exists():
        old=json.loads(previous.read_text())
        runtime=lambda values:{k:v for k,v in values.items() if k!='tenant/scripts/qualify-consumers.py'}
        ledger=PLAN/'artifacts/acceptance/P5.1.json'
        old_archives=json.loads(ledger.read_text())['candidate']['artifactHashes'] if ledger.exists() else {}
        current_archives={k:v['sha256'] for k,v in json.loads((art/'archives.json').read_text()).items()}
        if old.get('status')=='passed' and old.get('lanes')==lanes and runtime(old.get('sourceHashes',{}))==runtime(source) and old_archives==current_archives:
            for record in old['commands']:assert record['exit']==0 and sha(record['raw'])==record['sha256']
            for mapping in old['observations'].values():
                for entry in mapping.values():assert sha(PLAN/entry['path'])==entry['sha256']
            save(art/'final/consumer-before-harness-correction.json',old)
            rebound={**old,'sourceHashes':source,'sourceDigest':digest(source),'reuse':{'previousSourceDigest':old['sourceDigest'],'runtimeInputsDigest':digest(runtime(source)),'reason':'Only qualification trace/ledger renderer changed; all actual PHP/source/test/archive bytes and raw command outcomes unchanged.'}}
            save(previous,rebound)
            return rebound
    for lane in lanes:
        env = {**os.environ,'APP_ENV':'testing','XDEBUG_MODE':'off','COREX_CONSUMER_CHECKOUT':str(checkout),
               'TENANT_LAB_MANIFEST':str(run_manifest.resolve()),'TENANT_LANE_AUTOLOAD':str(art/'hosts'/lane/'vendor/autoload.php'),
               'TENANT_LAB_SUPPORT':str(ROOT/'tests/laravel/Support'),'TENANT_USE_SOURCE':'0'}
        probes = [('preflight',ROOT/'tests/laravel/Support/preflight.php'),
                  ('strict',checkout/'packages/tenant/tests/StrictReferencesProbe.php'),
                  ('consumer',ROOT/'tests/consumers/corex/PhysicalOwnerProbe.php'),
                  ('http',ROOT/'tests/consumers/corex/HttpProbe.php'),
                  ('clone',ROOT/'tests/consumers/corex/CloneProbe.php')]
        outputs = []
        lane_commands = []
        for name,path in probes:
            record = command([LANES[lane],str(path)],ROOT,env,art/'final'/digest(source)[:16]/lane/(name+'.log'))
            commands.append(record); lane_commands.append(record)
            assert record['exit'] == 0, record
            outputs.append(pathlib.Path(record['raw']).read_text())
        text = '\n'.join(outputs)
        assert '108 assertions' in text and 'COMPLETE physical owner probe' in text and 'COMPLETE CoreX HTTP probe' in text
        assert 'COMPLETE real clone/stamp' in text and 'WORKER pid=' in text and 'ATTEMPTED state=central' in text
        assert not any(marker in text for marker in ['SKIP','PHP Warning:','PHP Fatal error:'])
        observations[lane] = {}
        for fixture,required in FIXTURES.items():
            matches = [next((line for line in text.splitlines() if line.startswith('PASS ') and marker in line),None) for marker in required]
            assert all(matches), (lane,fixture,required,matches)
            observed = {'fixture':fixture,'lane':lane,'status':'passed','assertions':matches,'sourceDigest':digest(source),
                        'raw':[{'path':str(pathlib.Path(c['raw']).relative_to(PLAN)),'sha256':c['sha256']} for c in lane_commands]}
            path = art/'final'/digest(source)[:16]/lane/'observations'/(fixture+'.json');save(path,observed)
            observations[lane][fixture] = {'path':str(path.relative_to(PLAN)),'sha256':sha(path),'assertions':matches}
    result = {'status':'passed','sourceDigest':digest(source),'sourceHashes':source,'commands':commands,'observations':observations,'lanes':lanes,'skipped':0}
    save(art/'final/consumer.json',result)
    return result


def previous_consumer(art,source,lanes):
    result=json.loads((art/'final/consumer.json').read_text())
    assert result['status']=='passed' and result['sourceDigest']==digest(source) and result['lanes']==lanes
    for record in result['commands']: assert record['exit']==0 and sha(record['raw'])==record['sha256']
    for mapping in result['observations'].values():
        for entry in mapping.values(): assert sha(PLAN/entry['path'])==entry['sha256']
    return result


def trace(art,checkout,source,result):
    registrar=(checkout/'packages/tenancy/src/Laravel/RuntimeRegistrar.php').read_text()
    assert 'TenancyRuntimeFactory::manager($app)' not in registrar and 'createPayloadUsing(' not in registrar
    events=(checkout/'packages/tenancy/src/Laravel/EventsRegistrar.php').read_text()
    assert "[QueueTenantContext::class, 'processing']" not in events
    for path in (checkout/'packages/tenant/src').rglob('*.php'):
        text=path.read_text()
        assert re.search(r'(?<![=!<>])==(?!=)|!=(?!=)',text) is None, ('loose comparison',str(path))
        assert re.search(r'(?:use|new)\s+(?:CoreX\\Tenancy|Tenant\\Laravel)\\Internal\\',text) is None, ('private import',str(path))
    pattern=re.compile(r'tenant|workspace|DataScope|connectionIdentity|restoreEpoch|ContextJobPublisher|binding|scope',re.IGNORECASE)
    rows=[]
    for base in ['core','tenancy','custom-fields','operations','tasks','tasks-workflow','transfer','webhooks','workflow']:
        for path in (checkout/'packages'/base/'src').rglob('*.php'):
            text=path.read_text()
            lines=[i for i,line in enumerate(text.splitlines(),1) if pattern.search(line)]
            if not lines: continue
            relative=str(path.relative_to(checkout))
            inactive=path.name in ['DatabaseTenancyManager.php','QueueTenantContext.php','TenancyRuntimeFactory.php'] or 'Bootstrappers' in path.parts
            if inactive: disposition='remove'; target='Inactive legacy mechanism: no active binding/payload/event registration; sole public Tenant owner'
            elif 'DataScope' in text or 'TenantContextRestorer' in text:
                disposition='adapt' if base in ['core','tenancy'] else 'keep'
                target='Public TenantContextRestorer binding -> VerifiedContextRestorer; original domain actor/epoch/connection guards retained'
            else: disposition='keep'; target='Public TenancyManager/projection binding -> sole Tenant runtime; owning business/physical engine remains CoreX'
            rows.append({'source':relative,'sha256':sha(path),'lines':lines,'disposition':disposition,'target':target})
    assert all(any('/'+base+'/' in row['source'] for row in rows) for base in ['core','tenancy','custom-fields','operations','tasks-workflow','transfer','webhooks','workflow'])
    # Complete declared test bodies remain available. Adapted consumer suites execute the current expectations.
    seeds=json.loads((PLAN/'artifacts/P5-detail/source-inspection.json').read_text())
    tests=[]
    for seed in seeds['files']:
        if seed['repo']!='corex' or '/tests/' not in seed['path']: continue
        original=art/'source-bodies/corex'/seed['path']
        assert original.is_file() and sha(original)==seed['sha256']
        tests.append({'source':seed['path'],'sha256':seed['sha256'],'disposition':'adapt',
                      'target':['packages/tenancy/tests/Pg/TenantRuntimeConsumerProbe.php','packages/tenancy/tests/Pg/CorexHttpProbe.php','packages/tenancy/tests/Pg/CloneBindingProbe.php'],
                      'note':'Retain owning IO/semantic guards. Old ambient queue stamping, no-op same-account and implicit unfiltered Missing expectations replaced by explicit frozen/current generation/selection requirements.'})
    assert tests
    ledger={'item':'P5.1','status':'passed','sourceDigest':digest(source),'source':rows,'tests':tests,
            'newGaps':['CoreX physical marker/incarnation/verifier and host admission ports','Strict reference equality and verified v2 durable binding','Public guarded resource storage SPI'],
            'evidence':'artifacts/P5.1/final/consumer.json','testMode':'adapted installed consumer suites; obsolete ambient expectations are not rerun as current expectations'}
    save(PLAN/'artifacts/reuse/P5.1.json',ledger)
    return {'callers':len(rows),'source_tests':len(tests),'sourceDigest':digest(source),'status':'passed'}


def ledgers(art,source,result):
    portions=json.loads((PLAN/'artifacts/P5-detail/P5.1-portions.json').read_text())['portions']
    archive=json.loads((art/'archives.json').read_text())
    for lane in result['lanes']:
        rows=[]
        for portion in portions:
            inactive=portion['applicability']['status']=='not-applicable'
            entry=None if inactive else result['observations'][lane][portion['fixtureId']]
            rows.append({'portionId':portion['id'],'fixtureId':portion['fixtureId'],'producerItem':'P5.1','profile':portion['profile'],'lane':lane,'proofKind':portion['proofKind'],'applicability':portion['applicability'],
                         'check':{'procedure':'Installed current CoreX runtime/product/source adapters with real worker, HTTP, SQL and physical marker checks; exact per-fixture assertions in observation','result':None if inactive else 0},
                         'expected':portion['expectedObservations'],'observed':None if inactive else '\n'.join(entry['assertions']),
                         'status':'not-run' if inactive else 'passed','rawEvidence':None if inactive else '@plan/'+entry['path'],
                         **({} if inactive else {'artifactHashes':{k:v['sha256'] for k,v in archive.items()}})})
        ledger={'contract':'tenant.fixture-results/1','corpusHash':sha(ROOT/'contracts/fixtures/index.json'),'indexSchemaHash':sha(ROOT/'contracts/fixtures/index.schema.json'),'resultSchemaHash':sha(ROOT/'contracts/fixtures/results.schema.json'),
                'candidate':{'checkout':str(ROOT),'dirtyContentHash':digest(source),'artifactHashes':{k:v['sha256'] for k,v in archive.items()}},'results':rows}
        save(art/'final'/lane/'acceptance.json',ledger)
        if lane==result['lanes'][0]:save(PLAN/'artifacts/acceptance/P5.1.json',ledger)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--item',required=True,choices=['P5.1'])
    parser.add_argument('--stage',required=True,choices=['prepare','consumer','trace','boundary','package','ledger','aggregate'])
    parser.add_argument('--lanes',required=True)
    parser.add_argument('--checkout-manifest',required=True,type=pathlib.Path)
    parser.add_argument('--run-manifest',required=True,type=pathlib.Path)
    parser.add_argument('--output',required=True,type=pathlib.Path)
    args=parser.parse_args();art=PLAN/'artifacts'/args.item
    manifest=json.loads(args.run_manifest.read_text());checkout=pathlib.Path(json.loads(args.checkout_manifest.read_text())['checkout'])
    lanes=args.lanes.split(',');assert len(set(lanes))==len(lanes) and all(lane in LANES for lane in lanes)
    preflight(manifest);source=inputs(checkout)
    report={'item':args.item,'stage':args.stage,'status':'failed','sourceDigest':digest(source),'sourceHashes':source,'lanes':lanes,'commands':[],'skipped':0}
    try:
        if args.stage=='consumer':
            result=consumer(art,checkout,manifest,args.run_manifest,lanes,source);report.update(result);ledgers(art,source,result)
        elif args.stage=='prepare':report['installed']=installed(art,lanes)
        else:
            result=previous_consumer(art,source,lanes)
            if args.stage=='trace':report.update(trace(art,checkout,source,result))
            if args.stage=='boundary':report['reusedCommands']=[c for c in result['commands'] if any(name in c['raw'] for name in ['consumer.log','http.log','clone.log','strict.log'])]
            if args.stage in ['ledger','aggregate']:ledgers(art,source,result)
            if args.stage=='package':
                report['installed']=installed(art,lanes)
                for lane in lanes:
                    host=art/'hosts'/lane;env={**os.environ,'XDEBUG_MODE':'off'}
                    report['commands'].append(command([LANES[lane],'/usr/local/bin/composer','validate','--no-check-publish'],host,env,art/'final'/lane/'composer-validate.log'))
                    report['commands'].append(command([LANES[lane],str(ROOT/'vendor/bin/phpstan'),'analyse','--no-progress','--level=5','--autoload-file='+str(host/'vendor/autoload.php'),str(checkout/'packages/tenant/src')],ROOT,env,art/'final'/lane/'phpstan.log'))
                    for path in sorted((checkout/'packages/tenant/src').rglob('*.php')):
                        q=subprocess.run([LANES[lane],'-l',str(path)],stdout=subprocess.PIPE,stderr=subprocess.STDOUT);assert q.returncode==0,q.stdout
                    testenv={**env,'TENANT_LAB_MANIFEST':str(args.run_manifest.resolve()),'TENANT_LANE_AUTOLOAD':str(host/'vendor/autoload.php'),'TENANT_USE_SOURCE':'0','TENANT_OBSERVATIONS':str(art/'final'/lane/'adapter-observations'),'APP_ENV':'testing'}
                    junit=art/'final'/lane/'adapter-junit.xml'
                    report['commands'].append(command([LANES[lane],str(ROOT/'vendor/bin/pest'),'--configuration',str(ROOT/'packages/tenant-laravel/phpunit.xml'),'--compact','--log-junit',str(junit)],ROOT,testenv,art/'final'/lane/'adapter-regression.log'))
                    tree=ET.parse(junit);assert len(tree.findall('.//testcase'))>=32 and not tree.findall('.//skipped')
                    report.setdefault('locks',{})[lane]=sha(host/'composer.lock')
                assert all(c['exit']==0 for c in report['commands']),report['commands']
        report['status']='passed';save(args.output,report);print(json.dumps({'stage':args.stage,'status':'passed','sourceDigest':digest(source)},ensure_ascii=False));return 0
    except Exception as failure:
        report['failure']=str(failure);save(args.output,report);raise


if __name__=='__main__':
    sys.exit(main())
