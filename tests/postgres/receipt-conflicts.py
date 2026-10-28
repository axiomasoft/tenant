#!/usr/bin/env python3
import json,os,pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2];subprocess.run(['python3','-I',str(root/'tests/postgres/lab/preflight.py')],check=True)
m=json.loads(pathlib.Path(os.environ.get('P41_MANIFEST',str(root/'plans/2026.10.24-№1-TENANT/artifacts/P4.1/lab-manifest.json'))).read_text());creds=json.loads(pathlib.Path(m['credentials']).read_text())
env={**os.environ,'P41_AUTOLOAD':os.environ.get('P41_AUTOLOAD',str(root/'plans/2026.10.24-№1-TENANT/artifacts/P6.1/laravel/hosts/php84-l13/vendor/autoload.php')),'P41_RUN_ID':m['runId'],'P41_HOST':m['host'],'P41_PORT':str(m['port']),'P41_DB':m['central'],'P41_USER':m['admin'],'P41_PASSWORD':creds['adminPassword'],'P41_RUNTIME_PASSWORD':creds['runtimePassword'],'P41_TRUST':str(pathlib.Path(m['root'])/'receipt-race-trust.json')}
def worker(change):
 payload={'key':'raced','mode':'normal','name':'p41-fingerprint',**change};p=subprocess.run([os.environ.get('P41_PHP','php'),str(root/'tests/postgres/receipt-worker.php')],input=json.dumps(payload)+'\n',text=True,capture_output=True,env=env,timeout=20);return p,json.loads(p.stdout)
for change in [{'cluster':'22222222-2222-4222-8222-222222222222'},{'template':'22222222-2222-4222-8222-222222222222'},{'version':9},{'digest':'a'*64}]:
 p,result=worker(change);assert p.returncode==2 and result['error']=='Tenant\\Postgres\\Exceptions\\ClaimConflict',(change,p.stdout,p.stderr)
original=worker({});assert original[0].returncode==0
print(json.dumps({'status':'passed','scope':'changed cluster/template/version/manifest conflicts; original still replays','operation':original[1]['operation']}))
