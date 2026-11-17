#!/usr/bin/env python3
"""P6.1 acceptance aggregation of independently executed evidence; fail closed on drift."""
import argparse, hashlib, json, pathlib, runpy, subprocess, sys
ROOT=pathlib.Path(__file__).resolve().parents[1]
def sha(p):
 with pathlib.Path(p).open('rb') as f:return hashlib.file_digest(f,'sha256').hexdigest()
def read(p):return json.loads(pathlib.Path(p).read_text())
def save(p,v):p=pathlib.Path(p);p.parent.mkdir(parents=True,exist_ok=True);p.write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def guard(cond,message):
 if not cond:raise ValueError(message)
def discover_plan(explicit=None,root=ROOT):
 if explicit is not None:
  plan=pathlib.Path(explicit).resolve()
  guard(plan.is_dir(),'acceptance plan directory is missing: '+str(plan))
  return plan
 candidates=sorted(path.resolve() for path in (pathlib.Path(root)/'plans').glob('*TENANT') if path.is_dir())
 guard(bool(candidates),'no acceptance plan found; provide --plan with original producer evidence')
 guard(len(candidates)==1,'multiple acceptance plans found; select one explicitly with --plan')
 return candidates[0]

def raw(plan,p,expected=None):
 plan=pathlib.Path(plan).resolve()
 p=pathlib.Path(p);p=p if p.is_absolute() else plan/p;p=p.resolve();guard(p.is_relative_to(plan) and p.is_file() and p.stat().st_size>0,'evidence outside plan or missing '+str(p))
 if expected:guard(sha(p)==expected,'evidence hash mismatch '+str(p))
 return '@plan/'+str(p.relative_to(plan))
def check_scope(rows, portions):
 actual=[r['portionId'] for r in rows]; expected={p['id'] for p in portions}
 guard(len(actual)==len(set(actual)) and set(actual)==expected, 'missing, duplicate or foreign portion')

