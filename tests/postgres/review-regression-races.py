#!/usr/bin/env python3
"""No stubs: actual signed qualifier/cloner/public owner; observed PG backend overlap."""
import json,os,pathlib,subprocess,select,time,sys
ROOT=pathlib.Path(__file__).resolve().parents[2]
subprocess.run(['python3','-I',str(ROOT/'tests/postgres/lab/preflight.py')],check=True)
m=json.loads(pathlib.Path(os.environ['P41_MANIFEST']).read_text());creds=json.loads(pathlib.Path(m['credentials']).read_text());env={**os.environ,'P41_DB':m['central'],'P41_USER':m['admin'],'P41_PASSWORD':creds['adminPassword'],'P41_RUNTIME_PASSWORD':creds['runtimePassword']};php=os.environ.get('P41_PHP','php8.4')
def sql(q):return subprocess.check_output(['docker','exec','-i',m['container'],'psql','-X','-At','-v','ON_ERROR_STOP=1','-U',m['admin'],'-d',m['central'],'-c',q],text=True).strip()
def spawn(mode,key):return subprocess.Popen([php,str(ROOT/'tests/postgres/review-regression-worker.php'),mode,key],env=env,text=True,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,bufsize=1)
def hook(p):
 ready,_,_=select.select([p.stdout],[],[],20);assert ready,'hook timeout';line=p.stdout.readline();print(line.strip(),flush=True);r=json.loads(line);assert 'hook' in r,r;return r

def finish(p,release=False):
 if release:p.stdin.write('release\n');p.stdin.flush()
 out=p.communicate(timeout=25)[0];print(json.dumps({'exit':p.returncode,'stdout':out}),flush=True);return p.returncode,json.loads(out.splitlines()[-1]) if out else {}

def call(mode,key):return finish(spawn(mode,key))
case=sys.argv[1];observations={}
if case=='F1':
 a=spawn('f1-a','f1-original');h=hook(a);b=spawn('f1-b','f1-other');deadline=time.monotonic()+10;pid=None
 while time.monotonic()<deadline:
  pid=sql('SELECT pid FROM pg_stat_activity WHERE '+str(h['pid'])+'=ANY(pg_blocking_pids(pid)) LIMIT 1')
  if pid:break
  time.sleep(.05)
 assert pid,'qualifier overlap not observed';print(json.dumps({'observedBlockedBackend':pid,'blockingBackend':h['pid']}),flush=True)
 ca,ra=finish(a,True);cb,rb=finish(b);observations={'a':ra,'b':rb,'aExit':ca,'bExit':cb};assert ca==cb==0 and ra['receipt']['status']=='succeeded' and rb['result']=='qualified',observations
elif case=='F2':
 sql("UPDATE tenant_pg_clusters SET capacity=database_count+1 WHERE id='13131313-1313-4313-8313-131313131313'")
 a=spawn('f2-a','f2-original');h=hook(a);cb,rb=call('retry','f2-competing');ca,ra=finish(a,True);observations={'a':ra,'b':rb,'aExit':ca,'bExit':cb}
 assert ca==0 and cb!=0,observations
 c,replay=call('retry','f2-original');assert c==0 and replay['receipt']==ra['receipt']
 row=sql("SELECT signup_key,status FROM tenant_pg_resources WHERE resource_ref='"+ra['receipt']['resourceRef']+"'");assert row.endswith('|provisioning'),row
elif case=='F3':
 sql("UPDATE tenant_pg_clusters SET capacity=database_count+10 WHERE id='13131313-1313-4313-8313-131313131313'")
 a=spawn('f3-marker','f3-original');h=hook(a);a.kill();finish(a)
 deadline=time.monotonic()+10
 while sql('SELECT count(*) FROM pg_stat_activity WHERE pid='+str(h['pid']))!='0':
  assert time.monotonic()<deadline;time.sleep(.02)
 code,spare=call('seed-spare','f3-spare');assert code==0;before=sql("SELECT count(*) FROM pg_database WHERE datname LIKE '"+m['project']+"%'")
 # Client knows only K/intent. No private operation ID is passed to retry/reconcile.
 code,r=call('recover','f3-original');observations=r;assert code==0 and r['retryReceipt']['status'] in ['running','unknown'] and r['reconciled']['status']=='succeeded' and r['after']['operationId']==r['retryReceipt']['operationId']
 assert sql("SELECT status FROM tenant_pg_resources WHERE resource_ref='"+spare['receipt']['resourceRef']+"'")=='pending'
 assert sql("SELECT count(*) FROM pg_database WHERE datname LIKE '"+m['project']+"%'")==before
elif case in ['F3-before','F3-accepted']:
 sql("UPDATE tenant_pg_clusters SET capacity=database_count+10 WHERE id='13131313-1313-4313-8313-131313131313'")
 if int(sql("SELECT count(*) FROM tenant_pg_resources WHERE status='pending' AND signup_key IS NULL")):
  drain,drained=call('retry','consume-spare-'+case.lower());assert drain==0
 assert sql("SELECT count(*) FROM tenant_pg_resources WHERE status='pending' AND signup_key IS NULL")=='0'
 key=case.lower();mode='f3-before-create' if case=='F3-before' else 'f3-accepted';a=spawn(mode,key);h=hook(a);a.kill();finish(a)
 deadline=time.monotonic()+10
 while sql('SELECT count(*) FROM pg_stat_activity WHERE pid='+str(h['pid']))!='0':
  assert time.monotonic()<deadline;time.sleep(.02)
 before=sql("SELECT count(*) FROM pg_database WHERE datname LIKE '"+m['project']+"%'")
 code,r=call('cancel-original',key);observations=r;assert code==0 and r['after']['operationId']==r['retryReceipt']['operationId'] and r['after']['cancellationRequested']
 if case=='F3-accepted':assert r['retryReceipt']['status']=='accepted' and r['after']['status']=='cancelled'
 else:
  assert r['retryReceipt']['status']=='running'
  code,r=call('recover',key);assert code==0 and r['reconciled']['status']=='unknown' and r['after']['cancellationRequested']
 assert sql("SELECT count(*) FROM pg_database WHERE datname LIKE '"+m['project']+"%'")==before
else:raise AssertionError(case)
print(json.dumps({'status':'passed','finding':case,'observations':observations,'realPG':True,'publicOwner':True}),flush=True)
