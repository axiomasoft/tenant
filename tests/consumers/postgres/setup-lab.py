# Infrastructure adapted from tests/laravel/lab/setup.py; no physical engine is copied.
import pathlib,secrets,json,subprocess,os,time,hashlib,ipaddress,argparse
parser=argparse.ArgumentParser();parser.add_argument('--output',required=True,type=pathlib.Path);args=parser.parse_args()
P=args.output.resolve().parent
allowed=pathlib.Path('plans/2026.10.24-№1-TENANT/artifacts/P5.4').resolve()
assert P==allowed or allowed in P.parents
P.mkdir(parents=True,exist_ok=True)
assert not (P/'lab-manifest.json').exists(), 'Do not overwrite an existing immutable lab manifest'
def run(args,**kw):return subprocess.check_output(args,text=True,**kw).strip()
id=secrets.token_hex(6);name='tenant_lab_'+id
root=pathlib.Path('/tmp')/name;root.mkdir(mode=0o700)
admin='tenant_'+id+'_admin';runtime='tenant_'+id+'_runtime'
password=secrets.token_hex(24);runtime_password=secrets.token_hex(24)
images={}
for tag in ['postgres:16-alpine','redis:7-alpine']:
 v=json.loads(run(['docker','image','inspect',tag]))[0];images[tag]={'id':v['Id'],'digest':v['RepoDigests'][0]}
dbs={k:'tenant_'+id+'_'+k+'_test' for k in ['central','template','clone','catalog','recovery']}
networks=json.loads(run(['docker','network','inspect',*run(['docker','network','ls','-q']).splitlines()]))
used=[ipaddress.ip_network(c['Subnet']) for n in networks for c in (n.get('IPAM',{}).get('Config') or []) if c.get('Subnet')]
subnet=next(str(n) for n in ipaddress.ip_network('10.233.0.0/16').subnets(new_prefix=24) if all(n.version!=u.version or not n.overlaps(u) for u in used))
env={'TENANT_SUBNET':subnet,'TENANT_PG_IMAGE':images['postgres:16-alpine']['digest'],'TENANT_REDIS_IMAGE':images['redis:7-alpine']['digest'],'TENANT_ADMIN':admin,'TENANT_ADMIN_PASSWORD':password,'TENANT_CENTRAL_DB':dbs['central']}
(root/'compose.env').write_text('\n'.join(k+'='+v for k,v in env.items())+'\n');(root/'compose.env').chmod(0o600)
compose=['docker','compose','--env-file',str(root/'compose.env'),'-f',str(pathlib.Path('tests/laravel/lab/compose.yaml').resolve()),'-p',name]
subprocess.run(compose+['up','-d'],check=True,stdout=subprocess.DEVNULL)
containers={k:run(compose+['ps','-q',k]) for k in ['pg','redis']}
for i in range(100):
 q=subprocess.run(['docker','exec',containers['pg'],'pg_isready','-h','127.0.0.1','-U',admin,'-d',dbs['central']],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 if q.returncode==0:break
 time.sleep(.2)
else:raise RuntimeError('PG not ready')
def sql(db,text):return run(['docker','exec','-i',containers['pg'],'psql','-X','-v','ON_ERROR_STOP=1','-At','-U',admin,'-d',db],input=text)
version=sql(dbs['central'],'show server_version_num;');assert version.startswith('16')
system=sql(dbs['central'],'select system_identifier from pg_control_system();')
assert run(['docker','exec',containers['redis'],'redis-cli','ping'])=='PONG'
# All effects below constrained to this newly created exact container/server and exact allowlist.
assert name in run(['docker','inspect','--format','{{index .Config.Labels "com.docker.compose.project"}}',containers['pg']])
sql(dbs['central'],f"CREATE ROLE {runtime} LOGIN PASSWORD '{runtime_password}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;")
for key,db in dbs.items():
 assert db=='tenant_'+id+'_'+key+'_test'
 if key!='central':sql(dbs['central'],f'CREATE DATABASE {db} OWNER {admin};')
 sql(db,f'''REVOKE CREATE ON SCHEMA public FROM PUBLIC;
 CREATE TABLE tenant_binding (protocol text, owner_id text, resource_ref text, incarnation text, recovery_generation text);
 INSERT INTO tenant_binding VALUES ('tenant.binding/1','lab:owner','resource:{key}','incarnation:{key}','recovery:1');
 CREATE TABLE probes (name text PRIMARY KEY, value text NOT NULL);
 INSERT INTO probes VALUES ('identity','{key}');
 GRANT CONNECT ON DATABASE {db} TO {runtime};
 GRANT USAGE ON SCHEMA public TO {runtime};
 GRANT SELECT ON tenant_binding TO {runtime};
 GRANT SELECT,INSERT,UPDATE,DELETE ON probes TO {runtime};''')
if True:
 sql(dbs['central'],f'''CREATE TABLE jobs (id bigserial PRIMARY KEY, queue varchar(255) NOT NULL, payload text NOT NULL, attempts smallint NOT NULL, reserved_at bigint NULL, available_at bigint NOT NULL, created_at bigint NOT NULL);
 CREATE INDEX jobs_queue_idx ON jobs(queue);
 CREATE TABLE failed_jobs (id bigserial PRIMARY KEY, uuid varchar(255) UNIQUE NOT NULL, connection text NOT NULL, queue text NOT NULL, payload text NOT NULL, exception text NOT NULL, failed_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP);
 GRANT SELECT,INSERT,UPDATE,DELETE ON jobs,failed_jobs TO {runtime};
 GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO {runtime};''')
dbs.update({k:'tenant_'+id+'_'+k+'_test' for k in ['a','b']})
ports={k:int(run(['docker','port',containers[k],port]).split(':')[-1]) for k,port in [('pg','5432/tcp'),('redis','6379/tcp')]}
secret={'runtimePassword':runtime_password,'adminPassword':password};(root/'credentials.json').write_text(json.dumps(secret));(root/'credentials.json').chmod(0o600)
inspect={k:json.loads(run(['docker','inspect',v]))[0] for k,v in containers.items()}
manifest={'runId':id,'project':name,'root':str(root),'credentials':str(root/'credentials.json'),'containers':containers,'images':images,'ports':ports,'databases':dbs,'admin':admin,'runtime':runtime,'systemIdentifier':system,'pgVersion':version,'networkIds':{k:list(v['NetworkSettings']['Networks'].values())[0]['NetworkID'] for k,v in inspect.items()},'volumeIds':{k:[m['Name'] for m in v['Mounts'] if m['Type']=='volume'] for k,v in inspect.items()},'created':time.time(),'scope':'P5.4 unique disposable lab; A/B allocated by public PG owner, not infrastructure setup','diskHeadroom':__import__('shutil').disk_usage('/tmp').free}
args.output.write_text(json.dumps(manifest,indent=2)+'\n')
(P/'lab-setup.log').write_text(json.dumps({k:v for k,v in manifest.items() if k!='credentials'},indent=2)+'\n')
print(name,containers,ports,system)
