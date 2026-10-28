#!/usr/bin/env python3
"""CoreX named unique-index race with an uncooperative concurrent writer."""
import json,os,pathlib,subprocess,time
root=pathlib.Path(__file__).resolve().parents[2];subprocess.run(['python3','-I',str(root/'tests/postgres/lab/preflight.py')],check=True)
m=json.loads(pathlib.Path(os.environ['P41_MANIFEST']).read_text());creds=json.loads(pathlib.Path(m['credentials']).read_text());env={**os.environ,'P41_RUN_ID':m['runId'],'P41_HOST':m['host'],'P41_PORT':str(m['port']),'P41_DB':m['central'],'P41_USER':m['admin'],'P41_PASSWORD':creds['adminPassword'],'P41_RUNTIME_PASSWORD':creds['runtimePassword'],'P41_TRUST':str(pathlib.Path(m['root'])/'receipt-race-trust.json')}
def sql(q):return subprocess.check_output(['docker','exec','-i',m['container'],'psql','-X','-v','ON_ERROR_STOP=1','-At','-U',m['admin'],'-d',m['central']],input=q,text=True).strip()
def spawn(data):
 p=subprocess.Popen([os.environ['P41_PHP'],str(root/'tests/postgres/receipt-worker.php')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,env=env);p.stdin.write(json.dumps(data)+'\n');p.stdin.flush();return p
def collect(p):
 out,err=p.communicate(timeout=20)
 if p.returncode:raise RuntimeError((p.returncode,out,err))
 return json.loads(out)
raw=spawn({'key':'uncooperative','mode':'raw','name':'p41-raw','resource':'20202020-2020-4020-8020-202020202020'});claim=None
try:
 assert raw.stdout.readline().strip()=='raw-ready';claim=spawn({'key':'uncooperative','mode':'normal','name':'p41-uncooperative'})
 deadline=time.monotonic()+10
 while time.monotonic()<deadline:
  if sql("SELECT coalesce(wait_event_type,'') FROM pg_stat_activity WHERE application_name='p41-uncooperative'")=='Lock':break
  time.sleep(.02)
 else:raise RuntimeError('Uncooperative unique-index conflict did not block')
 raw.stdin.write('release\n');raw.stdin.flush();collect(raw);result=collect(claim)
 assert result['resource']=='resource:race3',result
 assert sql("SELECT count(*) FROM tenant_pg_operations WHERE operation_key='uncooperative'")=='1'
 print(json.dumps({'status':'passed','scope':'LIFE-01 named unique index and uncooperative writer','operation':result['operation'],'resource':result['resource']}))
finally:
 for p in [raw,claim]:
  if p is not None and p.poll() is None:p.kill();p.wait()
