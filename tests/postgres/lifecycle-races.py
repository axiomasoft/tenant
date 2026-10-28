#!/usr/bin/env python3
"""Observe real PG barriers, DDL, durable cancellation, same-key races and crashed workers."""
import importlib.util,json,os,pathlib,select,subprocess,time,sys
ROOT=pathlib.Path(__file__).resolve().parents[2]
subprocess.run(['python3','-I',str(ROOT/'tests/postgres/lab/preflight.py')],check=True)
m=json.loads(pathlib.Path(os.environ['P41_MANIFEST']).read_text());secrets=json.loads(pathlib.Path(m['credentials']).read_text())
env={**os.environ,'P41_RUN_ID':m['runId'],'P41_HOST':m['host'],'P41_PORT':str(m['port']),'P41_DB':m['central'],'P41_USER':m['admin'],'P41_PASSWORD':secrets['adminPassword'],'P41_RUNTIME_PASSWORD':secrets['runtimePassword']}
php=env.get('P41_PHP','php8.4')
def sql(q):
 return subprocess.check_output(['docker','exec','-i',m['container'],'psql','-X','-At','-v','ON_ERROR_STOP=1','-U',m['admin'],'-d',m['central']],input=q,text=True).strip()
def call(script,*args,ok=True):
 p=subprocess.run([php,str(ROOT/('tests/postgres/'+script+'.php')),*args],env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
 print(json.dumps({'script':script,'args':args,'exit':p.returncode,'output':p.stdout}),flush=True)
 assert (p.returncode==0)==ok,(script,args,p.stdout)
 return json.loads(p.stdout.splitlines()[-1]) if ok else p.stdout
# A separate live PG session owns each barrier; no timer substitutes for observed overlap.
def barrier(scenario):
 p=subprocess.Popen(['docker','exec','-i',m['container'],'psql','-X','-At','-v','ON_ERROR_STOP=1','-U',m['admin'],'-d',m['central']],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,bufsize=1)
 p.stdin.write("SELECT pg_advisory_lock(hashtextextended('lab:barrier:"+scenario+"',0));\n\\echo held\n");p.stdin.flush()
 while p.stdout.readline().strip()!='held':pass
 return p
def release(p,scenario):
 p.stdin.write("SELECT pg_advisory_unlock(hashtextextended('lab:barrier:"+scenario+"',0));\n\\q\n");p.stdin.flush();p.communicate(timeout=20);assert p.returncode==0

def worker(scenario,mode):
 p=subprocess.Popen([php,str(ROOT/'tests/postgres/lifecycle-worker.php'),scenario,mode],env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,bufsize=1)
 ready,_,_=select.select([p.stdout],[],[],20);assert ready,'worker did not reach checkpoint'
 line=p.stdout.readline();print(line.strip(),flush=True);hook=json.loads(line);assert 'hook' in hook
 deadline=time.monotonic()+10
 while time.monotonic()<deadline:
  state=sql('SELECT wait_event_type FROM pg_stat_activity WHERE pid='+str(hook['pid']))
  if state=='Lock':break
  time.sleep(.05)
 else:raise AssertionError('physical backend was not observed waiting on PG barrier')
 return p,hook

def finish(p,expected=0):
 out=p.communicate(timeout=30)[0];print(out,flush=True);assert p.returncode==expected,(p.returncode,out)
 return json.loads(out.splitlines()[-1]) if out else None
# Same original receipt during export; unrelated target progresses; purge cannot race it.
s='export_race';call('lifecycle-race-setup',s);b=barrier(s);p,h=worker(s,'after-export')
same=call('lifecycle-action',s,'same');assert same['operationId']==h['operationId'] and same['status']=='running'
call('lifecycle-action',s,'other',ok=False);call('lifecycle-action',s,'delete',ok=False)
call('lifecycle-race-setup','independent');ind=subprocess.run([php,str(ROOT/'tests/postgres/lifecycle-worker.php'),'independent','normal'],env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=20);assert ind.returncode==0 and 'succeeded' in ind.stdout
print(json.dumps({'independent':ind.stdout,'overlap':'completed while original barrier held'}),flush=True)
release(b,s);result=finish(p);assert result['result']['status']=='succeeded'
# Durable cancellation before submitted export; repeated cancellation is idempotent, no ready grant.
s='cancel_before';call('lifecycle-race-setup',s);b=barrier(s);p,h=worker(s,'before');cancel=call('lifecycle-action',s,'cancel');assert cancel['cancellationRequested']
assert call('lifecycle-action',s,'cancel')==cancel
release(b,s);result=finish(p)['result'];assert result['status']=='cancelled' and result['observedEffect']=='none'
assert sql("SELECT count(*) FROM tenant_pg_lifecycle_operations WHERE id='"+h['operationId']+"' AND manifest IS NOT NULL")=='0'
# Cancel after real dump: observed archive retained, cancelled receipt never ready.
s='cancel_after';call('lifecycle-race-setup',s);b=barrier(s);p,h=worker(s,'after-export');call('lifecycle-action',s,'cancel');release(b,s);result=finish(p)['result'];assert result['status']=='cancelled' and result['observedEffect']=='archived'
# Lost fence does not exclude a live delayed backend; neither reconcile nor second writer may proceed.
s='lost_fence';call('lifecycle-race-setup',s);b=barrier(s);p,h=worker(s,'lost-lock');call('lifecycle-action',s,'reconcile',ok=False);call('lifecycle-action',s,'other',ok=False)
release(b,s);finish(p,74);receipt=call('lifecycle-action',s,'query');assert receipt['status']=='unknown'
assert call('lifecycle-action',s,'reconcile')['status']=='unknown'
# Real lost PostgreSQL connection before DDL: no reconnect may submit a command.
s='lost_connection';setup=call('lifecycle-race-setup',s);b=barrier(s);p,h=worker(s,'before')
assert sql('SELECT pg_terminate_backend('+str(h['pid'])+')')=='t'
release(b,s);finish(p,74)
assert call('lifecycle-action',s,'query')['status']=='unknown'
assert sql("SELECT count(*) FROM pg_database WHERE datname='"+setup['database']+"'")=='1'
# Real archive crash before receipt: recover original durable bytes, never export again.
s='export_crash';call('lifecycle-race-setup',s);b=barrier(s);p,h=worker(s,'after-export');p.kill();finish(p,-9);release(b,s)
receipt=call('lifecycle-action',s,'reconcile');assert receipt['status']=='succeeded' and receipt['operationId']==h['operationId']
# Crash after actual DROP: metadata remains non-deleted until old backend exclusion and reconciliation.
s='drop_crash';setup=call('lifecycle-race-setup',s);b=barrier(s);p,h=worker(s,'after-drop');
assert sql("SELECT count(*) FROM pg_database WHERE datname='"+setup['database']+"'")=='0'
assert sql("SELECT status FROM tenant_pg_resources WHERE resource_ref='"+setup['resource']+"'")=='deleting'
call('lifecycle-action',s,'reconcile',ok=False);p.kill();finish(p,-9);release(b,s)
receipt=call('lifecycle-action',s,'reconcile');assert receipt['status']=='succeeded' and receipt['observedEffect']=='dropped'
assert call('lifecycle-action',s,'reconcile')==receipt
assert sql("SELECT count(*) FROM tenant_pg_lifecycle_outbox WHERE operation_id='"+h['operationId']+"'")=='1'
# Cancellation is not undo after DROP: same durable tombstone, cancelled observation.
s='drop_cancel';setup=call('lifecycle-race-setup',s);b=barrier(s);p,h=worker(s,'after-drop');call('lifecycle-action',s,'cancel');release(b,s);receipt=finish(p)['result'];assert receipt['status']=='cancelled' and receipt['observedEffect']=='dropped'
assert sql("SELECT status FROM tenant_pg_resources WHERE resource_ref='"+setup['resource']+"'")=='deleted'
print(json.dumps({'status':'passed','scope':'PG-observed simultaneous export/delete, original receipts, independent progress, durable cancel, lost lock with live late backend, archive/DROP worker death, quarantine/reconcile','cases':9}))
