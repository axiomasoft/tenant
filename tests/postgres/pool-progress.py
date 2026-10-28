#!/usr/bin/env python3
"""Observe pending visibility from a second process while physical creation is incomplete."""
import json,os,pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2];subprocess.run(['python3','-I',str(root/'tests/postgres/lab/preflight.py')],check=True)
m=json.loads(pathlib.Path(os.environ['P41_MANIFEST']).read_text());creds=json.loads(pathlib.Path(m['credentials']).read_text());env={**os.environ,'P41_RUN_ID':m['runId'],'P41_HOST':m['host'],'P41_PORT':str(m['port']),'P41_DB':m['central'],'P41_USER':m['admin'],'P41_PASSWORD':creds['adminPassword'],'P41_RUNTIME_PASSWORD':creds['runtimePassword'],'P41_TRUST':str(pathlib.Path(m['root'])/'receipt-race-trust.json')}
def sql(q):return subprocess.check_output(['docker','exec','-i',m['container'],'psql','-X','-v','ON_ERROR_STOP=1','-At','-U',m['admin'],'-d',m['central']],input=q,text=True).strip()
p=subprocess.Popen([os.environ['P41_PHP'],str(root/'tests/postgres/pool-progress-worker.php')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,env=env)
try:
 initial=json.loads(p.stdout.readline());ref=initial['resource'];database=initial['database'];assert database.startswith(m['project']+'_') and database.endswith('_test')
 assert sql("SELECT status FROM tenant_pg_resources WHERE resource_ref='"+ref+"'")=='provisioning'
 assert sql("SELECT count(*) FROM tenant_pg_resources WHERE resource_ref='"+ref+"' AND status='pending'")=='0'
 assert sql("SELECT count(*) FROM pg_database WHERE datname='"+database+"'")=='1'
 p.stdin.write('release\n');p.stdin.flush();out,err=p.communicate(timeout=20);result=json.loads(out);assert p.returncode==0 and result=={'status':'succeeded','resource':ref},(out,err)
 assert sql("SELECT status FROM tenant_pg_resources WHERE resource_ref='"+ref+"'")=='pending'
 print(json.dumps({'status':'passed','scope':'second process observes non-pending clone until verified publication','resource':ref}))
finally:
 if p.poll() is None:p.kill();p.wait()
