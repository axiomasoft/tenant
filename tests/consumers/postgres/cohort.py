#!/usr/bin/env python3
"""Two real PHP processes through the installed/source CoreX public owner composition."""
import json
import os
from pathlib import Path
import secrets
import subprocess
import time

manifest = json.loads(Path(os.environ['TENANT_LAB_MANIFEST']).read_text())
root = Path(manifest['root'])
assert root == Path('/tmp') / ('tenant_lab_' + manifest['runId']) and not root.is_symlink()
checkout = Path(os.environ['COREX_CONSUMER_CHECKOUT'])
php = os.environ['P54_PHP']
argv = [php, str(checkout / 'packages/tenant/tests/Postgres/AccountMappingTest.php'), '--worker']
started = time.monotonic()
token = secrets.token_hex(5)
processes = []


def launch(case, data):
    out = root / ('cohort-' + token + '-' + case + '.stdout')
    err = root / ('cohort-' + token + '-' + case + '.stderr')
    stdout = out.open('w')
    stderr = err.open('w')
    process = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=stdout, stderr=stderr, env={**os.environ, 'APP_ENV': 'testing'})
    process.stdin.write(json.dumps(data).encode())
    process.stdin.close()
    stdout.close()
    stderr.close()
    processes.append((case, process, out, err))
    return process, out, err


def wait_file(path):
    deadline = time.monotonic() + 30
    while not path.exists():
        assert time.monotonic() < deadline, ('barrier timeout', str(path))
        assert all(p.poll() in [None, 0] for _, p, _, _ in processes), 'worker failed before barrier'
        time.sleep(.02)


def finish(worker):
    p, out, err = worker
    assert p.wait(timeout=60) == 0, (out.read_text(), err.read_text())
    assert err.read_text() == '', err.read_text()
    return json.loads(out.read_text().splitlines()[-1])


try:
    barriers = [root / ('same-' + token + '-' + str(n)) for n in range(2)]
    workers = [launch('same-' + str(n), {'barrier': str(b), 'key': 'race-' + token, 'slug': 'race-' + token}) for n, b in enumerate(barriers)]
    for b in barriers:
        wait_file(Path(str(b) + '.ready'))
    for b in barriers:
        Path(str(b) + '.release').touch()
    results = [finish(w) for w in workers]
    assert workers[0][0].pid != workers[1][0].pid
    assert results[0]['account'] == results[1]['account']
    assert any(not r.get('pending') for r in results), results
    for n, result in enumerate(results):
        if result.get('pending'):
            assert result['status'] == 'provisioning', result
            results[n] = finish(launch('replay-' + str(n), {'key': 'race-' + token, 'slug': 'race-' + token}))
            assert not results[n].get('pending'), results[n]
    assert results[0]['mapping']['resource_ref'] == results[1]['mapping']['resource_ref']
    assert json.loads(results[0]['mapping']['provision_operation']) == json.loads(results[1]['mapping']['provision_operation'])
    print('PASS LIFE-01 two-process same key has one original account/resource/operation', flush=True)

    barrier = root / ('hold-' + token)
    holder = launch('holder', {'barrier': str(barrier), 'holdKey': True, 'key': 'blocked-' + token})
    wait_file(Path(str(barrier) + '.ready'))
    blocked = launch('blocked', {'key': 'blocked-' + token, 'slug': 'blocked-' + token})
    independent = launch('independent', {'key': 'independent-' + token, 'slug': 'independent-' + token})
    result = finish(independent)
    assert blocked[0].poll() is None and holder[0].poll() is None
    print('PASS LIFE-01 different key completes while original host key is locked', flush=True)
    Path(str(barrier) + '.release').touch()
    finish(holder)
    unblocked = finish(blocked)
    assert result['account'] != unblocked['account']
    assert result['mapping']['resource_ref'] != unblocked['mapping']['resource_ref']
    print('PASS LIFE-01 distinct keys allocate distinct verified resources', flush=True)
    print(json.dumps({'status': 'passed', 'assertions': 3, 'skipped': 0, 'durationSeconds': time.monotonic() - started,
                      'argv': argv, 'processes': [{'case': case, 'pid': p.pid, 'exit': p.returncode, 'stdout': str(out), 'stderr': str(err)} for case, p, out, err in processes]}))
finally:
    for _, p, _, _ in processes:
        if p.poll() is None:
            p.terminate()
            p.wait(timeout=10)
