"""Actual FlexCRM app A/W qualification, source trace and immutable installed cohorts."""
import argparse,datetime,hashlib,json,os,pathlib,re,runpy,subprocess,sys,time,xml.etree.ElementTree as ET
ROOT=pathlib.Path(__file__).resolve().parents[3]
PLAN=next((ROOT/'plans').glob('*TENANT')).resolve();ART=PLAN/'artifacts/P5.3'
PHP='/usr/bin/php8.5'
def sha(p):return hashlib.sha256(pathlib.Path(p).read_bytes()).hexdigest()
def digest(d):return hashlib.sha256(json.dumps(d,sort_keys=True,separators=(',',':')).encode()).hexdigest()
def save(p,d):p.parent.mkdir(parents=True,exist_ok=True);p.write_text(json.dumps(d,ensure_ascii=False,indent=2)+'\n')
def run(argv,cwd,env,log):
 t=time.monotonic();start=datetime.datetime.now(datetime.timezone.utc).isoformat();q=subprocess.run([str(x) for x in argv],cwd=cwd,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
 log.parent.mkdir(parents=True,exist_ok=True);log.write_bytes(q.stdout)
 return {'argv':[str(x) for x in argv],'cwd':str(cwd),'started_at':start,'finished_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'duration':time.monotonic()-t,'exit':q.returncode,'raw':str(log.relative_to(PLAN)),'sha256':sha(log),'environmentHash':digest(dict(sorted(env.items())))}
def inputs(checkout):
 d={}
 for rel in json.loads((ART/'owning-paths.json').read_text()):
  p=checkout/rel
  d['flexcrm/'+rel]=hashlib.sha256(os.readlink(p).encode()).hexdigest() if p.is_symlink() else sha(p) if p.is_file() else 'absent'
 for p in sorted((ROOT/'tests/consumers/flexcrm').glob('*')):
  if p.is_file():d['tenant/'+str(p.relative_to(ROOT))]=sha(p)
 d['tenant/scripts/qualify-consumers.py']=sha(ROOT/'scripts/qualify-consumers.py')
 return d

def installed():
 archives=json.loads((ART/'archives.json').read_text());hosts=json.loads((ART/'hosts.json').read_text());proof={}
 for key,meta in archives.items():
  assert sha(meta['path'])==meta['sha256'],('archive drift',key)
  source=pathlib.Path(meta['source']);observed={}
  for p in source.rglob('*'):
   if not p.is_file() or any(v in p.relative_to(source).parts for v in ['vendor','node_modules','tests','.git']):continue
   rel=str(p.relative_to(source));b=p.read_bytes()
   if rel=='composer.json':
    j=json.loads(b);j['version']='0.3.0' if key.startswith('azguard-') else '1.0.0';j.pop('repositories',None);b=json.dumps(j,indent=4).encode()
   observed[rel]=hashlib.sha256(b).hexdigest()
  assert observed==meta['files'],('source/archive mismatch',key)
 for lane,host in hosts.items():
  c=pathlib.Path(host['checkout']);assert sha(c/'composer.lock')==host['lockSha256'];assert sha(c/'composer.json')==host['composerSha256']
  packages=json.loads((c/'vendor/composer/installed.json').read_text())['packages'];names={p['name'] for p in packages}
  assert not any(n.startswith('corex/') or n.startswith(('stancl/','axiomasoft/stancl-')) for n in names)
  assert not any(p['dist']['type']=='path' for p in packages if p.get('dist'))
  count=0
  for meta in archives.values():
   for rel,expected in meta['files'].items():assert sha(c/'vendor'/meta['name']/rel)==expected,('installed drift',lane,meta['name'],rel);count+=1
  proof[lane]={'files':count,'lockSha256':sha(c/'composer.lock'),'packages':len(packages),'freshNoDev':True}
 return proof

FIXTURES={
 'AUTH-01':['selector denied before effect','authenticated actual CRM context','Tasks freshly disabled actor refused'],
 'AUTH-02':['ordinary revoked account denied','purpose string alone is no maintenance authority','separately authorized maintenance without paid status'],
 'REL-01':['revision between lookup and transactional lock denied','membership revision changed before commit rolls back','effect outbox audit are atomically absent','real effect and public outbox retain original A/W','worker effect uses frozen A/W','actual audit worker preserves original actor W','queued revoked membership refused before product hydration or IO','actual Tasks command via public port and actual AzGuard permission'],
 'REL-03':['no mandatory billing dependency or payer identity'],
 'NULL-APP-01':['before selected {rW}','before missing {}'],
 'NULL-COREX-01':['before selected {rW,r0}','before null {rW,rX,r0}'],
 'NULL-CRM-01':['before W false {rW}','before W true {rW,r0}','historical null guarded {r0}','guard independently denies absent capability'],
 'NULL-TARGET-01':['selected actual app only authorized W','missing CoreX closed {}','AccountWide denied without explicit capability','explicitly authorized AccountWide exact set'],
 'EXT03-SNAPSHOTS':['A-B-A innermost X','B frame own exact snapshot restored','outer A model snapshot not overwritten','partially activated h2 is journaled','shared reverse journal h2-h1 and actual product restore','failed restore poisons and stops real worker process'],
 'SELECT-01':['selection_lost no default workspace','no effect in W2','revoked explicit selection is selection_lost no fallback'],
 'CODEC-01':['known public durable reference freshly admitted','unknown optional ignored while required retired malformed untrusted refused before effect','actual worker four refusals no required skips'],
 'FLEX-COHORT-01':['named central queue completion no failures','real worker cannot write A work in B','worker cleanup central']}

def env(manifest,checkout):
 return {**os.environ,'TENANT_LAB_MANIFEST':str(manifest.resolve()),'FLEXCRM_CHECKOUT':str(checkout),'FLEXCRM_BEFORE_SOURCES':str(ART/'before-source-manifest.json'),'APP_ENV':'testing','XDEBUG_MODE':'off','FLEXCRM_TRACE_OUTPUT':str(ART/'final/public-reference-trace.json')}

def consumer(checkout,manifest,source):
 proof=installed();hosts=json.loads((ART/'hosts.json').read_text());host=pathlib.Path(hosts['php85-l13']['checkout']);folder=ART/'final'/digest(source)[:16];commands=[];outputs=[]
 environment=env(manifest,host)
 probes=[('preflight','Preflight.php','COMPLETE FlexCRM preflight'),('boot','Boot.php','BOOT 13.'),('compile','Compile.php','Wrote compiled registry'),('consumer','ConsumerProbe.php','COMPLETE FlexCRM actual consumer'),('guards','GuardsProbe.php','COMPLETE FlexCRM fresh guards and codecs'),('visibility','VisibilityProbe.php','COMPLETE FlexCRM visibility before and target'),('frames','FrameProbe.php','COMPLETE FlexCRM frame journal'),('tasks','TasksProbe.php','COMPLETE FlexCRM Tasks public cohort')]
 for name,script,marker in probes:
  rec=run([PHP,ROOT/'tests/consumers/flexcrm'/script],host,environment,folder/(name+'.log'));commands.append(rec);save(folder/'commands-progress.json',commands)
  text=(PLAN/rec['raw']).read_text();assert rec['exit']==0 and marker in text,(name,rec,text[-2000:]);assert not any(s in text for s in ['PHP Warning:','PHP Fatal error:','ASSERTION FAILED']);outputs.append(text)
 for driver in ['file','redis']:
  e={**environment,'FLEXCRM_SESSION_DRIVER':driver}
  rec=run([PHP,ROOT/'tests/consumers/flexcrm/HttpSetup.php'],host,e,folder/('http-'+driver+'-setup.log'));commands.append(rec);assert rec['exit']==0 and 'COMPLETE HTTP fixture setup' in (PLAN/rec['raw']).read_text()
  path=folder/('http-'+driver+'.log');rec=run([sys.executable,ROOT/'tests/consumers/flexcrm/http-probe.py','--output',path],host,e,path);commands.append(rec);text=path.read_text();assert rec['exit']==0 and 'COMPLETE real FlexCRM HTTP '+driver in text,(rec,text[-1200:]);outputs.append(text)
  for suffix in ['.server.log','.responses.json']:assert path.with_suffix(suffix).is_file()
 # Second independent fresh no-dev app boot proves the same delivered lock graph.
 repeat=pathlib.Path(hosts['php85-l13-repeat']['checkout']);rec=run([PHP,ROOT/'tests/consumers/flexcrm/Boot.php'],repeat,env(manifest,repeat),folder/'repeat-boot.log');commands.append(rec);assert rec['exit']==0 and 'BOOT 13.' in (PLAN/rec['raw']).read_text()
 # Required owning suites execute the same installed product generation in the isolated development app.
 for meta in json.loads((ART/'archives.json').read_text()).values():
  for rel,h in meta['files'].items():assert sha(checkout/'vendor'/meta['name']/rel)==h,('development installed bytes',meta['name'],rel)
 compile_record=run([PHP,ROOT/'tests/consumers/flexcrm/Compile.php'],checkout,env(manifest,checkout),folder/'source-compile.log');commands.append(compile_record);assert compile_record['exit']==0 and 'Wrote compiled registry' in (PLAN/compile_record['raw']).read_text()
 junit=folder/'source-junit.xml';e={**env(manifest,checkout),'FLEXCRM_TENANT_LAB_ENV':str(ROOT/'tests/consumers/flexcrm/Environment.php')}
 rec=run([PHP,checkout/'vendor/bin/pest','-c',checkout/'phpunit-tenant-lab.xml','--compact','--log-junit',junit],checkout,e,folder/'source-regressions.log');commands.append(rec);assert rec['exit']==0,(rec,(PLAN/rec['raw']).read_text()[-2200:])
 tree=ET.parse(junit);cases=tree.findall('.//testcase');assert len(cases)==34 and not tree.findall('.//skipped') and not tree.findall('.//failure') and not tree.findall('.//error');assert any('boxed context' in c.attrib['name'] for c in cases)
 source_suite={'tests':len(cases),'assertions':sum(int(c.attrib.get('assertions',0)) for c in cases),'skipped':0,'raw':rec,'junit':{'path':str(junit.relative_to(PLAN)),'sha256':sha(junit)}}
 outputs.append('PASS INSTALL-02 two fresh no-dev actual app installs public composition\nPASS INSTALL-03 actual boxed admission source tests passed and qualified PHP85 L13 boot\n')
 observations={};text='\n'.join(outputs)
 for fixture,markers in {**FIXTURES,'INSTALL-02':['two fresh no-dev actual app installs public composition'],'INSTALL-03':['actual boxed admission source tests passed and qualified PHP85 L13 boot']}.items():
  matches=[next((s for s in text.splitlines() if s.startswith('PASS ') and marker in s),None) for marker in markers];assert all(matches),(fixture,markers,matches)
  obj={'fixture':fixture,'lane':'php85-l13','status':'passed','sourceDigest':digest(source),'assertions':matches,'commands':commands,'installed':proof,'owningSuites':source_suite}
  p=folder/'observations'/(fixture+'.json');save(p,obj);observations[fixture]={'path':str(p.relative_to(PLAN)),'sha256':sha(p),'assertions':matches}
 result={'status':'passed','sourceDigest':digest(source),'sourceHashes':source,'commands':commands,'observations':observations,'sourceSuites':source_suite,'installed':proof,'lanes':['php85-l13'],'requiredSkips':0};save(ART/'final/consumer.json',result);return result

def previous(source):
 r=json.loads((ART/'final/consumer.json').read_text());assert r['status']=='passed' and r['sourceDigest']==digest(source)
 for c in r['commands']:assert c['exit']==0 and sha(PLAN/c['raw'])==c['sha256']
 for x in r['observations'].values():assert sha(PLAN/x['path'])==x['sha256']
 return r

def ledger(source,result):
 portions=json.loads((PLAN/'artifacts/P5-detail/P5.3-portions.json').read_text())['portions'];archives={k:v['sha256'] for k,v in json.loads((ART/'archives.json').read_text()).items()};rows=[]
 for portion in portions:
  inactive=portion['applicability']['status']=='conditional';obs=result['observations'].get(portion['fixtureId']);assert inactive or obs
  rows.append({'portionId':portion['id'],'fixtureId':portion['fixtureId'],'producerItem':'P5.3','profile':portion['profile'],'lane':'php85-l13','proofKind':portion['proofKind'],'applicability':portion['applicability'],
   'check':{'procedure':'Actual app installed cohort, owning source regressions, real PG/Redis/HTTP/queue worker; per-fixture observations bind exact commands and input hashes','result':None if inactive else 0},'expected':portion['expectedObservations'],'observed':'Not selected: no owning company/employment/city/commercial policy supplied; no invented product policy.' if inactive else '; '.join(obs['assertions']),'status':'not-run' if inactive else 'passed','rawEvidence':None if inactive else '@plan/'+obs['path'],**({} if inactive else {'artifactHashes':archives})})
 save(PLAN/'artifacts/acceptance/P5.3.json',{'contract':'tenant.fixture-results/1','corpusHash':sha(ROOT/'contracts/fixtures/index.json'),'indexSchemaHash':sha(ROOT/'contracts/fixtures/index.schema.json'),'resultSchemaHash':sha(ROOT/'contracts/fixtures/results.schema.json'),'candidate':{'checkout':str(ROOT),'dirtyContentHash':digest(source),'artifactHashes':archives},'results':rows})

def main():
 p=argparse.ArgumentParser();p.add_argument('--item',choices=['P5.3'],required=True);p.add_argument('--stage',choices=['prepare','consumer','trace','boundary','package','ledger','aggregate'],required=True);p.add_argument('--lanes',required=True);p.add_argument('--checkout-manifest',type=pathlib.Path,required=True);p.add_argument('--run-manifest',type=pathlib.Path,required=True);p.add_argument('--output',type=pathlib.Path,required=True);a=p.parse_args();assert a.lanes=='php85-l13'
 checkout=pathlib.Path(json.loads(a.checkout_manifest.read_text())['checkout']);m=json.loads(a.run_manifest.read_text());runpy.run_path(str(ROOT/'scripts/qualify-consumers.py'))['preflight'](m);source=inputs(checkout)
 report={'item':'P5.3','stage':a.stage,'status':'failed','sourceDigest':digest(source),'sourceHashes':source,'lanes':['php85-l13'],'commands':[],'requiredSkips':0}
 try:
  if a.stage=='consumer':r=consumer(checkout,a.run_manifest,source);report.update(r);ledger(source,r)
  elif a.stage=='prepare':report['installed']=installed()
  else:
   r=previous(source);report['installed']=installed()
   if a.stage=='trace':
    q=run([PHP,ROOT/'tests/consumers/flexcrm/TraceProbe.php'],checkout,env(a.run_manifest,checkout),ART/'final/trace.log');report['commands'].append(q);assert q['exit']==0 and 'COMPLETE public source trace' in (PLAN/q['raw']).read_text()
   if a.stage=='boundary':report['reusedActualCommands']=[c for c in r['commands'] if any(s in c['raw'] for s in ['guards.log','frames.log','visibility.log','http-file.log','http-redis.log','tasks.log'])];assert len(report['reusedActualCommands'])==6
   if a.stage=='package':
    for label,host in json.loads((ART/'hosts.json').read_text()).items():
     c=pathlib.Path(host['checkout']);rec=run([PHP,'/usr/local/bin/composer','validate','--no-check-publish'],c,env(a.run_manifest,c),ART/'final'/('composer-'+label+'.log'));report['commands'].append(rec)
    rec=run([sys.executable,ROOT/'tests/consumers/flexcrm/static-check.py'],ROOT,env(a.run_manifest,checkout),ART/'final/static-check.log');report['commands'].append(rec)
    paths=[str(checkout/x) for x in json.loads((ART/'owning-paths.json').read_text()) if x.endswith('.php') and (checkout/x).is_file()]
    rec=run([PHP,checkout/'vendor/bin/pint','--test',*paths],checkout,env(a.run_manifest,checkout),ART/'final/pint-check.log');report['commands'].append(rec)
    lint=[]
    for path in paths:
     q=subprocess.run([PHP,'-l',path],stdout=subprocess.PIPE,stderr=subprocess.STDOUT);lint.append({'path':path,'exit':q.returncode,'output':q.stdout.decode()});assert q.returncode==0
    save(ART/'final/lint.json',lint);report['lint']={'path':'artifacts/P5.3/final/lint.json','sha256':sha(ART/'final/lint.json'),'files':len(lint)}
    assert all(c['exit']==0 for c in report['commands']),report['commands']
   if a.stage in ['ledger','aggregate']:ledger(source,r)
  report['status']='passed';save(a.output,report);print(json.dumps({'stage':a.stage,'status':'passed','sourceDigest':digest(source)}));return 0
 except Exception as failure:
  report['failure']=str(failure);save(a.output,report);raise
if __name__=='__main__':sys.exit(main())
