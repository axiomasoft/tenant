#!/usr/bin/env python3
"""Capture foreign trees and reconstruct a local baseline from verified P5.1 bodies."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess

ROOT = Path(__file__).resolve().parents[3]
PLAN = ROOT / 'plans/2026.10.24-№1-TENANT'
ART = PLAN / 'artifacts/P5.4'


def sha(data):
    return hashlib.sha256(data).hexdigest()


def git(root, *args):
    return subprocess.check_output(['git', '-C', str(root), *args])


def fingerprint(root, paths):
    rows = {}
    for name in paths:
        p = root / name
        if not p.exists() and not p.is_symlink():
            rows[name] = {'kind': 'absent'}
            continue
        st = p.lstat()
        if p.is_symlink():
            rows[name] = {'kind': 'symlink', 'mode': stat.S_IMODE(st.st_mode), 'target': os.readlink(p)}
        elif p.is_file():
            rows[name] = {'kind': 'file', 'mode': stat.S_IMODE(st.st_mode), 'sha256': sha(p.read_bytes())}
    return rows


def capture(root):
    paths = sorted(set(git(root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard').decode().strip('\0').split('\0')) - {''})
    return {'root': str(root), 'head': git(root, 'rev-parse', 'HEAD').decode().strip(),
            'gitDir': git(root, 'rev-parse', '--absolute-git-dir').decode().strip(),
            'remotes': git(root, 'remote', '-v').decode(),
            'status': git(root, 'status', '--porcelain=v1', '--untracked-files=all').decode(),
            'index': git(root, 'ls-files', '--stage').decode(),
            'stagedDiffSha256': sha(git(root, 'diff', '--cached', '--binary')),
            'unstagedDiffSha256': sha(git(root, 'diff', '--binary')),
            'files': fingerprint(root, paths)}


def main():
    session = os.environ['CODEX_SESSION_ID']
    target = Path('/tmp') / ('tenant-p54-' + session[:8]) / 'corex'
    manifest = ART / 'checkout-manifest.json'
    if manifest.exists():
        raise RuntimeError('Existing checkout manifest must be resumed, never overwritten.')
    inspected = json.loads((PLAN / 'artifacts/P5.4-detail/source-inspection.json').read_text())
    seed = Path(inspected['isolatedRoot'])
    body_root = PLAN / 'artifacts/P5.1/final/candidate-bodies/corex'
    for row in inspected['verifiedCorexFiles']:
        for directory in [seed, body_root]:
            assert sha((directory / row['path']).read_bytes()) == row['sha256'], (directory, row['path'])
    original = Path('/home/vostrikov/projects/packages/corex')
    captured = {'tenant': capture(ROOT), 'corexOriginal': capture(original)}
    seed_paths = sorted(str(p.relative_to(seed)) for p in seed.rglob('*') if p.is_file() or p.is_symlink())
    seed_fingerprints = fingerprint(seed, seed_paths)
    ART.mkdir(exist_ok=True)
    if not (ART / 'preservation-before.json').exists():
        (ART / 'preservation-before.json').write_text(json.dumps(captured, indent=2) + '\n')
    if not target.exists():
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copytree(seed, target, symlinks=True)
    assert fingerprint(target, seed_paths) == seed_fingerprints
    if not (target / '.git').exists():
        git(target, 'init', '-b', 'tenant-p54-owner-switch')
    # Machine-local ignored configuration stays present, outside the source baseline.
    baseline_paths = sorted(set(git(target, 'ls-files', '-z', '--cached', '--others', '--exclude-standard').decode().strip('\0').split('\0')) - {''})
    paths_file = target.parent / 'baseline-paths'
    paths_file.write_bytes(b'\0'.join(os.fsencode(p) for p in baseline_paths) + b'\0')
    git(target, 'add', '--pathspec-from-file=' + str(paths_file), '--pathspec-file-nul')
    git(target, 'commit', '-m', 'chore(tenant): reconstruct verified P5.1 source baseline (PLAN1.P5.4)')
    baseline = git(target, 'rev-parse', 'HEAD').decode().strip()
    value = {'item': 'P5.4', 'session': session, 'checkout': str(target),
             'source': str(original), 'seed': str(seed), 'seedHasGit': False,
             'originalGenerations': inspected['historicalHeads'],
             'baseline': baseline, 'baselineKind': 'local reconstruction; historical SHA is provenance only',
             'baselineCapture': capture(target), 'seedFingerprints': seed_fingerprints,
             'verifiedP51SourceFiles': len(inspected['verifiedCorexFiles']),
             'preservation': 'artifacts/P5.4/preservation-before.json'}
    manifest.write_text(json.dumps(value, indent=2) + '\n')
    print(json.dumps({'checkout': str(target), 'baseline': baseline, 'files': len(seed_paths)}))


if __name__ == '__main__':
    main()
