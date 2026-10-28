#!/usr/bin/env python3
"""P6.1 acceptance aggregation of independently executed evidence; fail closed on drift."""
import argparse, hashlib, json, pathlib, runpy, subprocess, sys
ROOT=pathlib.Path(__file__).resolve().parents[1]
PLAN=next((ROOT/'plans').glob('*TENANT')).resolve(); ART=PLAN/'artifacts/P6.1'
def sha(p):
 with pathlib.Path(p).open('rb') as f:return hashlib.file_digest(f,'sha256').hexdigest()
def read(p):return json.loads(pathlib.Path(p).read_text())
def save(p,v):p=pathlib.Path(p);p.parent.mkdir(parents=True,exist_ok=True);p.write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def guard(cond,message):
 if not cond:raise ValueError(message)
def raw(p,expected=None):
 p=pathlib.Path(p);p=p if p.is_absolute() else PLAN/p;p=p.resolve();guard(p.is_relative_to(PLAN) and p.is_file() and p.stat().st_size>0,'evidence outside plan or missing '+str(p))
 if expected:guard(sha(p)==expected,'evidence hash mismatch '+str(p))
 return '@plan/'+str(p.relative_to(PLAN))
def check_scope(rows, portions):
 actual=[r['portionId'] for r in rows]; expected={p['id'] for p in portions}
 guard(len(actual)==len(set(actual)) and set(actual)==expected, 'missing, duplicate or foreign portion')

