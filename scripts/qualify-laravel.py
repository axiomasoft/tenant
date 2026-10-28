#!/usr/bin/env python3
"""P3 qualification: isolated real IO, per-lane original portions, reproducible packages.

No success is inferred from a fixture name, file presence alone, or another lane.
"""
import argparse, datetime, hashlib, json, os, pathlib, platform, subprocess, sys, time, zipfile, stat

ROOT=pathlib.Path(__file__).resolve().parents[1]
PLAN=ROOT/'plans/2026.10.24-№1-TENANT'
ITEM='P3.1'
ART=PLAN/'artifacts'/ITEM
LANES={'php84-l12':'/usr/bin/php8.4','php84-l13':'/usr/bin/php8.4','php85-l13':'/usr/bin/php8.5'}
COMPOSER='/usr/local/bin/composer'

def sha(path): return hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest()
def now(): return datetime.datetime.now(datetime.timezone.utc).isoformat()
def save(path,value):
    path=pathlib.Path(path);path.parent.mkdir(parents=True,exist_ok=True);path.write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n')
def source_hashes():
    files=sorted([*ROOT.glob('packages/tenant-laravel/src/**/*.php'),*ROOT.glob('packages/tenant-laravel/config/*.php'),*ROOT.glob('packages/tenant-laravel/tests/**/*.php'),*ROOT.glob('tests/laravel/Support/*.php'),ROOT/'scripts/qualify-laravel.py',ROOT/'packages/tenant-laravel/composer.json',ROOT/'packages/tenant-laravel/phpunit.xml',ROOT/'tests/laravel/lab/setup.py',ROOT/'tests/laravel/lab/compose.yaml',ROOT/'tests/laravel/lab/telemetry-resolver.c'])
    return {str(p.relative_to(ROOT)):sha(p) for p in files}
