import json,pathlib,subprocess,runpy
R=pathlib.Path(__file__).resolve().parents[3];A=(R/'plans/2026.10.24-№1-TENANT/artifacts/P5.3').resolve();m=json.loads((A/'lab-manifest.json').read_text())
runpy.run_path(str(R/'scripts/qualify-consumers.py'))['preflight'](m)
def sql(db,s):
 assert db in m['databases'].values()
 q=subprocess.run(['docker','exec','-i',m['containers']['pg'],'psql','-X','-v','ON_ERROR_STOP=1','-U',m['admin'],'-d',db],input=s.encode(),stdout=subprocess.PIPE,stderr=subprocess.STDOUT);assert q.returncode==0,q.stdout.decode();return q.stdout.decode()
# Known-outcome setup ledger permits exact resume, never blind restamping.
marker=A/'central-schema-complete.json'
if marker.exists():print('existing known complete schema');raise SystemExit(0)
s='''CREATE TABLE root_accounts(id uuid primary key,slug text,status text,owner_identity_id uuid,db_name text NOT NULL,deleted_at timestamp,resource_ref text UNIQUE,resource_incarnation text,route_revision text,binding_generation text);\n'''
for n,label in enumerate(['a','b'],1):s+=f"INSERT INTO root_accounts VALUES ('00000000-0000-4000-8000-00000000000{n}','{label}','active',NULL,'{m['databases'][label]}',NULL,'corex:{label}','incarnation:{label}','1','generation:1');\n"
s+=f"GRANT SELECT ON root_accounts TO {m['runtime']};"
print(sql(m['databases']['central'],'BEGIN;'+s+'COMMIT;'))
for label in ['a','b']:
 print(sql(m['databases'][label],f"BEGIN;CREATE TABLE corex_tenant_binding(protocol text,owner_id text,resource_ref text,incarnation text,recovery_generation text); INSERT INTO corex_tenant_binding VALUES('tenant.binding/1','corex','corex:{label}','incarnation:{label}','generation:1'); GRANT SELECT ON corex_tenant_binding TO {m['runtime']};COMMIT;"))
marker.write_text(json.dumps({'status':'passed','server':m['systemIdentifier'],'databases':m['databases']},indent=2)+'\n')