def load_evidence():
 checks=read(ART/'producer-integrity.json');guard(sha(PLAN/'artifacts/P6-detail/producer-evidence.json')==checks['indexSha256'],'producer index drift');guard(checks['counts'].get('failed',0)==0 and checks['counts'].get('unavailable',0)==0,'producer input failures')
 for row in checks['rows']:
  if row['status']=='passed':guard(pathlib.Path(row['path']).is_file() and sha(row['path'])==row['expected'],'producer hash drift '+row['path'])
 core=read(ART/'core-runs.json');guard(len(core)==4 and all(x['exit']==0 and sha(x['log'])==x['sha256'] for x in core),'core runs missing or failed')
 installs=read(ART/'fresh-consumer-installs.json');guard(len(installs)==4 and all(x['status']=='passed' and x['exit']==0 and sha(x['log'])==x['logSha256'] for x in installs),'consumer installs failed')
 laravel=read(ART/'laravel-install.json');guard(len(laravel)==3 and all(x['exit']==0 for x in laravel),'Laravel installs failed')
 stages=read(ART/'laravel/stages.json');guard(len(stages)==5 and all(x['status']=='passed' for x in stages),'Laravel stage failure')
 results={}
 for k in ['corex','vaulter','flexcrm']:
  p=ART/k/'final/consumer.json';d=read(p);guard(d['status']=='passed','consumer failure '+k)
  for c in d['commands']:
   guard(c['exit']==0,'failed command '+k);raw(c['raw'],c['sha256'])
  collections=d['observations'];collections=collections.values() if k=='flexcrm' else (entry for lane in collections.values() for entry in lane.values())
  for entry in collections:raw(entry['path'],entry['sha256'])
  results[k]=d
 # Bind current installed files and locks to the recorded coherent archive generation.
 p51=read(PLAN/'artifacts/P5.1/archives.json');p52=read(PLAN/'artifacts/P5.2/archives.json');p53=read(PLAN/'artifacts/P5.3/archives.json')
 for entry in installs:
  selected=dict(p51 if entry['item']=='P5.1' else p52)
  if entry['item']=='P5.1':selected.update({k:p52[k] for k in ['tenant','tenant-laravel']})
  host=pathlib.Path(entry['cwd']);guard(sha(host/'composer.lock')==entry['lockSha256'],'consumer lock drift')
  import zipfile
  for k,meta in selected.items():
   guard(sha(meta['path'])==meta['sha256'],'consumer archive drift '+k)
   with zipfile.ZipFile(meta['path']) as z:name=json.loads(z.read(k+'/composer.json'))['name']
   for rel,expected in meta['files'].items():guard(sha(host/'vendor'/name/rel)==expected,'installed consumer drift '+name+'/'+rel)
 for entry in laravel:
  host=pathlib.Path(entry['cwd']);guard(sha(host/'composer.lock')==entry['lockSha256'],'Laravel lock drift')
  for k in ['tenant','tenant-laravel']:
   for rel,expected in p52[k]['files'].items():guard(sha(host/'vendor/axiomasoft'/k/rel)==expected,'installed Laravel drift '+k+'/'+rel)
 for name in ['fresh-flexcrm-php85-l13','fresh-flexcrm-php85-l13-repeat']:
  host=ART/name
  for meta in p53.values():
   for rel,expected in meta['files'].items():guard(sha(host/'vendor'/meta['name']/rel)==expected,'installed FlexCRM drift '+meta['name']+'/'+rel)
 # Recheck source identities: producer output belongs to current owning bodies.
 c=runpy.run_path(str(ROOT/'scripts/qualify-consumers.py'));checkout=pathlib.Path('/home/vostrikov/.local/state/tenant-plan1-p53-01a18446/corex');guard(c['digest'](c['inputs'](checkout))==results['corex']['sourceDigest'],'CoreX inputs changed')
 v=runpy.run_path(str(ROOT/'tests/consumers/vaulter/qualify.py'));checkout=pathlib.Path('/home/vostrikov/.local/state/tenant-plan1-p53-01a18446/vaulter');guard(v['digest'](v['inputs'](checkout))==results['vaulter']['sourceDigest'],'Vaulter inputs changed')
 f=runpy.run_path(str(ROOT/'tests/consumers/flexcrm/qualify.py'));f['inputs'].__globals__['ART']=ART/'flexcrm';guard(f['digest'](f['inputs'](ART/'flex-dev'))==results['flexcrm']['sourceDigest'],'FlexCRM inputs changed')
 a=runpy.run_path(str(ROOT/'scripts/qualify-laravel.py'));current=a['digest'](a['source_hashes']())
 for lane in ['php84-l12','php84-l13','php85-l13']:
  for s in ['facilities','integration','queue','codec-dispatch','supervisor']:
   d=read(ART/'laravel'/lane/(s+'.json'));guard(d['status']=='passed' and d['sourceDigest']==current and d['skipped']==0,'Laravel drift '+lane+'/'+s)
   for o in d['observations'].values():raw(o['path'],o['sha256'])
   for c in d['commands']:guard(c['exit']==0,'Laravel command failed');raw(c['log'],c['sha256'])
 for lane in ['php84-cli','php85-cli']:
  h=read(ART/'core-plan/artifacts/P2.2'/(lane+'-host.json'))
  for rel,expected in h['sourceFiles'].items():guard(sha(ROOT/'packages/tenant'/rel)==expected,'core source drift '+rel)
  guard(sha(pathlib.Path(h['host'])/'observations.json')==h['hashes']['observations'],'core observations drift')
 boundary=read(ART/'boundary-graph.json');guard(boundary['status']=='passed','boundary graph failure')
 trace=read(ART/'flexcrm-trace-inputs.json');guard(sha(ART/'flexcrm-source-trace.json')==trace['traceSha256'],'source trace drift')
 for name,expected in trace['files'].items():guard(sha(ART/'flex-dev'/name)==expected,'trace source drift '+name)
 archives=read(PLAN/'artifacts/P5.3/archives.json');hashes={}
 for k,m in archives.items():guard(sha(m['path'])==m['sha256'],'archive drift '+k);hashes['archive:'+k]=m['sha256']
 for k in ['corex','vaulter','flexcrm']:hashes['consumer:'+k]=sha(ART/k/'final/consumer.json')
 hashes['laravel-stages']=sha(ART/'laravel/stages.json');hashes['core-runs']=sha(ART/'core-runs.json');hashes['boundary-graph']=sha(ART/'boundary-graph.json');hashes['trace-inputs']=sha(ART/'flexcrm-trace-inputs.json')
 return results,hashes