def load_evidence(plan):
 plan=pathlib.Path(plan).resolve();art=plan/'artifacts/P6.1'
 checks=read(art/'producer-integrity.json');guard(sha(plan/'artifacts/P6-detail/producer-evidence.json')==checks['indexSha256'],'producer index drift');guard(checks['counts'].get('failed',0)==0 and checks['counts'].get('unavailable',0)==0,'producer input failures')
 for row in checks['rows']:
  if row['status']=='passed':guard(pathlib.Path(row['path']).is_file() and sha(row['path'])==row['expected'],'producer hash drift '+row['path'])
 core=read(art/'core-runs.json');guard(len(core)==4 and all(x['exit']==0 and sha(x['log'])==x['sha256'] for x in core),'core runs missing or failed')
 installs=read(art/'fresh-consumer-installs.json');guard(len(installs)==4 and all(x['status']=='passed' and x['exit']==0 and sha(x['log'])==x['logSha256'] for x in installs),'consumer installs failed')
 laravel=read(art/'laravel-install.json');guard(len(laravel)==3 and all(x['exit']==0 for x in laravel),'Laravel installs failed')
 stages=read(art/'laravel/stages.json');guard(len(stages)==5 and all(x['status']=='passed' for x in stages),'Laravel stage failure')
 results={}
 for k in ['corex','vaulter','flexcrm']:
  p=art/k/'final/consumer.json';d=read(p);guard(d['status']=='passed','consumer failure '+k)
  for c in d['commands']:
   guard(c['exit']==0,'failed command '+k);raw(plan,c['raw'],c['sha256'])
  collections=d['observations'];collections=collections.values() if k=='flexcrm' else (entry for lane in collections.values() for entry in lane.values())
  for entry in collections:raw(plan,entry['path'],entry['sha256'])
  results[k]=d
 # Bind current installed files and locks to the recorded coherent archive generation.
 p51=read(plan/'artifacts/P5.1/archives.json');p52=read(plan/'artifacts/P5.2/archives.json');p53=read(plan/'artifacts/P5.3/archives.json')
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
  host=art/name
  for meta in p53.values():
   for rel,expected in meta['files'].items():guard(sha(host/'vendor'/meta['name']/rel)==expected,'installed FlexCRM drift '+meta['name']+'/'+rel)
 # Recheck source identities: producer output belongs to current owning bodies.
 c=runpy.run_path(str(ROOT/'scripts/qualify-consumers.py'));checkout=pathlib.Path('/home/vostrikov/.local/state/tenant-plan1-p53-01a18446/corex');guard(c['digest'](c['inputs'](checkout))==results['corex']['sourceDigest'],'CoreX inputs changed')
 v=runpy.run_path(str(ROOT/'tests/consumers/vaulter/qualify.py'));checkout=pathlib.Path('/home/vostrikov/.local/state/tenant-plan1-p53-01a18446/vaulter');guard(v['digest'](v['inputs'](checkout))==results['vaulter']['sourceDigest'],'Vaulter inputs changed')
 f=runpy.run_path(str(ROOT/'tests/consumers/flexcrm/qualify.py'));f['inputs'].__globals__['ART']=art/'flexcrm';guard(f['digest'](f['inputs'](art/'flex-dev'))==results['flexcrm']['sourceDigest'],'FlexCRM inputs changed')
 a=runpy.run_path(str(ROOT/'scripts/qualify-laravel.py'));current=a['digest'](a['source_hashes']())
 for lane in ['php84-l12','php84-l13','php85-l13']:
  for s in ['facilities','integration','queue','codec-dispatch','supervisor']:
   d=read(art/'laravel'/lane/(s+'.json'));guard(d['status']=='passed' and d['sourceDigest']==current and d['skipped']==0,'Laravel drift '+lane+'/'+s)
   for o in d['observations'].values():raw(plan,o['path'],o['sha256'])
   for c in d['commands']:guard(c['exit']==0,'Laravel command failed');raw(plan,c['log'],c['sha256'])
 for lane in ['php84-cli','php85-cli']:
  h=read(art/'core-plan/artifacts/P2.2'/(lane+'-host.json'))
  for rel,expected in h['sourceFiles'].items():guard(sha(ROOT/'packages/tenant'/rel)==expected,'core source drift '+rel)
  guard(sha(pathlib.Path(h['host'])/'observations.json')==h['hashes']['observations'],'core observations drift')
 boundary=read(art/'boundary-graph.json');guard(boundary['status']=='passed','boundary graph failure')
 trace=read(art/'flexcrm-trace-inputs.json');guard(sha(art/'flexcrm-source-trace.json')==trace['traceSha256'],'source trace drift')
 for name,expected in trace['files'].items():guard(sha(art/'flex-dev'/name)==expected,'trace source drift '+name)
 archives=read(plan/'artifacts/P5.3/archives.json');hashes={}
 for k,m in archives.items():guard(sha(m['path'])==m['sha256'],'archive drift '+k);hashes['archive:'+k]=m['sha256']
 for k in ['corex','vaulter','flexcrm']:hashes['consumer:'+k]=sha(art/k/'final/consumer.json')
 hashes['laravel-stages']=sha(art/'laravel/stages.json');hashes['core-runs']=sha(art/'core-runs.json');hashes['boundary-graph']=sha(art/'boundary-graph.json');hashes['trace-inputs']=sha(art/'flexcrm-trace-inputs.json')
 return results,hashes

