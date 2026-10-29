#!/usr/bin/env python3
"""Prove the exact newly created PG16/Redis7 container, namespace and role boundary."""
import json
import os
from pathlib import Path
import re
import subprocess

m = json.loads(Path(os.environ['TENANT_LAB_MANIFEST']).read_text())
assert re.fullmatch('[a-f0-9]{12}', m['runId'])
assert m['project'] == 'tenant_lab_' + m['runId']
assert m['root'] == '/tmp/' + m['project'] and not Path(m['root']).is_symlink()
assert Path(m['root']).stat().st_mode & 0o077 == 0
assert m['credentials'] == m['root'] + '/credentials.json'
assert Path(m['credentials']).stat().st_mode & 0o077 == 0
for label, database in m['databases'].items():
    assert database == 'tenant_' + m['runId'] + '_' + label + '_test'
for service, cid in m['containers'].items():
    info = json.loads(subprocess.check_output(['docker', 'inspect', cid]))[0]
    tag = 'postgres:16-alpine' if service == 'pg' else 'redis:7-alpine'
    assert info['State']['Running'] and info['Config']['Labels']['com.docker.compose.project'] == m['project']
    assert info['Image'] == m['images'][tag]['id']
    assert [v['Name'] for v in info['Mounts'] if v['Type'] == 'volume'] == m['volumeIds'][service]
    assert len(info['NetworkSettings']['Networks']) == 1
    assert next(iter(info['NetworkSettings']['Networks'].values()))['NetworkID'] == m['networkIds'][service]
    port = '5432/tcp' if service == 'pg' else '6379/tcp'
    assert info['NetworkSettings']['Ports'][port] == [{'HostIp': '127.0.0.1', 'HostPort': str(m['ports'][service])}]


def sql(query):
    return subprocess.check_output(['docker', 'exec', '-i', m['containers']['pg'], 'psql', '-X', '-At', '-v', 'ON_ERROR_STOP=1',
                                    '-U', m['admin'], '-d', m['databases']['central']], input=query, text=True).strip()


subprocess.run(['docker', 'exec', m['containers']['pg'], 'pg_isready', '-U', m['admin'], '-d', m['databases']['central']], check=True)
assert sql('SELECT system_identifier FROM pg_control_system()') == m['systemIdentifier']
assert sql('SHOW server_version_num').startswith('16')
assert sql("SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls FROM pg_roles WHERE rolname='" + m['runtime'] + "'") == 'f'
for label in ['central', 'catalog', 'template', 'clone', 'recovery']:
    assert sql("SELECT count(*) FROM pg_database WHERE datname='" + m['databases'][label] + "'") == '1'
assert subprocess.check_output(['docker', 'exec', m['containers']['redis'], 'redis-cli', 'ping'], text=True).strip() == 'PONG'
print(json.dumps({'status': 'passed', 'mode': 'host', 'systemIdentifier': m['systemIdentifier'],
                  'databases': m['databases'], 'role': m['runtime'], 'environment': 'testing'}))
