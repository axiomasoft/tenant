#!/usr/bin/env python3
import json,os,pathlib,subprocess,time
root=pathlib.Path(__file__).resolve().parents[2];subprocess.run(['python3','-I',str(root/'tests/postgres/lab/preflight.py')],check=True)
m=json.loads(pathlib.Path(os.environ.get('P41_MANIFEST',str(root/'plans/2026.10.24-№1-TENANT/artifacts/P4.1/lab-manifest.json'))).read_text());creds=json.loads(pathlib.Path(m['credentials']).read_text());env={**os.environ,'P41_AUTOLOAD':os.environ.get('P41_AUTOLOAD',str(root/'plans/2026.10.24-№1-TENANT/artifacts/P6.1/laravel/hosts/php84-l13/vendor/autoload.php')),'P41_RUN_ID':m['runId'],'P41_HOST':m['host'],'P41_PORT':str(m['port']),'P41_DB':m['central'],'P41_USER':m['admin'],'P41_PASSWORD':creds['adminPassword'],'P41_RUNTIME_PASSWORD':creds['runtimePassword'],'P41_TRUST':str(pathlib.Path(m['root'])/'receipt-race-trust.json')}
def sql(q):return subprocess.check_output(['docker','exec','-i',m['container'],'psql','-X','-v','ON_ERROR_STOP=1','-At','-U',m['admin'],'-d',m['central']],input=q,text=True).strip()
def spawn(v):
 p=subprocess.Popen([os.environ.get('P41_PHP','php'),str(root/'tests/postgres/provision-late-worker.php')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,env=env);p.stdin.write(json.dumps(v)+'\n');p.stdin.flush();return p
def complete(v):
 p=spawn(v);out,err=p.communicate(timeout=20);return p.returncode,json.loads(out),err
first=spawn({'mode':'execute'})
try:
 start=json.loads(first.stdout.readline());op=start['operation'];assert first.stdout.readline().strip()=='created'
 code,result,err=complete({'mode':'reconcile','operation':op});assert code==2 and result['error']=='Tenant\\Exceptions\\ResourceQuarantined' and result['reason']=='resource_quarantined',(result,err)
 code,result,err=complete({'mode':'cancel','operation':op});assert code==0 and result['status']=='running' and result['cancellationRequested'] is True,(result,err)
 first.kill();first.wait(timeout=10)
 deadline=time.monotonic()+10
 while time.monotonic()<deadline:
  if sql("SELECT count(*) FROM pg_stat_activity WHERE application_name='p41-late-execute'")=='0':break
  time.sleep(.02)
 else:raise RuntimeError('Original backend has not exited')
 code,result,err=complete({'mode':'reconcile','operation':op});assert code==0 and result=={'status':'unknown','operation':op},(result,err)
 assert sql("SELECT count(*) FROM pg_database WHERE datname='"+m['project']+"_late_test'")=='1'
 assert sql("SELECT count(*) FROM tenant_pg_provision_attempts WHERE id='"+op+"'")=='1'
 late=spawn({'mode':'cancel-race'});procs=[late]
 try:
  lateop=json.loads(late.stdout.readline())['operation'];assert late.stdout.readline().strip()=='before-create'
  code,cancel,err=complete({'mode':'cancel','operation':lateop});assert code==0 and cancel['status']=='running' and cancel['cancellationRequested'] is True,(cancel,err)
  late.stdin.write('release\n');late.stdin.flush();out,err=late.communicate(timeout=20);finished=json.loads(out)
  assert late.returncode==0 and finished['status']=='cancelled' and finished['cancellationRequested'] is True and finished['observedEffect']=='quarantined',(out,err)
  code,again,err=complete({'mode':'cancel','operation':lateop});assert code==0 and again==finished,(again,err)
  assert sql("SELECT count(*) FROM tenant_pg_resources WHERE resource_ref='resource:cancelrace' AND status='pending'")=='0'
  assert sql("SELECT count(*) FROM pg_database WHERE datname='"+m['project']+"_cancelrace_test'")=='1'
 finally:
  if late.poll() is None:late.kill();late.wait()
 print(json.dumps({'status':'passed','scope':'live fence, killed post-create, durable cancellation before late CREATE with no grant','unknown':op,'cancelled':lateop}))
finally:
 if first.poll() is None:first.kill();first.wait()