def aggregate():
 results,hashes=load_evidence();portions=read(PLAN/'artifacts/P6-detail/portion-map.json')['items']['P6.1'];guard(len(portions)==272 and len({p['id'] for p in portions})==272,'scope mismatch')
 source_digest=hashlib.sha256(json.dumps(hashes,sort_keys=True,separators=(',',':')).encode()).hexdigest();p3={x['id']:x for x in read(PLAN/'artifacts/P3-detail/portion-map.json')['rows']};p22=read(ART/'core-plan/artifacts/P2.2/php84-cli-host.json');p22obs=pathlib.Path(p22['host'])/'observations.json';p22rows={x['portionId']:x for x in read(p22obs)['portions']};rows=[]
 for p in portions:
  item=p['item'];fid=p['fixtureId'];app=p['applicability']['status'];lane={'P2.1':'php84-cli','P2.2':'php84-cli','P3.1':'php84-l12','P3.2':'php84-l12','P5.1':'php84-l13','P5.2':'php84-l12','P5.3':'php85-l13'}[item];status='not-run' if app!='required' else 'passed';ev=None;observed=None
  if status=='not-run':
   if item=='P5.2' and p['profile']=='single':lane='php84-cli'
   observed='Unselected conditional or non-applicable branch; not claimed.'
  elif item=='P2.1':
   log=ART/'P2.1-php84-cli.log';guard(p['id'] in log.read_text(),'missing core install portion '+p['id']);ev=raw(log);observed='Fresh installed core scenario completed; exact portion observation is in log.'
  elif item=='P2.2':
   obs=p22rows[p['id']];guard(obs['status']=='passed' and all(x['assertions']>0 for x in obs['observations']),'core observation missing');ev=raw(p22obs,p22['hashes']['observations']);observed='Fresh installed core scenario passed in default and decorated variants.'
  elif item in ('P3.1','P3.2'):
   stage=p3[p['id']]['stage'];d=read(ART/'laravel'/lane/(stage+'.json'));one=d['observations'][fid+'-default'];two=d['observations'][fid+'-decorator'];guard(one['assertions']>0 and two['assertions']>0,'missing Laravel observations');ev=raw(one['path'],one['sha256']);raw(two['path'],two['sha256']);observed=f'Installed facility scenario passed default and decorator ({one["assertions"]}/{two["assertions"]} assertions); both observations retained in stage record.'
  else:
   d=results[{'P5.1':'corex','P5.2':'vaulter','P5.3':'flexcrm'}[item]];o=d['observations'][fid] if item=='P5.3' else d['observations'][lane][fid];ev=raw(o['path'],o['sha256']);observed='; '.join(o['assertions'])
  row={'portionId':p['id'],'fixtureId':fid,'producerItem':item,'profile':p['profile'],'lane':lane,'proofKind':p['proofKind'],'applicability':p['applicability'],'check':{'procedure':'P6.1 actual fresh installed cohort; see bound evidence and execution records','result':0 if status=='passed' else None},'expected':p['expectedObservations'],'observed':observed,'status':status,'rawEvidence':ev}
  if status=='passed':row['artifactHashes']=hashes
  rows.append(row)
 ledger={'contract':'tenant.fixture-results/1','corpusHash':sha(ROOT/'contracts/fixtures/index.json'),'indexSchemaHash':sha(ROOT/'contracts/fixtures/index.schema.json'),'resultSchemaHash':sha(ROOT/'contracts/fixtures/results.schema.json'),'candidate':{'checkout':str(ROOT),'dirtyContentHash':source_digest,'artifactHashes':hashes},'results':rows}
 validator=runpy.run_path(str(ROOT/'scripts/check-contract-fixtures.py'));profiles=validator['validate_manifests'](validator['load_contracts']());index=validator['parse'](ROOT/'contracts/fixtures/index.json');_,ps=validator['validate_index'](index,validator['parse'](ROOT/'contracts/fixtures/index.schema.json'),profiles);schema=validator['parse'](ROOT/'contracts/fixtures/results.schema.json');seen=validator['validate_results'](ledger,schema,index,ps,profiles,PLAN);guard(seen=={p['id'] for p in portions},'aggregate scope incomplete');check_scope(rows,portions)
 save(PLAN/'artifacts/acceptance/P6.1.json',ledger)
 for item in ['P2.1','P2.2','P3.1','P3.2','P5.1','P5.2','P5.3']:
  projected={**ledger,'results':[r for r in rows if r['producerItem']==item]};validator['validate_results'](projected,schema,index,ps,profiles,PLAN,item=item);save(ART/'ledgers'/(item+'.json'),projected)
 # Preserve every additional qualified lane as its own original-producer ledger.
 for item,lanes in [('P2.1',['php85-cli']),('P2.2',['php85-cli']),('P3.1',['php84-l13','php85-l13']),('P3.2',['php84-l13','php85-l13']),('P5.1',['php85-l13']),('P5.2',['php84-l13'])]:
  for lane in lanes:
   projected=[]
   for row in [r for r in rows if r['producerItem']==item]:
    r=dict(row);fid=r['fixtureId'];r['lane']=('php84-cli' if item=='P5.2' and r['profile']=='single' else lane)
    if r['status']=='passed':
     if item=='P2.1':
      log=ART/('P2.1-'+lane+'.log');guard(r['portionId'] in log.read_text(),'missing lane portion');r['rawEvidence']=raw(log)
     elif item=='P2.2':
      h=read(ART/'core-plan/artifacts/P2.2'/(lane+'-host.json'));obs=pathlib.Path(h['host'])/'observations.json';entry=next(x for x in read(obs)['portions'] if x['portionId']==r['portionId']);guard(entry['status']=='passed' and all(o['assertions']>0 for o in entry['observations']),'missing lane runtime');r['rawEvidence']=raw(obs,h['hashes']['observations'])
     elif item in ('P3.1','P3.2'):
      stage=p3[r['portionId']]['stage'];o=read(ART/'laravel'/lane/(stage+'.json'))['observations'];first=o[fid+'-default'];second=o[fid+'-decorator'];guard(first['assertions']>0 and second['assertions']>0,'missing lane observation');r['rawEvidence']=raw(first['path'],first['sha256']);raw(second['path'],second['sha256'])
     else:
      o=results['corex' if item=='P5.1' else 'vaulter']['observations'][lane][fid];r['rawEvidence']=raw(o['path'],o['sha256']);r['observed']='; '.join(o['assertions'])
    projected.append(r)
   value={**ledger,'results':projected};validator['validate_results'](value,schema,index,ps,profiles,PLAN,item=item);save(ART/'ledgers'/(item+'-'+lane+'.json'),value)
 return ledger