def aggregate(plan):
 plan=pathlib.Path(plan).resolve();art=plan/'artifacts/P6.1'
 results,hashes=load_evidence(plan);portions=read(plan/'artifacts/P6-detail/portion-map.json')['items']['P6.1'];guard(len(portions)==272 and len({p['id'] for p in portions})==272,'scope mismatch')
 source_digest=hashlib.sha256(json.dumps(hashes,sort_keys=True,separators=(',',':')).encode()).hexdigest();p3={x['id']:x for x in read(plan/'artifacts/P3-detail/portion-map.json')['rows']};p22=read(art/'core-plan/artifacts/P2.2/php84-cli-host.json');p22obs=pathlib.Path(p22['host'])/'observations.json';p22rows={x['portionId']:x for x in read(p22obs)['portions']};rows=[]
 for p in portions:
  item=p['item'];fid=p['fixtureId'];app=p['applicability']['status'];lane={'P2.1':'php84-cli','P2.2':'php84-cli','P3.1':'php84-l12','P3.2':'php84-l12','P5.1':'php84-l13','P5.2':'php84-l12','P5.3':'php85-l13'}[item];status='not-run' if app!='required' else 'passed';ev=None;observed=None
  if status=='not-run':
   if item=='P5.2' and p['profile']=='single':lane='php84-cli'
   observed='Unselected conditional or non-applicable branch; not claimed.'
  elif item=='P2.1':
   log=art/'P2.1-php84-cli.log';guard(p['id'] in log.read_text(),'missing core install portion '+p['id']);ev=raw(plan,log);observed='Fresh installed core scenario completed; exact portion observation is in log.'
  elif item=='P2.2':
   obs=p22rows[p['id']];guard(obs['status']=='passed' and all(x['assertions']>0 for x in obs['observations']),'core observation missing');ev=raw(plan,p22obs,p22['hashes']['observations']);observed='Fresh installed core scenario passed in default and decorated variants.'
  elif item in ('P3.1','P3.2'):
   stage=p3[p['id']]['stage'];d=read(art/'laravel'/lane/(stage+'.json'));one=d['observations'][fid+'-default'];two=d['observations'][fid+'-decorator'];guard(one['assertions']>0 and two['assertions']>0,'missing Laravel observations');ev=raw(plan,one['path'],one['sha256']);raw(plan,two['path'],two['sha256']);observed=f'Installed facility scenario passed default and decorator ({one["assertions"]}/{two["assertions"]} assertions); both observations retained in stage record.'
  else:
   d=results[{'P5.1':'corex','P5.2':'vaulter','P5.3':'flexcrm'}[item]];o=d['observations'][fid] if item=='P5.3' else d['observations'][lane][fid];ev=raw(plan,o['path'],o['sha256']);observed='; '.join(o['assertions'])
  row={'portionId':p['id'],'fixtureId':fid,'producerItem':item,'profile':p['profile'],'lane':lane,'proofKind':p['proofKind'],'applicability':p['applicability'],'check':{'procedure':'P6.1 actual fresh installed cohort; see bound evidence and execution records','result':0 if status=='passed' else None},'expected':p['expectedObservations'],'observed':observed,'status':status,'rawEvidence':ev}
  if status=='passed':row['artifactHashes']=hashes
  rows.append(row)
 ledger={'contract':'tenant.fixture-results/1','corpusHash':sha(ROOT/'contracts/fixtures/index.json'),'indexSchemaHash':sha(ROOT/'contracts/fixtures/index.schema.json'),'resultSchemaHash':sha(ROOT/'contracts/fixtures/results.schema.json'),'candidate':{'checkout':str(ROOT),'dirtyContentHash':source_digest,'artifactHashes':hashes},'results':rows}
 validator=runpy.run_path(str(ROOT/'scripts/check-contract-fixtures.py'));profiles=validator['validate_manifests'](validator['load_contracts']());index=validator['parse'](ROOT/'contracts/fixtures/index.json');_,ps=validator['validate_index'](index,validator['parse'](ROOT/'contracts/fixtures/index.schema.json'),profiles);schema=validator['parse'](ROOT/'contracts/fixtures/results.schema.json');seen=validator['validate_results'](ledger,schema,index,ps,profiles,plan);guard(seen=={p['id'] for p in portions},'aggregate scope incomplete');check_scope(rows,portions)
 save(plan/'artifacts/acceptance/P6.1.json',ledger)
 for item in ['P2.1','P2.2','P3.1','P3.2','P5.1','P5.2','P5.3']:
  projected={**ledger,'results':[r for r in rows if r['producerItem']==item]};validator['validate_results'](projected,schema,index,ps,profiles,plan,item=item);save(art/'ledgers'/(item+'.json'),projected)
 # Preserve every additional qualified lane as its own original-producer ledger.
 for item,lanes in [('P2.1',['php85-cli']),('P2.2',['php85-cli']),('P3.1',['php84-l13','php85-l13']),('P3.2',['php84-l13','php85-l13']),('P5.1',['php85-l13']),('P5.2',['php84-l13'])]:
  for lane in lanes:
   projected=[]
   for row in [r for r in rows if r['producerItem']==item]:
    r=dict(row);fid=r['fixtureId'];r['lane']=('php84-cli' if item=='P5.2' and r['profile']=='single' else lane)
    if r['status']=='passed':
     if item=='P2.1':
      log=art/('P2.1-'+lane+'.log');guard(r['portionId'] in log.read_text(),'missing lane portion');r['rawEvidence']=raw(plan,log)
     elif item=='P2.2':
      h=read(art/'core-plan/artifacts/P2.2'/(lane+'-host.json'));obs=pathlib.Path(h['host'])/'observations.json';entry=next(x for x in read(obs)['portions'] if x['portionId']==r['portionId']);guard(entry['status']=='passed' and all(o['assertions']>0 for o in entry['observations']),'missing lane runtime');r['rawEvidence']=raw(plan,obs,h['hashes']['observations'])
     elif item in ('P3.1','P3.2'):
      stage=p3[r['portionId']]['stage'];o=read(art/'laravel'/lane/(stage+'.json'))['observations'];first=o[fid+'-default'];second=o[fid+'-decorator'];guard(first['assertions']>0 and second['assertions']>0,'missing lane observation');r['rawEvidence']=raw(plan,first['path'],first['sha256']);raw(plan,second['path'],second['sha256'])
     else:
      o=results['corex' if item=='P5.1' else 'vaulter']['observations'][lane][fid];r['rawEvidence']=raw(plan,o['path'],o['sha256']);r['observed']='; '.join(o['assertions'])
    projected.append(r)
   value={**ledger,'results':projected};validator['validate_results'](value,schema,index,ps,profiles,plan,item=item);save(art/'ledgers'/(item+'-'+lane+'.json'),value)
 return ledger

