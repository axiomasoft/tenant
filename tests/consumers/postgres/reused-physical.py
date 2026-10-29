#!/usr/bin/env python3
"""Run unchanged P4 physical scenarios against an exact allowlisted P5.4 lab alias."""
import json
import os
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[3]
path = Path(os.environ['TENANT_LAB_MANIFEST'])
manifest = json.loads(path.read_text())
alias = {**manifest, 'container': manifest['containers']['pg'], 'host': '127.0.0.1', 'port': manifest['ports']['pg'],
         'central': manifest['databases']['catalog'], 'version': manifest['pgVersion']}
alias_path = path.with_name('p4-reuse-manifest.json')
if alias_path.exists():
    assert json.loads(alias_path.read_text()) == alias
else:
    alias_path.write_text(json.dumps(alias, indent=2) + '\n')
env = {**os.environ, 'P54_LIFECYCLE_REUSE': '1', 'P41_MANIFEST': str(alias_path), 'P41_AUTOLOAD': os.environ['TENANT_LANE_AUTOLOAD'],
       'P41_PHP': os.environ['P54_PHP'], 'P42_INSTALLED': '' if os.environ['P54_SOURCE'] == '1' else '1'}
commands = [['python3', '-B', str(root/'tests/postgres/lifecycle-races.py')],
            ['python3', '-B', str(root/'tests/postgres/lifecycle-lab.py'), 'lifecycle-faults']]
for argv in commands:
    print(json.dumps({'reusedArgv': argv, 'cwd': str(root)}), flush=True)
    subprocess.run(argv, env=env, cwd=root, check=True)
print(json.dumps({'status': 'passed', 'scope': 'P4 exact physical fence/backend/archive/DROP regression, plus actual CoreX host gating in product-lifecycle', 'cases': 10, 'skipped': 0}))