def main():
 ap=argparse.ArgumentParser();ap.add_argument('--item',required=True,choices=['P6.1']);ap.add_argument('--stage',required=True,choices=['install','consumers','ledger','extensions','rollback-passport']);ap.add_argument('--inputs',required=True,type=pathlib.Path);ap.add_argument('--output',required=True,type=pathlib.Path);args=ap.parse_args();guard(args.inputs.resolve()==(PLAN/'artifacts/P6-detail/producer-evidence.json').resolve(),'unexpected producer index');guard(args.output.resolve().is_relative_to(ART.resolve()),'output escapes P6.1');result={'item':'P6.1','stage':args.stage,'status':'failed'}
 try:
  if args.stage in ['install','consumers']:
   d,h=load_evidence();result.update({'status':'passed','consumerStatuses':{k:v['status'] for k,v in d.items()},'artifactHashes':h,'scope':'fresh installed lanes and actual consumer evidence'})
  elif args.stage in ['ledger','extensions']:
   ledger=aggregate();ext=[r for r in ledger['results'] if r['portionId'].startswith('EXT-')];guard({x['portionId'][:6] for x in ext}=={f'EXT-{i:02}' for i in range(1,7)} and all(x['status']=='passed' for x in ext),'extension scope incomplete');result.update({'status':'passed','portions':len(ledger['results']),'statusCounts':{s:sum(r['status']==s for r in ledger['results']) for s in ['passed','failed','unavailable','not-run']},'extensions':len(ext),'ledgerSha256':sha(PLAN/'artifacts/acceptance/P6.1.json')})
  else:
   rb=read(ART/'rollback/report.json');pk=read(ART/'rollback/restored-packages.json');passport=read(ART/'passport.json');guard(rb['status']=='source-reverse-forward-passed' and pk['status']=='passed','rollback incomplete');guard({r['id'] for r in passport['E']}=={f'E{i:02}' for i in range(1,13)} and next(r['status'] for r in passport['E'] if r['id']=='E09')=='OPEN','incomplete E passport');guard(len(passport['N_TC_EXT'])==44 and passport['ledgerSha256']==sha(PLAN/'artifacts/acceptance/P6.1.json'),'source passport stale');guard(read(ART/'boundary-graph.json')['status']=='passed','boundary graph incomplete')
   for row in passport['E']:
    for evidence in row['evidence']:raw(evidence['path'].removeprefix('@plan/'),evidence['sha256'])
   for name in ['corex','vaulter','flexcrm']:
    p=ART/'rollback'/f'{name}-probe/final/consumer.json';d=read(p);guard(d['status']=='passed','restored probe incomplete '+name)
    for c in d['commands']:guard(c['exit']==0,'restored command failed');raw(c['raw'],c['sha256'])
   result.update({'status':'passed','sourceRollback':sha(ART/'rollback/report.json'),'restoredPackages':sha(ART/'rollback/restored-packages.json'),'passportSha256':sha(ART/'passport.json'),'restoredConsumers':{n:sha(ART/'rollback'/f'{n}-probe/final/consumer.json') for n in ['corex','vaulter','flexcrm']},'E09':'OPEN — owner, support, security and licence facts not established','limitations':['No publication or maturity claim','No deployed-data rollback','No optional P4 physical lifecycle']})
 except Exception as e:
  result['error']=str(e);save(args.output,result);raise
 save(args.output,result);print(json.dumps({'stage':args.stage,'status':result['status']}))
if __name__=='__main__':main()