def digest(value): return hashlib.sha256(json.dumps(value,sort_keys=True,separators=(',',':')).encode()).hexdigest()
def command(argv,log,env=None,cwd=ROOT,expect=0):
    start=now();t=time.monotonic();result=subprocess.run([str(x) for x in argv],cwd=cwd,env={**os.environ,**(env or {})},stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
    pathlib.Path(log).parent.mkdir(parents=True,exist_ok=True);pathlib.Path(log).write_bytes(result.stdout)
    row={'argv':[str(x) for x in argv],'cwd':str(cwd),'started_at':start,'finished_at':now(),'duration':round(time.monotonic()-t,4),'exit':result.returncode,'status':'passed' if result.returncode==expect else 'failed','log':str(pathlib.Path(log).relative_to(PLAN)),'sha256':sha(log)}
    if result.returncode!=expect: raise RuntimeError(json.dumps(row)+'\n'+result.stdout.decode(errors='replace')[-9000:])
    return row

def preflight(manifest,lane,label):
    records=[]
    for service,cid in manifest['containers'].items():
        ins=json.loads(subprocess.check_output(['docker','inspect',cid]))[0]
        assert ins['State']['Running'] and ins['Config']['Labels']['com.docker.compose.project']==manifest['project']
        assert ins['Image']==manifest['images']['postgres:16-alpine' if service=='pg' else 'redis:7-alpine']['id']
        assert list(ins['NetworkSettings']['Networks'].values())[0]['NetworkID']==manifest['networkIds'][service]
        assert [m['Name'] for m in ins['Mounts'] if m['Type']=='volume']==manifest['volumeIds'][service]
    system=subprocess.check_output(['docker','exec',manifest['containers']['pg'],'psql','-X','-At','-U',manifest['admin'],'-d',manifest['databases']['central'],'-c','select system_identifier from pg_control_system()'],text=True).strip()
    assert system==manifest['systemIdentifier']
    env=environment(lane,manifest)
    records.append(command([LANES[lane],ROOT/'tests/laravel/Support/preflight.php'],ART/lane/(label+'-preflight.log'),env))
    return records

def environment(lane,manifest,install=None):
    host=pathlib.Path(install) if install else ROOT/'tests/laravel/hosts'/lane
    return {'TENANT_LAB_MANIFEST':str(ART/'lab-manifest.json'),'TENANT_LANE_AUTOLOAD':str(host/'vendor/autoload.php'),'TENANT_USE_SOURCE':'0','TENANT_OBSERVATIONS':str(ART/lane/'observations'),'APP_ENV':'testing','XDEBUG_MODE':'off'}

def archives():
    inputs={str(p.relative_to(ROOT)):sha(p) for package in ['tenant','tenant-laravel'] for p in (ROOT/'packages'/package).rglob('*') if p.is_file() and ('src' in p.parts or 'config' in p.parts or p.name=='composer.json')}
    folder=ART/'archive'/digest(inputs)[:20];folder.mkdir(parents=True,exist_ok=True)
    result={}
    for package in ['tenant','tenant-laravel']:
        source=ROOT/'packages'/package;out=folder/(package+'.zip')
        with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
            for path in sorted(source.rglob('*')):
                if not path.is_file() or not ('src' in path.parts or 'config' in path.parts or path==source/'composer.json'):continue
                content=path.read_bytes()
                if path.name=='composer.json':
                    c=json.loads(content);c['version']='1.0.0';content=(json.dumps(c,indent=4)+'\n').encode()
                info=zipfile.ZipInfo(package+'/'+str(path.relative_to(source)),date_time=(2026,10,28,0,0,0));info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED;z.writestr(info,content)
        result[package]={'path':str(out),'sha256':sha(out),'sourceFiles':{str(p.relative_to(source)):sha(p) for p in source.rglob('*') if p.is_file() and ('src' in p.parts or 'config' in p.parts)}}
    save(ART/'archives.json',result);return folder,result

def prepare(lanes,manifest):
    folder,archive=archives();records=[]
    for lane in lanes:
        host=ROOT/'tests/laravel/hosts'/lane
        data=json.loads((host/'composer.json').read_text());data['require']['axiomasoft/tenant-laravel']='1.0.0';data['repositories']=[{'type':'artifact','url':str(folder)}];save(host/'composer.json',data)
        (host/'tests').mkdir(exist_ok=True)
        records.append(command([LANES[lane],COMPOSER,'update','axiomasoft/tenant-laravel','axiomasoft/tenant','--with-dependencies','--prefer-dist','--no-interaction'],ART/lane/'composer-update.log',cwd=host))
        if (host/'vendor').exists(): (host/'vendor').rename(pathlib.Path(manifest['root'])/('previous-vendor-'+lane+'-'+str(time.time_ns())))
        records.append(command([LANES[lane],COMPOSER,'install','--prefer-dist','--no-interaction'],ART/lane/'composer-install.log',cwd=host))
    save(ART/'prepare.json',{'archives':archive,'commands':records,'sources':source_hashes()})

def tests(stage,lanes,manifest):
    mapping=json.loads((PLAN/'artifacts/P3-detail/portion-map.json').read_text())['rows']
    source_item='P3.1' if stage in ['facilities','integration'] else ITEM
    rows=[r for r in mapping if r['item']==source_item and r['stage']==stage]
    files=sorted({r['targetTest'].split('::')[0] for r in rows})
    if stage=='facilities':files.extend(['packages/tenant-laravel/tests/Facilities/CacheSessionTest.php','packages/tenant-laravel/tests/Facilities/AtomicCacheAddTest.php','packages/tenant-laravel/tests/Facilities/SessionRecreationTest.php'])
    if stage=='integration':files.append('packages/tenant-laravel/tests/Http/SessionBindingTest.php')
    assert rows and files
    results=[]
    hashes=source_hashes();archive=json.loads((ART/'archives.json').read_text())
    for lane in lanes:
        records=preflight(manifest,lane,stage);host=ROOT/'tests/laravel/hosts'/lane
        framework=['Foundation/Http/Kernel.php','Session/Middleware/StartSession.php','Database/Concerns/ManagesTransactions.php','Queue/Worker.php','Queue/SyncQueue.php','Queue/CallQueuedHandler.php','Queue/Jobs/Job.php','Queue/QueueManager.php','Queue/Events/JobAttempted.php']
        provenance={p:sha(host/'vendor/laravel/framework/src/Illuminate'/p) for p in framework}
        save(ART/lane/(stage+'-framework-sources.json'),provenance)
        for package,meta in archive.items():
            for path,h in meta['sourceFiles'].items():assert sha(host/'vendor/axiomasoft'/package/path)==h,('installed bytes mismatch',lane,package,path)
        env=environment(lane,manifest);env['TENANT_OBSERVATIONS']=str(ART/lane/stage/'observations');junit=ART/lane/(stage+'-junit.xml')
        for fixture in {r['fixtureId'] for r in rows}:
            for variant in ['default','decorator']:
                raw=pathlib.Path(env['TENANT_OBSERVATIONS'])/(fixture+'-'+variant+'.json')
                if raw.exists():raw.unlink()
        records.append(command([LANES[lane],host/'vendor/bin/pest','--configuration',ROOT/'packages/tenant-laravel/phpunit.xml','--compact','--log-junit',junit,*[ROOT/f for f in files]],ART/lane/(stage+'.log'),env))
        import xml.etree.ElementTree as ET
        cases=ET.parse(junit).findall('.//testcase');assert len(cases)>=len(files) and not ET.parse(junit).findall('.//skipped')
        for fixture in {r['fixtureId'] for r in rows}:assert any(fixture in c.attrib.get('name','') for c in cases),'Fixture not discovered '+fixture
        observations={}
        for fixture in sorted({r['fixtureId'] for r in rows}):
            for variant in ['default','decorator']:
                raw=pathlib.Path(env['TENANT_OBSERVATIONS'])/(fixture+'-'+variant+'.json');o=json.loads(raw.read_text());assert o['fixture']==fixture and o['assertions']>0 and o['variant']==variant
                observations[fixture+'-'+variant]={'path':str(raw.relative_to(PLAN)),'sha256':sha(raw),'assertions':o['assertions']}
        result={'lane':lane,'sources':hashes,'sourceDigest':digest(hashes),'commands':records,'discovered':len(cases),'skipped':0,'observations':observations,'status':'passed','lockHash':sha(host/'composer.lock'),'archives':{k:v['sha256'] for k,v in archive.items()}}
        save(ART/lane/(stage+'.json'),result);results.append(result)
    return {'stage':stage,'lanes':results,'status':'passed'}

def reuse():
    mapping=json.loads((PLAN/'artifacts/P3-detail/source-target-map.json').read_text());inspection=json.loads((ART/'source-read.json').read_text());corex=pathlib.Path('/home/vostrikov/projects/packages/corex')
    rows=[]
    for row in mapping['source']:
        if row['item']!=ITEM:continue
        assert sha(corex/row['source']['path'])==row['source']['sha256']
        row={**row,'actualTargets':{p:sha(ROOT/p) for p in row['targets']},'status':'materialized' if row['targets'] else row['decision']};rows.append(row)
    portions=json.loads((PLAN/'artifacts/P3-detail/portion-map.json').read_text())['rows']
    by_fixture={r['fixtureId']:r['targetTest'] for r in portions if r['item']==ITEM}
    tests=[]
    for row in mapping['tests']:
        if row['item']!=ITEM:continue
        assert sha(corex/row['sourcePath'])==row['sourceSha256']
        fixtures=[x for x in row['fixtures'] if x in by_fixture]
        tests.append({**row,'plannedTarget':row['targetTest'],'actualTargets':{x:by_fixture[x] for x in fixtures},'targetHashes':{by_fixture[x].split('::')[0]:sha(ROOT/by_fixture[x].split('::')[0]) for x in fixtures},'status':'adapted' if fixtures else 'kept-owner/deferred','datasetDisposition':row['reason']+' File/DB/memcached cache and DB/memcached sessions are unsupported in v1; reject at boot. Native S3/fake-S3 and product/queue/physical branches remain with their declared owners. Held-service same-ID revival replaced by permanent stale refusal.'})
    value={'item':ITEM,'sourceInspection':inspection,'source':rows,'tests':tests,'newGaps':[g for g in mapping['newGaps'] if g.get('item')==ITEM],'sourceDigest':digest(source_hashes()),'status':'passed','limits':'Source tests are adapted/disposed, not reported as a rerun of CoreX suites. Physical owner remains test-only host; actual consumer integration P5.'}
    save(PLAN/'artifacts/reuse'/(ITEM+'.json'),value);return value

def package(lanes,manifest):
    archive=json.loads((ART/'archives.json').read_text());results=[]
    for lane in lanes:
        host=ROOT/'tests/laravel/hosts'/lane;php=LANES[lane];records=[]
        root=pathlib.Path(manifest['root'])/'installs'/(lane+'-'+digest(source_hashes())[:12]);root.mkdir(parents=True,exist_ok=True)
        assert not (root/'vendor').exists(),'Fresh installation target already exists; never reuse it as fresh proof'
        for f in ['composer.json','composer.lock']:(root/f).write_bytes((host/f).read_bytes())
        records.append(command([php,COMPOSER,'install','--no-dev','--prefer-dist','--no-interaction'],ART/lane/'fresh-install.log',cwd=root))
        graph=json.loads((root/'vendor/composer/installed.json').read_text())['packages'];names=[p['name'] for p in graph]
        assert 'axiomasoft/tenant' in names and 'axiomasoft/tenant-laravel' in names and not any(any(x in n for x in ['corex','vaulter','tenant-postgres']) for n in names)
        for pkg,meta in archive.items():
            for path,h in meta['sourceFiles'].items():assert sha(root/'vendor/axiomasoft'/pkg/path)==h
        records.append(command([php,ROOT/'tests/laravel/Support/preflight.php'],ART/lane/'fresh-runtime.log',environment(lane,manifest,root)))
        # Production package sources only, analysed against each actual locked framework.
        records.append(command([php,host/'vendor/bin/phpstan','analyse','--configuration',ROOT/'packages/tenant-laravel/phpstan.neon','--autoload-file',host/'vendor/autoload.php','--no-progress','--error-format=table'],ART/lane/'phpstan.log',environment(lane,manifest)))
        for source in (ROOT/'packages/tenant-laravel/src').rglob('*.php'):
            proc=subprocess.run([php,'-l',str(source)],capture_output=True);assert proc.returncode==0,proc.stdout.decode()
        # Strict validate has a deliberate release-only missing-license diagnostic, retained raw.
        log=ART/lane/'strict-validate.log';v=subprocess.run([php,COMPOSER,'validate','--strict',str(ROOT/'packages/tenant-laravel/composer.json')],capture_output=True,cwd=ROOT);log.write_bytes(v.stdout+v.stderr)
        assert v.returncode in [1,2] and b'license' in log.read_bytes().lower()
        results.append({'lane':lane,'status':'passed','freshRoot':str(root),'commands':records,'graph':names,'lockHash':sha(host/'composer.lock'),'installedFiles':{str(p.relative_to(root)):sha(p) for p in (root/'vendor/axiomasoft').rglob('*') if p.is_file()},'strictValidate':{'exit':v.returncode,'log':str(log.relative_to(PLAN)),'sha256':sha(log),'classification':'E09 release-only OPEN; not a passing strict validate'},'lintFiles':len(list((ROOT/'packages/tenant-laravel/src').rglob('*.php')))})
    value={'stage':'package','status':'passed','lanes':results,'archives':archive,'sources':source_hashes(),'composerHash':sha(COMPOSER),'binaryHashes':{k:sha(v) for k,v in LANES.items()}}
    save(ART/('package-manifest.json' if ITEM=='P3.1' else 'framework-manifest.json'),value);return value

def ledgers(lanes):
    corpus=json.loads((ROOT/'contracts/fixtures/index.json').read_text());portions=[p for p in corpus['portions'] if p['item']==ITEM];fixtures={x['id']:x for x in corpus['fixtures']}
    hashes=source_hashes();archive=json.loads((ART/'archives.json').read_text());outputs=[]
    for lane in lanes:
        outcomes={}
        for stage in (['facilities','integration'] if ITEM=='P3.1' else ['queue','codec-dispatch','supervisor']):
            v=json.loads((ART/lane/(stage+'.json')).read_text());assert v['sourceDigest']==digest(hashes),'Stale stage evidence';outcomes.update(v['observations'])
        candidate={'checkout':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),'dirtyContentHash':digest(hashes),'artifactHashes':{**{k:v['sha256'] for k,v in archive.items()},**{'raw:'+key:meta['sha256'] for key,meta in outcomes.items()}}}
        rows=[]
        for p in portions:
            fid=p['fixtureId'];a=outcomes[fid+'-default'];b=outcomes[fid+'-decorator']
            rows.append({'portionId':p['id'],'fixtureId':fid,'producerItem':ITEM,'profile':p['profile'],'lane':lane,'proofKind':p['proofKind'],'applicability':p['applicability'],'check':{'procedure':'Original fixture through installed archive and real framework worker/process; both default and decorator','result':0},'expected':p['expectedObservations'],'observed':'Both default and transparent decorator executed the original assigned portion in real IO/framework/process checks. Default '+str(a['assertions'])+' / decorator '+str(b['assertions'])+' assertions. Raw trace includes individual assertions, actual queue callbacks/SQL/hydration or parent-observed process exit/PIDs. Consumer branches remain P5.','status':'passed','rawEvidence':'@plan/'+a['path'],'artifactHashes':candidate['artifactHashes']})
        value={'contract':'tenant.fixture-results/1','corpusHash':sha(ROOT/'contracts/fixtures/index.json'),'indexSchemaHash':sha(ROOT/'contracts/fixtures/index.schema.json'),'resultSchemaHash':sha(ROOT/'contracts/fixtures/results.schema.json'),'candidate':candidate,'results':rows}
        path=ART/lane/'acceptance.json';save(path,value);outputs.append(path)
    save(PLAN/'artifacts/acceptance'/(ITEM+'.json'),json.loads(outputs[0].read_text()));return outputs

