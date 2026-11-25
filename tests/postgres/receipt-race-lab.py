#!/usr/bin/env python3
"""Observed concurrent and interrupted real claim/receipt behavior in P4.1 lab."""
import json,os,pathlib,subprocess,time
root=pathlib.Path(__file__).resolve().parents[2]
subprocess.run(['python3','-I',str(root/'tests/postgres/lab/preflight.py')],check=True)
m=json.loads(pathlib.Path(os.environ.get('P41_MANIFEST',str(root/'plans/2026.10.24-№1-TENANT/artifacts/P4.1/lab-manifest.json'))).read_text());creds=json.loads(pathlib.Path(m['credentials']).read_text())
env={**os.environ,'P41_AUTOLOAD':os.environ.get('P41_AUTOLOAD',str(root/'plans/2026.10.24-№1-TENANT/artifacts/P6.1/laravel/hosts/php84-l13/vendor/autoload.php')),'P41_RUN_ID':m['runId'],'P41_HOST':m['host'],'P41_PORT':str(m['port']),'P41_DB':m['central'],'P41_USER':m['admin'],'P41_PASSWORD':creds['adminPassword'],'P41_RUNTIME_PASSWORD':creds['runtimePassword'],'P41_TRUST':str(pathlib.Path(m['root'])/'receipt-race-trust.json')}
subprocess.run([os.environ.get('P41_PHP','php'),str(root/'tests/postgres/receipt-race-setup.php')],env=env,check=True)
def sql(q):return subprocess.check_output(['docker','exec','-i',m['container'],'psql','-X','-v','ON_ERROR_STOP=1','-At','-U',m['admin'],'-d',m['central']],input=q,text=True).strip()
def spawn(key,mode,name):
 p=subprocess.Popen([os.environ.get('P41_PHP','php'),str(root/'tests/postgres/receipt-worker.php')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,env=env);p.stdin.write(json.dumps({'key':key,'mode':mode,'name':name})+'\n');p.stdin.flush();return p
def collect(p):
 out,err=p.communicate(timeout=20)
 if p.returncode:raise RuntimeError((p.returncode,out,err))
 return json.loads(out)
procs=[]
try:
 first=spawn('raced','qualify-pause','p41-receipt-first');procs.append(first);assert first.stdout.readline().strip()=='ready'
 second=spawn('raced','normal','p41-receipt-second');procs.append(second)
 deadline=time.monotonic()+10
 while time.monotonic()<deadline:
  if sql("SELECT coalesce(wait_event_type,'') FROM pg_stat_activity WHERE application_name='p41-receipt-second'")=='Lock':break
  time.sleep(.02)
 else:raise RuntimeError('second worker did not block on lock')
 other=spawn('different','normal','p41-receipt-other');procs.append(other);other_result=collect(other)
 first.stdin.write('release\n');first.stdin.flush();a=collect(first);b=collect(second)
 assert a==b and a['resource']!=other_result['resource']
 before=sql("SELECT count(*) FROM tenant_pg_operations")
 pre=spawn('killed-before','precommit','p41-receipt-pre');procs.append(pre);assert pre.stdout.readline().strip()=='precommit';pre.kill();pre.wait(timeout=10)
 assert sql("SELECT count(*) FROM tenant_pg_operations")==before
 post=spawn('lost-response','postcommit','p41-receipt-post');procs.append(post);assert post.stdout.readline().strip()=='committed';post.kill();post.wait(timeout=10)
 row=sql("SELECT id||'|'||resource_ref FROM tenant_pg_operations WHERE operation_key='lost-response'");rid,ref=row.split('|')
 replay=spawn('lost-response','normal','p41-receipt-replay');procs.append(replay);result=collect(replay)
 assert result['operation']==rid and result['resource']==ref
 assert sql("SELECT count(*) FROM tenant_pg_operations WHERE operation_key='lost-response'")=='1'
 print(json.dumps({'status':'passed','scope':'real template and marker verified receipt races, precommit kill and lost response','raced':a,'other':other_result,'replayed':result}))
finally:
 for p in procs:
  if p.poll() is None:p.kill();p.wait()