def main():
 ap=argparse.ArgumentParser(description=__doc__)
 ap.add_argument('--plan',type=pathlib.Path,help='Original acceptance plan containing producer evidence; required when discovery is absent or ambiguous.')
 ap.add_argument('--item',required=True,choices=['P6.1'])
 ap.add_argument('--stage',required=True,choices=['install','consumers','ledger','extensions','rollback-passport'])
 ap.add_argument('--inputs',required=True,type=pathlib.Path)
 ap.add_argument('--output',required=True,type=pathlib.Path)
 args=ap.parse_args()
 try:
  plan=discover_plan(args.plan)
  producer_index=plan/'artifacts/P6-detail/producer-evidence.json'
  guard(producer_index.is_file(),'acceptance plan has no original producer evidence index: '+str(producer_index))
  guard(args.inputs.resolve()==producer_index.resolve(),'unexpected producer index')
  art=plan/'artifacts/P6.1'
  guard(args.output.resolve().is_relative_to(art.resolve()),'output escapes P6.1')
 except ValueError as error:
  ap.error(str(error))
 result={'item':'P6.1','stage':args.stage,'status':'failed'}
 try:
  if args.stage in ['install','consumers']:
   d,h=load_evidence(plan);result.update({'status':'passed','consumerStatuses':{k:v['status'] for k,v in d.items()},'artifactHashes':h,'scope':'fresh installed lanes and actual consumer evidence'})
  elif args.stage in ['ledger','extensions']:
   ledger=aggregate(plan);ext=[r for r in ledger['results'] if r['portionId'].startswith('EXT-')];guard({x['portionId'][:6] for x in ext}=={f'EXT-{i:02}' for i in range(1,7)} and all(x['status']=='passed' for x in ext),'extension scope incomplete');result.update({'status':'passed','portions':len(ledger['results']),'statusCounts':{s:sum(r['status']==s for r in ledger['results']) for s in ['passed','failed','unavailable','not-run']},'extensions':len(ext),'ledgerSha256':sha(plan/'artifacts/acceptance/P6.1.json')})
  else:
   rb=read(art/'rollback/report.json');pk=read(art/'rollback/restored-packages.json');passport=read(art/'passport.json');guard(rb['status']=='source-reverse-forward-passed' and pk['status']=='passed','rollback incomplete');guard({r['id'] for r in passport['E']}=={f'E{i:02}' for i in range(1,13)} and next(r['status'] for r in passport['E'] if r['id']=='E09')=='OPEN','incomplete E passport');guard(len(passport['N_TC_EXT'])==44 and passport['ledgerSha256']==sha(plan/'artifacts/acceptance/P6.1.json'),'source passport stale');guard(read(art/'boundary-graph.json')['status']=='passed','boundary graph incomplete')
   for row in passport['E']:
    for evidence in row['evidence']:raw(plan,evidence['path'].removeprefix('@plan/'),evidence['sha256'])
   for name in ['corex','vaulter','flexcrm']:
    p=art/'rollback'/f'{name}-probe/final/consumer.json';d=read(p);guard(d['status']=='passed','restored probe incomplete '+name)
    for c in d['commands']:guard(c['exit']==0,'restored command failed');raw(plan,c['raw'],c['sha256'])
   result.update({'status':'passed','sourceRollback':sha(art/'rollback/report.json'),'restoredPackages':sha(art/'rollback/restored-packages.json'),'passportSha256':sha(art/'passport.json'),'restoredConsumers':{n:sha(art/'rollback'/f'{n}-probe/final/consumer.json') for n in ['corex','vaulter','flexcrm']},'E09':'OPEN — owner, support, security and licence facts not established','limitations':['No publication or maturity claim','No deployed-data rollback','No optional P4 physical lifecycle']})
 except Exception as e:
  result['error']=str(e);save(args.output,result);raise
 save(args.output,result);print(json.dumps({'stage':args.stage,'status':result['status']}))
if __name__=='__main__':main()