def phase_aggregate(lanes,receipt_path):
    # This stage never launches model sessions or repeats unchanged suites.
    inspected=[]
    for lane in lanes:
        for item,count in [('P3.1',63),('P3.2',25)]:
            path=PLAN/'artifacts'/item/lane/'acceptance.json';value=json.loads(path.read_text())
            assert len(value['results'])==count and all(r['status']=='passed' and r['lane']==lane for r in value['results'])
            for row in value['results']:
                raw=PLAN/row['rawEvidence'].removeprefix('@plan/');assert raw.is_file() and raw.stat().st_size>0
            inspected.append({'item':item,'lane':lane,'path':str(path),'sha256':sha(path),'portions':count})
        for stage in ['queue','codec-dispatch','supervisor','facilities','integration']:
            record=json.loads((ART/lane/(stage+'.json')).read_text());assert record['sourceDigest']==digest(source_hashes()) and record['status']=='passed'
            for observation in record['observations'].values():assert sha(PLAN/observation['path'])==observation['sha256']
    assert receipt_path, 'Mandatory independent review receipt is pending; coordinator must supply it before phase acceptance/finish'
    receipt=json.loads(pathlib.Path(receipt_path).read_text())
    assert receipt['subject']=='PLAN1.P3.2' and receipt['candidate']['head']==subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
    expected=json.loads((ART/'review-candidate.json').read_text())
    assert receipt['candidate']==expected, 'Review does not bind the exact supplied candidate'
    assert receipt['verdict']=='GREEN' and receipt['round'] in [1,2], 'Independent review must be GREEN within the existing round budget'
    reviewer=receipt['reviewer'];executor=json.loads((ART/'candidate.json').read_text())['executor']
    assert (reviewer['provider'],reviewer['model'],reviewer.get('effort'))==('codex','gpt-6.1-sol','high')
    assert reviewer.get('session') and reviewer['session']!=executor['session'], 'Author cannot supply independent review'
    assert not any(f['severity'] in ['Major','Blocker'] and f['status'] not in ['rejected','corrected'] for f in receipt['findings'])
    for name,value in expected['paths'].items():
        path=ROOT/name;mode=path.lstat().st_mode
        assert stat.S_ISREG(mode) and value==('100755 ' if mode & 0o111 else '100644 ')+subprocess.check_output(['git','hash-object','--no-filters','--',name],cwd=ROOT,text=True).strip(), 'Reviewed candidate bytes changed: '+name
    # Native finish additionally checks the complete schema, outside changes and lifecycle budgets.
    return {'status':'passed','stage':'aggregate','inspected':inspected,'reviewReceipt':{'path':receipt_path,'sha256':sha(receipt_path)},'limits':'Native finish must additionally validate full review schema, exact candidate digest and required verdict; E09 remains release-only OPEN.'}

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--item',choices=['P3.1','P3.2'],required=True);ap.add_argument('--stage',choices=['prepare','facilities','integration','queue','codec-dispatch','supervisor','trace','package','ledger','aggregate'],required=True);ap.add_argument('--lanes',required=True);ap.add_argument('--run-manifest',required=True);ap.add_argument('--output',required=True);ap.add_argument('--review-receipt');args=ap.parse_args()
    global ITEM, ART
    ITEM=args.item; ART=PLAN/'artifacts'/ITEM
    lanes=args.lanes.split(',');assert lanes==list(LANES),'Required independent three-lane matrix'
    manifest=json.loads(pathlib.Path(args.run_manifest).read_text());started=now()
    if args.stage=='prepare':prepare(lanes,manifest);value={'status':'passed','stage':'prepare'}
    elif args.stage in ['facilities','integration','queue','codec-dispatch','supervisor']:value=tests(args.stage,lanes,manifest)
    elif args.stage=='trace':value=reuse()
    elif args.stage=='package':value=package(lanes,manifest)
    elif args.stage=='ledger':value={'status':'passed','ledgers':[str(p) for p in ledgers(lanes)]}
    else:value=phase_aggregate(lanes,args.review_receipt)
    value['started_at']=started;value['finished_at']=now();save(args.output,value);print(json.dumps({'stage':args.stage,'status':value['status'],'lanes':lanes,'output':args.output}))
if __name__=='__main__':
    try:main()
    except Exception as error:print(str(error),file=sys.stderr);sys.exit(1)
