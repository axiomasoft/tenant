#!/usr/bin/env python3
"""LIFE-03: kill after claim but before CREATE; original operation stays quarantined."""
import json,os,pathlib,subprocess,time
root=pathlib.Path(__file__).resolve().parents[2];subprocess.run(['python3','-I',str(root/'tests/postgres/lab/preflight.py')],check=True)
m=json.loads(pathlib.Path(os.environ['P41_MANIFEST']).read_text());creds=json.loads(pathlib.Path(m['credentials']).read_text());env={**os.environ,'P41_RUN_ID':m['runId'],'P41_HOST':m['host'],'P41_PORT':str(m['port']),'P41_DB':m['central'],'P41_USER':m['admin'],'P41_PASSWORD':creds['adminPassword'],'P41_RUNTIME_PASSWORD':creds['runtimePassword'],'P41_TRUST':str(pathlib.Path(m['root'])/'receipt-race-trust.json')}
def sql(q):return subprocess.check_output(['docker','exec','-i',m['container'],'psql','-X','-v','ON_ERROR_STOP=1','-At','-U',m['admin'],'-d',m['central']],input=q,text=True).strip()
def spawn(v):
 p=subprocess.Popen([os.environ['P41_PHP'],str(root/'tests/postgres/provision-late-worker.php')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,env=env);p.stdin.write(json.dumps(v)+'\n');p.stdin.flush();return p
p=spawn({'mode':'precreate-crash'})
try:
 op=json.loads(p.stdout.readline())['operation'];assert p.stdout.readline().strip()=='before-create';p.kill();p.wait(timeout=10)
 deadline=time.monotonic()+10
 while time.monotonic()<deadline:
  if sql("SELECT count(*) FROM pg_stat_activity WHERE application_name='p41-late-precreate-crash'")=='0':break
  time.sleep(.02)
 else:raise RuntimeError('Original backend still running')
 q=spawn({'mode':'reconcile','operation':op});out,err=q.communicate(timeout=20);r=json.loads(out);assert q.returncode==0 and r=={'status':'unknown','operation':op},(out,err)
 assert sql("SELECT count(*) FROM pg_database WHERE datname='"+m['project']+"_precrash_test'")=='0'
 assert sql("SELECT count(*) FROM tenant_pg_provision_attempts WHERE id='"+op+"'")=='1'
 print(json.dumps({'status':'passed','scope':'kill after durable claim before CREATE; reconcile original without retry','operation':op}))
finally:
 if p.poll() is None:p.kill();p.wait()
