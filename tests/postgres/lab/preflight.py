#!/usr/bin/env python3
"""Fail closed unless this is the uniquely created P4.1 PG16 test server."""
import json, os, pathlib, re, subprocess
ROOT=pathlib.Path(__file__).resolve().parents[3]
if os.environ.get('P54_LIFECYCLE_REUSE') == '1':
 import runpy
 original=json.loads(pathlib.Path(os.environ['TENANT_LAB_MANIFEST']).read_text())
 adapted=json.loads(pathlib.Path(os.environ['P41_MANIFEST']).read_text())
 for key in original: assert adapted[key] == original[key], ('P5.4 reuse manifest drift',key)
 assert adapted['central']==original['databases']['catalog'] and adapted['container']==original['containers']['pg']
 assert adapted['host']=='127.0.0.1' and adapted['port']==original['ports']['pg']
 runpy.run_path(str(ROOT/'tests/consumers/postgres/preflight.py'),run_name='__main__')
 raise SystemExit(0)
m=json.loads(pathlib.Path(os.environ.get('P41_MANIFEST',str(ROOT/'plans/2026.10.24-№1-TENANT/artifacts/P4.1/lab-manifest.json'))).read_text())
assert re.fullmatch(r'[0-9a-f]{12}',m['runId'])
name=m['project']; assert name in [prefix+m['runId'] for prefix in (['tenant_p41_','tenant_p42_'] if os.environ.get('P4_ITEM')=='P4.2' else ['tenant_p41_'])]; assert m['project']==name and m['central']==name+'_central_test'
assert m['admin']==name+'_admin' and m['runtime']==name+'_runtime' and m['host']=='127.0.0.1'
private=pathlib.Path(m['root']); assert private==pathlib.Path('/tmp')/name and private.stat().st_mode & 0o077==0
secret=pathlib.Path(m['credentials']);assert secret==private/'credentials.json' and secret.stat().st_mode & 0o077==0
p=json.loads(subprocess.check_output(['docker','inspect',m['container']],text=True))[0]
assert p['State']['Running'] and p['Config']['Labels']['com.docker.compose.project']==name and p['Image']==m['imageId']
assert [v['Name'] for v in p['Mounts'] if v['Type']=='volume']==[m['volume']] and all(v['Type']=='volume' for v in p['Mounts'])
assert len(p['NetworkSettings']['Networks'])==1 and next(iter(p['NetworkSettings']['Networks'].values()))['NetworkID']==m['networkId']
assert p['NetworkSettings']['Ports']['5432/tcp']==[{'HostIp':'127.0.0.1','HostPort':str(m['port'])}]
subprocess.run(['docker','exec',m['container'],'pg_isready','-U',m['admin'],'-d',m['central']],check=True,stdout=subprocess.DEVNULL)
def sql(q): return subprocess.check_output(['docker','exec','-i',m['container'],'psql','-X','-v','ON_ERROR_STOP=1','-At','-U',m['admin'],'-d',m['central']],input=q,text=True).strip()
assert sql('SELECT system_identifier FROM pg_control_system()')==m['systemIdentifier']
assert sql('SHOW server_version_num').startswith('16')
assert sql('SELECT current_database()')==m['central']
assert sql("SELECT count(*) FROM pg_database WHERE datname='"+m['central']+"'")=='1'
assert sql("SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication FROM pg_roles WHERE rolname='"+m['runtime']+"'")=='f'
credentials=json.loads(secret.read_text());env={**os.environ,'P41_HOST':m['host'],'P41_PORT':str(m['port']),'P41_DB':m['central'],'P41_USER':m['runtime'],'P41_PASSWORD':credentials['runtimePassword']}
php='''$p=new PDO("pgsql:host=".getenv("P41_HOST").";port=".getenv("P41_PORT").";dbname=".getenv("P41_DB"),getenv("P41_USER"),getenv("P41_PASSWORD"),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$r=$p->query("select current_database(),current_user,current_setting('server_version_num')")->fetch(PDO::FETCH_NUM);if($r[0]!==getenv("P41_DB")||$r[1]!==getenv("P41_USER")||!str_starts_with($r[2],"16"))exit(1);if($p->query("select has_schema_privilege(current_user, 'public', 'CREATE')")->fetchColumn())exit(2);'''
subprocess.run([os.environ.get('P41_PHP','php'),'-r',php],env=env,check=True)
print(json.dumps({'status':'passed','mode':'host','container':m['container'],'systemIdentifier':m['systemIdentifier'],'database':m['central'],'runtime':m['runtime'],'version':m['version']}))
