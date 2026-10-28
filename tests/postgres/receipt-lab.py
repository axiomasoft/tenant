#!/usr/bin/env python3
import json,os,pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
subprocess.run(['python3','-I',str(root/'tests/postgres/lab/preflight.py')],check=True)
m=json.loads(pathlib.Path(os.environ.get('P41_MANIFEST',str(root/'plans/2026.10.24-№1-TENANT/artifacts/P4.1/lab-manifest.json'))).read_text());p=json.loads(pathlib.Path(m['credentials']).read_text())
env={**os.environ,'P41_AUTOLOAD':os.environ.get('P41_AUTOLOAD',str(root/'plans/2026.10.24-№1-TENANT/artifacts/P6.1/laravel/hosts/php84-l13/vendor/autoload.php')),'P41_RUN_ID':m['runId'],'P41_HOST':m['host'],'P41_PORT':str(m['port']),'P41_DB':m['central'],'P41_USER':m['admin'],'P41_PASSWORD':p['adminPassword'],'P41_RUNTIME_PASSWORD':p['runtimePassword']}
subprocess.run([os.environ.get('P41_PHP','php'),str(root/'tests/postgres/receipt-lab.php')],env=env,check=True)
