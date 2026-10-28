#!/usr/bin/env python3
"""Create a new P4.1-only PostgreSQL 16 lab. Never reuses an existing server."""
import hashlib, ipaddress, json, os, pathlib, secrets, subprocess, time

ROOT=pathlib.Path(__file__).resolve().parents[3]
ART=pathlib.Path(os.environ.get('P41_ARTIFACT_DIR',str(ROOT/'plans/2026.10.24-№1-TENANT/artifacts/P4.1'))).resolve()
allowed=(ROOT/('plans/2026.10.24-№1-TENANT/artifacts/'+os.environ.get('P4_ITEM','P4.1'))).resolve()
if os.environ.get('P4_ITEM','P4.1') not in ('P4.1','P4.2'): raise SystemExit('Unsafe item')
if ART!=allowed and allowed not in ART.parents: raise SystemExit('Unsafe artifact directory')
ART.mkdir(parents=True, exist_ok=True)
manifest=ART/'lab-manifest.json'
if manifest.exists(): raise SystemExit('Refusing to overwrite lab manifest')
runid=secrets.token_hex(6); name='tenant_p41_'+runid
private=pathlib.Path('/tmp')/name
private.mkdir(mode=0o700)
admin=name+'_admin'; runtime=name+'_runtime'; central=name+'_central_test'
password=secrets.token_hex(24); runtime_password=secrets.token_hex(24)
def output(args, **kwargs): return subprocess.check_output(args, text=True, **kwargs).strip()
image=json.loads(output(['docker','image','inspect','postgres:16-alpine']))[0]
digest=next(x for x in image['RepoDigests'] if '@sha256:' in x)
netids=output(['docker','network','ls','-q']).splitlines()
nets=json.loads(output(['docker','network','inspect',*netids])) if netids else []
used=[ipaddress.ip_network(c['Subnet']) for n in nets for c in (n.get('IPAM',{}).get('Config') or []) if c.get('Subnet')]
subnet=next(str(n) for n in ipaddress.ip_network('10.234.0.0/16').subnets(new_prefix=24) if all(n.version!=u.version or not n.overlaps(u) for u in used))
compose=private/'compose.yaml'
compose.write_text('''services:\n  pg:\n    image: ${PG_IMAGE:?}\n    environment:\n      POSTGRES_USER: ${PG_ADMIN:?}\n      POSTGRES_PASSWORD: ${PG_PASSWORD:?}\n      POSTGRES_DB: ${PG_DATABASE:?}\n    ports: ["127.0.0.1::5432"]\n    volumes: ["pg:/var/lib/postgresql/data"]\n    networks: [lab]\nvolumes:\n  pg: {}\nnetworks:\n  lab:\n    ipam:\n      config:\n        - subnet: ${PG_SUBNET:?}\n''')
env=private/'compose.env';env.write_text(f'PG_SUBNET={subnet}\nPG_IMAGE={digest}\nPG_ADMIN={admin}\nPG_PASSWORD={password}\nPG_DATABASE={central}\n');env.chmod(0o600)
cmd=['docker','compose','--env-file',str(env),'-f',str(compose),'-p',name]
subprocess.run(cmd+['up','-d'],check=True,stdout=subprocess.DEVNULL)
cid=output(cmd+['ps','-q','pg']); inspect=json.loads(output(['docker','inspect',cid]))[0]
assert inspect['Config']['Labels']['com.docker.compose.project']==name and inspect['Image']==image['Id']
assert inspect['State']['Running'] and len(inspect['Mounts'])==1 and inspect['Mounts'][0]['Type']=='volume'
for _ in range(150):
 if subprocess.run(['docker','exec',cid,'psql','-X','-At','-U',admin,'-d',central,'-c','SELECT 1'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0: break
 time.sleep(.2)
else: raise RuntimeError('Postgres not ready; retain lab for inspection')
def sql(query): return output(['docker','exec','-i',cid,'psql','-X','-v','ON_ERROR_STOP=1','-At','-U',admin,'-d',central],input=query)
version=sql('SHOW server_version_num;'); assert version.startswith('16')
system=sql('SELECT system_identifier FROM pg_control_system();')
assert central==name+'_central_test' and len(name)<30
sql(f"CREATE ROLE {runtime} LOGIN PASSWORD '{runtime_password}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;")
credentials=private/'credentials.json';credentials.write_text(json.dumps({'adminPassword':password,'runtimePassword':runtime_password}));credentials.chmod(0o600)
port=int(output(['docker','port',cid,'5432/tcp']).rsplit(':',1)[1])
data={'project':name,'runId':runid,'container':cid,'imageId':image['Id'],'imageDigest':digest,'networkId':next(iter(inspect['NetworkSettings']['Networks'].values()))['NetworkID'],'volume':inspect['Mounts'][0]['Name'],'systemIdentifier':system,'version':version,'port':port,'host':'127.0.0.1','admin':admin,'runtime':runtime,'central':central,'credentials':str(credentials),'root':str(private)}
manifest.write_text(json.dumps(data,indent=2)+'\n')
print(json.dumps({k:v for k,v in data.items() if k!='credentials'},indent=2))
