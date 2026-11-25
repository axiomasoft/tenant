#!/usr/bin/env python3
import json,os,pathlib,subprocess,sys
root=pathlib.Path(__file__).resolve().parents[2]
subprocess.run(['python3','-I',str(root/'tests/postgres/lab/preflight.py')],check=True)
m=json.loads(pathlib.Path(os.environ['P41_MANIFEST']).read_text());creds=json.loads(pathlib.Path(m['credentials']).read_text())
env={**os.environ,'P41_RUN_ID':m['runId'],'P41_HOST':m['host'],'P41_PORT':str(m['port']),'P41_DB':m['central'],'P41_USER':m['admin'],'P41_PASSWORD':creds['adminPassword'],'P41_RUNTIME_PASSWORD':creds['runtimePassword']}
for stage in sys.argv[1:] or ['lifecycle-setup','lifecycle-archive']:
 subprocess.run([os.environ.get('P41_PHP','php8.4'),str(root/('tests/postgres/'+stage+'.php'))],env=env,check=True)
