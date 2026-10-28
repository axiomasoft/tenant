#!/usr/bin/env python3
"""P4.1 lab lanes. Never infers runtime success from fixture presence."""
import argparse,datetime,hashlib,json,os,pathlib,subprocess,time
ROOT=pathlib.Path(__file__).resolve().parents[1]
ART=(ROOT/'plans/2026.10.24-№1-TENANT/artifacts/P4.1').resolve()
HOSTS=ROOT/'plans/2026.10.24-№1-TENANT/artifacts/P6.1/laravel/hosts'
LANES={'php84-l12':('/usr/bin/php8.4','php84-l12'),'php84-l13':('/usr/bin/php8.4','php84-l13'),'php85-l13':('/usr/bin/php8.5','php85-l13')}
STAGES=['claim-lab','race-lab','template-lab','clone-lab','receipt-lab','receipt-race-lab','receipt-conflicts','provision-lab','provision-cancel','provision-late','pool-lab','receipt-unique-race','receipt-deleted','replay-lab','provision-precreate','provision-poststamp','negative-template','marker-privileges','fallback-lab','template-replay-drift','package-install']
def sha(p):return hashlib.sha256(pathlib.Path(p).read_bytes()).hexdigest()
def stamp():return datetime.datetime.now(datetime.timezone.utc).isoformat()
def run(lane,label):
 php,host=LANES[lane]; folder=ART/'lanes'/label/lane
 if folder.exists():raise RuntimeError('Existing lane evidence: inspect outcome; do not overwrite')
 folder.mkdir(parents=True);autoload=HOSTS/host/'vendor/autoload.php';assert pathlib.Path(php).is_file() and autoload.is_file()
 env={**os.environ,'P41_ARTIFACT_DIR':str(folder),'P41_MANIFEST':str(folder/'lab-manifest.json'),'P41_AUTOLOAD':str(autoload),'P41_PHP':php,'P41_INSTALL_DIR':str(folder/'install-host'),'P41_LARAVEL_MAJOR':'12' if lane.endswith('l12') else '13'}
 source={str(p.relative_to(ROOT)):sha(p) for d in [ROOT/'packages/tenant-postgres',ROOT/'tests/postgres'] for p in sorted(d.rglob('*')) if p.is_file() and '__pycache__' not in p.parts}
 checks=[]
 for stage,args in [('setup',['python3','-I',str(ROOT/'tests/postgres/lab/setup.py')]),('manifest-regression',[php,str(ROOT/'tests/postgres/manifest-regression.php')]),('spi-contract',[php,str(ROOT/'tests/postgres/spi-contract.php')])]+[(x,['python3','-I',str(ROOT/('tests/postgres/'+x+'.py'))]) for x in STAGES]:
  start=stamp();t=time.monotonic();p=subprocess.run(args,cwd=ROOT,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT);log=folder/(stage+'.log');log.write_bytes(p.stdout)
  item={'stage':stage,'argv':args,'cwd':str(ROOT),'started':start,'durationSeconds':round(time.monotonic()-t,4),'exit':p.returncode,'status':'passed' if p.returncode==0 else 'failed','log':str(log),'sha256':sha(log)};checks.append(item)
  (folder/'progress.json').write_text(json.dumps({'lane':lane,'status':'in_progress' if not p.returncode else 'failed','checks':checks,'sourceHashes':source},indent=2)+'\n')
  print(lane,stage,item['status'],flush=True)
  if p.returncode:raise RuntimeError('Stage failed; inspect '+str(log))
 return finalize(lane,label)

def finalize(lane,label):
 php,host=LANES[lane];folder=ART/'lanes'/label/lane;autoload=HOSTS/host/'vendor/autoload.php';progress=json.loads((folder/'progress.json').read_text());checks=progress['checks']
 assert len(checks)==len(STAGES)+3 and all(c['status']=='passed' and c['exit']==0 and sha(c['log'])==c['sha256'] for c in checks)
 source={str(p.relative_to(ROOT)):sha(p) for d in [ROOT/'packages/tenant-postgres',ROOT/'tests/postgres'] for p in sorted(d.rglob('*')) if p.is_file() and '__pycache__' not in p.parts}
 assert source==progress['sourceHashes'], 'source changed after tests'
 env={**os.environ,'P41_AUTOLOAD':str(autoload)}
 versions=subprocess.check_output([php,'-r',"require getenv('P41_AUTOLOAD'); echo PHP_VERSION, ' ', Illuminate\\Foundation\\Application::VERSION;"],text=True,env=env)
 result={'lane':lane,'status':'passed','scope':'lab stages only; no automatic corpus or review claim','runtime':versions,'sourceHashes':source,'hostComposerLock':sha(HOSTS/host/'composer.lock'),'labManifest':sha(folder/'lab-manifest.json'),'checks':checks}
 (folder/'result.json').write_text(json.dumps(result,indent=2)+'\n');return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--lane',required=True,choices=LANES);p.add_argument('--finalize',action='store_true');p.add_argument('--run-label',default='qualification');args=p.parse_args();assert args.run_label.isidentifier();finalize(args.lane,args.run_label) if args.finalize else run(args.lane,args.run_label)
