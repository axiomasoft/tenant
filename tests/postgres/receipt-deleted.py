#!/usr/bin/env python3
"""LIFE-DELETED-01: a tombstoned original key cannot allocate again."""
import json,os,pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2];subprocess.run(['python3','-I',str(root/'tests/postgres/lab/preflight.py')],check=True)
m=json.loads(pathlib.Path(os.environ['P41_MANIFEST']).read_text());creds=json.loads(pathlib.Path(m['credentials']).read_text())
env={**os.environ,'P41_RUN_ID':m['runId'],'P41_HOST':m['host'],'P41_PORT':str(m['port']),'P41_DB':m['central'],'P41_USER':m['admin'],'P41_PASSWORD':creds['adminPassword'],'P41_RUNTIME_PASSWORD':creds['runtimePassword'],'P41_TRUST':str(pathlib.Path(m['root'])/'receipt-race-trust.json')}
def sql(q):return subprocess.check_output(['docker','exec','-i',m['container'],'psql','-X','-v','ON_ERROR_STOP=1','-At','-U',m['admin'],'-d',m['central']],input=q,text=True).strip()
row=sql("SELECT id||'|'||resource_id FROM tenant_pg_operations WHERE operation_key='lost-response'");op,resource=row.split('|');before=sql("SELECT count(*) FROM tenant_pg_resources WHERE status='pending'")
assert sql("SELECT count(*) FROM tenant_pg_resources WHERE id='"+resource+"' AND deleted_at IS NULL")=='1'
sql("UPDATE tenant_pg_resources SET deleted_at=now(),status='deleted' WHERE id='"+resource+"'")
p=subprocess.run([os.environ['P41_PHP'],str(root/'tests/postgres/receipt-worker.php')],input=json.dumps({'key':'lost-response','mode':'normal','name':'p41-tombstone'})+'\n',text=True,capture_output=True,env=env,timeout=20);result=json.loads(p.stdout)
assert p.returncode==2 and result['error']=='Tenant\\Postgres\\Exceptions\\ClaimConflict',(p.stdout,p.stderr)
assert sql("SELECT count(*) FROM tenant_pg_resources WHERE status='pending'")==before
assert sql("SELECT count(*) FROM tenant_pg_operations WHERE operation_key='lost-response'")=='1'
p=subprocess.run([os.environ['P41_PHP'],str(root/'tests/postgres/receipt-worker.php')],input=json.dumps({'key':'lost-response','mode':'normal','name':'p41-tombstone-changed','digest':'a'*64})+'\n',text=True,capture_output=True,env=env,timeout=20);changed=json.loads(p.stdout)
assert p.returncode==2 and changed['error']=='Tenant\\Postgres\\Exceptions\\ClaimConflict',(p.stdout,p.stderr)
assert sql("SELECT count(*) FROM tenant_pg_resources WHERE status='pending'")==before
print(json.dumps({'status':'passed','scope':'LIFE-DELETED-01 same key refuses after deletion without new allocation','operation':op}))
