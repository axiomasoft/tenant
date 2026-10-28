#!/usr/bin/env python3
"""Adapt CoreX independent claim barrier to the P4.1 test catalog."""
import json, os, pathlib, subprocess, time
root=pathlib.Path(__file__).resolve().parents[2]
subprocess.run(['python3','-I',str(root/'tests/postgres/lab/preflight.py')],check=True)
m=json.loads(pathlib.Path(os.environ.get('P41_MANIFEST',str(root/'plans/2026.10.24-№1-TENANT/artifacts/P4.1/lab-manifest.json'))).read_text()); creds=json.loads(pathlib.Path(m['credentials']).read_text())
def sql(q):return subprocess.check_output(['docker','exec','-i',m['container'],'psql','-X','-v','ON_ERROR_STOP=1','-At','-U',m['admin'],'-d',m['central']],input=q,text=True).strip()
# No cleanup or reuse of a possibly completed outcome; unique test keys and IDs.
assert sql("SELECT count(*) FROM tenant_pg_resources WHERE signup_key IN ('barrier-key','independent-key')")=='0'
assert sql("SELECT count(*) FROM tenant_pg_resources WHERE id IN ('55555555-5555-4555-8555-555555555555','66666666-6666-4666-8666-666666666666')")=='0'
sql("INSERT INTO tenant_pg_resources(id,cluster_id,template_version,status) VALUES ('55555555-5555-4555-8555-555555555555','11111111-1111-4111-8111-111111111111',1,'pending'),('66666666-6666-4666-8666-666666666666','11111111-1111-4111-8111-111111111111',1,'pending')")
env={**os.environ,'P41_AUTOLOAD':os.environ.get('P41_AUTOLOAD',str(root/'plans/2026.10.24-№1-TENANT/artifacts/P6.1/laravel/hosts/php84-l13/vendor/autoload.php')),'P41_RUN_ID':m['runId'],'P41_HOST':m['host'],'P41_PORT':str(m['port']),'P41_DB':m['central'],'P41_USER':m['runtime'],'P41_PASSWORD':creds['runtimePassword']}
payload={'key':'barrier-key','cluster':'11111111-1111-4111-8111-111111111111','template':'22222222-2222-4222-8222-222222222222','digest':'a'*64}
def spawn(data):
 p=subprocess.Popen([os.environ.get('P41_PHP','php'),str(root/'tests/postgres/claim-worker.php')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,env=env);p.stdin.write(json.dumps(data)+'\n');p.stdin.flush();return p
def collect(p):
 out,err=p.communicate(timeout=15)
 if p.returncode:raise RuntimeError((p.returncode,out,err))
 return json.loads(out)
first=spawn({**payload,'pause':True,'name':'p41-barrier-first'})
assert first.stdout.readline().strip()=='ready'
second=spawn({**payload,'name':'p41-barrier-second'})
try:
 deadline=time.monotonic()+10
 while time.monotonic()<deadline:
  if sql("SELECT coalesce(wait_event_type,'') FROM pg_stat_activity WHERE application_name='p41-barrier-second'")=='Lock': break
  time.sleep(.02)
 else: raise RuntimeError('second process never observed blocked on PostgreSQL lock')
 unrelated=spawn({**payload,'key':'independent-key','name':'p41-independent'})
 other=collect(unrelated)
 first.stdin.write('release\n'); first.stdin.flush()
 one=collect(first);two=collect(second)
 assert one['resource']==two['resource']!=other['resource'] and one['replayed'] is False and two['replayed'] is True
 assert sql("SELECT count(*) FROM tenant_pg_resources WHERE signup_key='barrier-key'")=='1'
 print(json.dumps({'status':'passed','scope':'observed same-key lock and unrelated progress','first':one,'second':two,'other':other}))
finally:
 for p in (first,second):
  if p.poll() is None:p.kill();p.wait()
