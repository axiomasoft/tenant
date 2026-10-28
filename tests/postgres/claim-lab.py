#!/usr/bin/env python3
"""Partial CoreX claim adaptation probe; not a P4.1 acceptance runner."""
import json, os, pathlib, subprocess
root=pathlib.Path(__file__).resolve().parents[2]
subprocess.run(['python3','-I',str(root/'tests/postgres/lab/preflight.py')],check=True)
m=json.loads(pathlib.Path(os.environ.get('P41_MANIFEST',str(root/'plans/2026.10.24-№1-TENANT/artifacts/P4.1/lab-manifest.json'))).read_text())
creds=json.loads(pathlib.Path(m['credentials']).read_text())
def sql(q):
 return subprocess.check_output(['docker','exec','-i',m['container'],'psql','-X','-v','ON_ERROR_STOP=1','-At','-U',m['admin'],'-d',m['central']],input=q,text=True).strip()
# Fresh isolated central DB only. Refuse to repeat setup over an existing catalog.
assert sql("SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_name LIKE 'tenant_pg_%'")=='0'
adminenv={**os.environ,'PGPASSWORD':creds['adminPassword']}
autoload=os.environ.get('P41_AUTOLOAD',str(root/'plans/2026.10.24-№1-TENANT/artifacts/P6.1/laravel/hosts/php84-l13/vendor/autoload.php'))
# Use the package's actual migration via a minimal Illuminate host.
setup=root/'tests/postgres/claim-setup.php'
setupenv={**adminenv,'P41_AUTOLOAD':autoload,'P41_RUN_ID':m['runId'],'P41_HOST':m['host'],'P41_PORT':str(m['port']),'P41_DB':m['central'],'P41_USER':m['admin'],'P41_PASSWORD':creds['adminPassword'],'P41_RUNTIME':m['runtime']}
subprocess.run([os.environ.get('P41_PHP','php'),str(setup)],env=setupenv,check=True)
env={**setupenv,'P41_USER':m['runtime'],'P41_PASSWORD':creds['runtimePassword']}; env.pop('PGPASSWORD',None)
cluster='11111111-1111-4111-8111-111111111111'; template='22222222-2222-4222-8222-222222222222'; digest='a'*64
payload={'key':'same-key','cluster':cluster,'template':template,'digest':digest}
def launch(p):
 proc=subprocess.Popen([os.environ.get('P41_PHP','php'),str(root/'tests/postgres/claim-worker.php')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,env=env)
 proc.stdin.write(json.dumps(p)+'\n');proc.stdin.close();return proc
a,b=launch(payload),launch(payload)
def result(proc):
 out=proc.stdout.read();err=proc.stderr.read();code=proc.wait(timeout=20)
 if code: raise RuntimeError(f'worker exit {code}: {out} {err}')
 return json.loads(out)
x,y=result(a),result(b)
assert x['resource']==y['resource'] and sorted([x['replayed'],y['replayed']])==[False,True]
assert sql("SELECT count(*) FROM tenant_pg_resources WHERE signup_key='same-key'")=='1'
changed=launch({**payload,'digest':'b'*64});out=changed.stdout.read();err=changed.stderr.read();code=changed.wait(timeout=20)
assert code==2 and json.loads(out)['error']=='Tenant\\Postgres\\Exceptions\\ClaimConflict', (out,err)
print(json.dumps({'status':'passed','scope':'partial same-key claim and changed digest','results':[x,y],'conflict':json.loads(out),'runtime':m['runtime']}))
