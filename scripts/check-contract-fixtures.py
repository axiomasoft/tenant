#!/usr/bin/env python3
"""Validate frozen Tenant v1 specifications; never execute or certify runtime IO."""
import argparse
import hashlib
import json
import sys
import unicodedata
from pathlib import Path

from jsonschema import Draft202012Validator

ROOT = Path(__file__).resolve().parent.parent
CONTRACTS = ROOT / 'contracts'
FIXTURES = CONTRACTS / 'fixtures'
CORPUS_SHA256 = 'c712345f870d1a3d2137a4e85fe69f673ca9cc9c6ccaf9e57f404699a497582a'
SOURCES = {*(f'N-{n:02}' for n in range(1, 13)), *(f'TC-{n:02}' for n in range(1, 27)), *(f'EXT-{n:02}' for n in range(1, 7))}
OWNERS = {
 'P2.1':'N-01 N-12 TC-12 TC-23 TC-24', 'P2.2':'N-02 N-03 N-04 N-12 TC-01 TC-02 TC-03 TC-04 TC-05 TC-14 TC-17 TC-19',
 'P3.1':'N-03 N-04 N-05 TC-01 TC-02 TC-03 TC-19 TC-21', 'P3.2':'N-03 N-04 N-05 TC-02 TC-03 TC-04 TC-06 TC-20 TC-25',
 'P4.1':'N-06 N-07 N-09 N-11 TC-07 TC-08 TC-26', 'P4.2':'N-07 N-08 N-09 N-11 TC-08 TC-09 TC-10 TC-11 TC-22 TC-26',
 'P5.1':'N-01 N-02 N-05 N-09 N-10 N-11 N-12 TC-01 TC-05 TC-06 TC-11 TC-12 TC-14 TC-17 TC-20 TC-21 TC-24',
 'P5.2':'N-01 N-02 N-03 N-05 N-12 TC-05 TC-06 TC-12 TC-20 TC-21 TC-24 TC-25',
 'P5.3':'N-01 N-02 N-05 N-10 N-12 TC-01 TC-05 TC-12 TC-13 TC-14 TC-15 TC-16 TC-17 TC-18 TC-19 TC-20 TC-21 TC-23 TC-24 TC-25',
 'P5.4':'N-06 N-07 N-08 N-09 N-11 TC-07 TC-08 TC-09 TC-10 TC-11 TC-12 TC-22 TC-26',
}
EXT = {'EXT-01': ('P2.1','P2.2','P3.1'), 'EXT-02': ('P2.2','P3.1'), 'EXT-03': ('P2.2','P3.1','P5.3'), 'EXT-04': ('P2.2','P3.1','P3.2'), 'EXT-05': ('P2.2','P3.1'), 'EXT-06': ('P3.1',)}
TRACE = {'EXT01-PLUGIN': ('namespaced capability', 'decorator', 'replacement', 'one owner'), 'EXT02-ORDER': ('deterministic', 'resources before product'), 'EXT02-NEGATIVE': ('exclusive_slot_conflict','missing_dependency','unsupported_capability','dependency_cycle','incompatible_spi','composition_frozen','before prepare'), 'EXT03-SNAPSHOTS': ('reverse','poisons'), 'EXT04-EVENTS': ('generation','isolated','reentry','callback failure'), 'EXT05-OWNER': ('one owner','duplicate','late rebind'), 'EXT06-STREAM': ('open_resource','frame_leak','sentinel','fread'), 'JOURNAL-01': ('started','reverse','h2'), 'EVENT-TRACE-01': ('publish(b,g2)','entered(b)','callback_failed','publish(a,g3)','restored(a)')}

class ContractError(ValueError):
    pass

def require(condition, message):
    if not condition:
        raise ContractError(message)

def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()

def parse(path):
    def pairs(values):
        result = {}
        for key, value in values:
            require(key not in result, f'duplicate key: {key}')
            result[key] = value
        return result
    return json.loads(Path(path).read_text(encoding='utf-8'), object_pairs_hook=pairs,
                      parse_constant=lambda x: (_ for _ in ()).throw(ContractError(f'invalid constant: {x}')))

def schema_validate(schema, obj, what):
    errors = sorted(Draft202012Validator(schema).iter_errors(obj), key=lambda e: str(list(e.absolute_path)))
    require(not errors, f'{what}: {errors[0].message if errors else ""}')

def load_contracts():
    return {name: parse(CONTRACTS / (name + '.v1.json')) for name in ('api','errors','ownership','profiles')}

def validate_manifests(c):
    for name, doc in c.items():
        require(doc.get('contract') == f'tenant.{name}/1' and doc.get('semanticVersion') == '1.0.0', f'{name}: contract/version')
    api, errors, ownership, profiles = (c[n] for n in ('api','errors','ownership','profiles'))
    require(api.get('spiVersion') == profiles.get('spiVersion') == 1 and api.get('wire') == profiles.get('wire') == 'tenant.context/1', 'SPI/wire version conflict')
    require(api['wireValidation']['schema'] == 'contracts/tenant.context.v1.schema.json', 'wire schema reference')
    require({'ContextReference','FrameToken','RuntimeObservation','TrackedStream'} <= set(api['types']), 'missing public type')
    reasons = [e['reason'] for e in errors['errors']]
    require(len(reasons) == len(set(reasons)) and {'missing','denied','stale_incarnation','restore_failed'} <= set(reasons), 'reason codes')
    ps = profiles['profiles']; ids = [p['id'] for p in ps]; require(len(ids) == len(set(ids)) and set(ids) == {'php-scoped','single','laravel-pg-database','postgres-lifecycle'}, 'profile IDs')
    lanes = profiles['lanes']
    for p in ps:
        require(p['version'] == 1 and p['runtimeStatus'] == 'not-run' and all(lane in lanes for lane in p['lanes']), 'profile/version/lane')
    for lane, php, laravel in [('php84-l12','8.4.x','12.x'),('php84-l13','8.4.x','13.x'),('php85-l13','8.5.x','13.x')]:
        require(lanes[lane]['php'] == php and lanes[lane]['laravel'] == laravel, 'qualification matrix mismatch')
    require('FlexCRM' in lanes['php85-l13']['consumer'], 'FlexCRM lane missing')
    for checkpoint, owner in [('P5.1','CoreX'),('P5.4','axiomasoft/tenant-postgres')]:
        x = ownership['checkpoints'][checkpoint]
        require(x['physicalOwner'] == owner and x['frameOwner'] == 'axiomasoft/tenant', 'checkpoint owner conflict')
        require(all(x['writerByCheckpoint'][checkpoint] == owner for x in ownership['physicalOperations']), 'physical writer conflict')
    identities = [x['id'] for x in ownership['identities']]
    require(len(identities) == len(set(identities)), 'duplicate ownership identity')
    require(set(profiles['extPortions']) == set(EXT), 'extension set')
    for ext, owners in EXT.items():
        require(set(profiles['extPortions'][ext]['owners']) == set(owners), f'{ext}: owners')
    return {p['id']:p for p in ps}

def validate_index(index, index_schema, profiles, check_frozen=True):
    schema_validate(index_schema, index, 'fixture index')
    require(index['contract'] == 'tenant.fixture-index/1', 'fixture contract version')
    if check_frozen:
        require(digest(FIXTURES / 'index.json') == CORPUS_SHA256, 'frozen corpus hash changed: update contract and lock deliberately')
        require(set(index['frozenInputs']) == {f'contracts/{x}' for x in ('api.v1.json','errors.v1.json','ownership.v1.json','profiles.v1.json','tenant.context.v1.schema.json')}, 'frozen input list')
        for path, expected in index['frozenInputs'].items():
            require(digest(ROOT / path) == expected, f'frozen input hash: {path}')
    fs = {f['id']: f for f in index['fixtures']}; ps = {p['id']: p for p in index['portions']}
    require(len(fs) == len(index['fixtures']) and len(ps) == len(index['portions']), 'duplicate fixture/portion')
    require({p['sourceId'] for p in ps.values()} == SOURCES, 'source coverage')
    actual = set()
    for p in ps.values():
        f = fs.get(p['fixtureId']); require(f is not None, f"unknown fixture: {p['fixtureId']}")
        require(p['profile'] in profiles, 'unknown profile')
        require(p['sourceId'] in f['sourceBranches'], 'source reference mismatch')
        assignment = {k:p[k] for k in ('item','profile','proofKind')}
        require(assignment in f['assignments'], 'assignment mismatch')
        require(p['applicability'] == f['applicability'] and p['expectedObservations'] == f['expected'] and p['sourceBranch'] == f['steps'], 'portion expectation mismatch')
        expected_id = '.'.join((p['sourceId'],p['fixtureId'],p['item'],p['proofKind']))
        require(p['id'] == expected_id, 'forged portion id')
        key = (p['fixtureId'],p['sourceId'],p['item'],p['profile'],p['proofKind'])
        require(key not in actual, 'duplicate portion assignment'); actual.add(key)
    expected = {(f['id'], source, a['item'], a['profile'], a['proofKind']) for f in fs.values() for source in f['sourceBranches'] for a in f['assignments']}
    require(actual == expected, 'missing or extra portion')
    pairs = {(p['item'], p['sourceId']) for p in ps.values()}
    for owner, sources in OWNERS.items():
        require(all((owner,s) in pairs for s in sources.split()), f'{owner}: missing normative portion')
    for ext, owners in EXT.items():
        require(all((owner,ext) in pairs for owner in owners), f'{ext}: missing owning portion')
    for fixture, terms in TRACE.items():
        require(fixture in fs, f'{fixture}: missing trace')
        observed = fs[fixture]['expected'].lower()
        require(all(term.lower() in observed for term in terms), f'{fixture}: missing expected trace')
    require(all(f['runtimeStatus'] == 'not-run' for f in fs.values()), 'fixture cannot claim runtime proof')
    return fs, ps

def validate_context_bytes(raw, schema, profiles, namespaces, extensions):
    require(len(raw) <= 16384, 'context byte limit')
    def pairs(values):
        out = {}
        for key, value in values:
            require(key not in out, f'duplicate key: {key}')
            out[key] = value
        return out
    def depth(value):
        if isinstance(value, dict): return 1 + max((depth(x) for x in value.values()), default=0)
        if isinstance(value, list): return 1 + max((depth(x) for x in value), default=0)
        return 0
    doc = json.loads(raw.decode('utf-8'), object_pairs_hook=pairs, parse_constant=lambda x: (_ for _ in ()).throw(ContractError('invalid constant')))
    require(depth(doc) <= 4, 'context depth limit')
    validate_context(doc, schema, profiles, namespaces, extensions)


def validate_context(doc, schema, profiles, namespaces, extensions):
    schema_validate(schema, doc, 'context')
    p = profiles.get(doc['profileId']); require(p is not None and p['version'] == doc['profileVersion'] and doc['mode'] in p['modes'], 'profile/version/mode mismatch')
    if doc['mode'] != 'central':
        require(doc['namespace'] in namespaces, 'unregistered namespace')
        identity = doc['isolationId']; require(unicodedata.normalize('NFC',identity) == identity and len(identity.encode('utf-8')) <= 96, 'identity NFC/byte limit')
        require('://' not in doc['resourceRef'], 'resourceRef must not carry a DSN')
    seen = set()
    for ext in doc['extensions']:
        require(ext['type'] not in seen, 'duplicate extension'); seen.add(ext['type'])
        registration = extensions.get(ext['type'])
        if registration is None:
            require(not ext['required'], 'unknown required extension'); continue
        require(ext['version'] == registration['version'] and set(ext['refs']) == set(registration['refs']), 'extension version/ref mismatch')

def validate_results(ledger, schema, index, ps, profiles, evidence_root, item=None, profile=None):
    schema_validate(schema, ledger, 'results')
    for key, path in [('corpusHash', FIXTURES/'index.json'),('indexSchemaHash',FIXTURES/'index.schema.json'),('resultSchemaHash',FIXTURES/'results.schema.json')]:
        require(ledger[key] == digest(path), f'{key} mismatch')
    seen = set()
    for row in ledger['results']:
        p = ps.get(row['portionId']); require(p is not None, 'unknown portion')
        require(row['portionId'] not in seen, 'duplicate portion result'); seen.add(row['portionId'])
        for result_key, portion_key in [('fixtureId','fixtureId'),('producerItem','item'),('profile','profile'),('proofKind','proofKind')]:
            require(row[result_key] == p[portion_key], f'{result_key} mismatch')
        require(row['lane'] in profiles[p['profile']]['lanes'], 'profile lane mismatch')
        require(row['expected'] == p['expectedObservations'], 'expected observation mismatch')
        require(row['applicability']['status'] == p['applicability']['status'] or (p['applicability']['status'] == 'conditional' and row['applicability']['status'] == 'not-applicable'), 'applicability mismatch')
        if row['status'] in ('passed','failed'):
            require(row['artifactHashes'] == ledger['candidate']['artifactHashes'], 'candidate artifact hashes mismatch')
            raw = row['rawEvidence']; require(not Path(raw).is_absolute(), 'evidence must be relative to evidence root')
            root = Path(evidence_root).resolve()
            if raw.startswith('@plan/'): raw = raw[len('@plan/'):]
            require(not raw.startswith('@'), 'unknown evidence locator')
            path = (root / raw).resolve()
            require(path.is_relative_to(root) and path.is_file() and path.stat().st_size > 0, 'missing/empty raw evidence')
            require(not raw.endswith(('.schema.json','index.json')), 'declaration is not runtime evidence')
            content = path.read_bytes()
            require(bool(content.strip()), 'empty raw evidence')
            require(hashlib.sha256(content).hexdigest() not in {digest(FIXTURES / name) for name in ('index.json','index.schema.json','results.schema.json')}, 'corpus declaration is not runtime evidence')
            try:
                evidence_doc = json.loads(content)
            except (ValueError, UnicodeDecodeError):
                evidence_doc = None
            if isinstance(evidence_doc, dict):
                require(evidence_doc.get('proofKind') not in ('contract','synthetic') or p['proofKind'] in ('contract','synthetic'), 'synthetic evidence cannot establish real proof')
                require(evidence_doc.get('contract') not in ('tenant.fixture-index/1','tenant.fixture-results/1'), 'declaration is not runtime evidence')
    if item is not None:
        required = {p['id'] for p in ps.values() if p['item'] == item and (profile is None or p['profile'] == profile)}
        require(bool(required), 'empty result scope')
        require(seen == required, 'missing or extra scoped portion results')
    return seen

def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--all', action='store_true'); ap.add_argument('--results', type=Path); ap.add_argument('--evidence-root', type=Path, default=ROOT); ap.add_argument('--item'); ap.add_argument('--profile'); ap.add_argument('--context', type=Path); ap.add_argument('--registry', type=Path)
    args = ap.parse_args(); require(args.all or args.results or args.context, 'select --all, --results or --context')
    schemas = [parse(CONTRACTS/'tenant.context.v1.schema.json'), parse(FIXTURES/'index.schema.json'), parse(FIXTURES/'results.schema.json')]
    for schema in schemas: Draft202012Validator.check_schema(schema)
    profiles = validate_manifests(load_contracts()); index = parse(FIXTURES/'index.json')
    fs, ps = validate_index(index, schemas[1], profiles)
    if args.context:
        require(args.registry is not None, '--context requires an explicit host registry')
        registry = parse(args.registry); validate_context_bytes(args.context.read_bytes(), schemas[0], profiles, set(registry['namespaces']), registry['extensions'])
        print('context shape and registry checks OK; origin/admission/physical verification remain host duties')
    if args.results:
        require(args.item is not None, '--results requires --item for completeness')
        seen = validate_results(parse(args.results), schemas[2], index, ps, profiles, args.evidence_root, args.item, args.profile)
        print(f'results validated: {len(seen)}; evidence truth requires owning runtime checks')
    if args.all:
        example = {'schema':'tenant.context/1','mode':'tenant','profileId':'php-scoped','profileVersion':1,'namespace':'example','isolationId':'A','resourceRef':'resource:A','incarnation':'i1','routeRevision':'r1','extensions':[]}
        validate_context(example, schemas[0], profiles, {'example'}, {})
        for field, value in [('mode','missing'),('incarnation',None),('profileVersion',2),('namespace','untrusted')]:
            bad = dict(example); bad[field] = value
            try: validate_context(bad, schemas[0], profiles, {'example'}, {})
            except ContractError: continue
            raise ContractError(f'negative context accepted: {field}')
        # Exercise result shape with a non-executed declaration and reject a fabricated pass.
        portion = next(iter(ps.values()))
        row = {'portionId':portion['id'], 'fixtureId':portion['fixtureId'], 'producerItem':portion['item'], 'profile':portion['profile'], 'lane':profiles[portion['profile']]['lanes'][0], 'proofKind':portion['proofKind'], 'applicability':portion['applicability'], 'check':{'procedure':'not yet executed','result':None}, 'expected':portion['expectedObservations'], 'observed':None, 'status':'not-run', 'rawEvidence':None}
        sample = {'contract':'tenant.fixture-results/1','corpusHash':digest(FIXTURES/'index.json'),'indexSchemaHash':digest(FIXTURES/'index.schema.json'),'resultSchemaHash':digest(FIXTURES/'results.schema.json'),'candidate':{'checkout':'shape-only','dirtyContentHash':'a'*64,'artifactHashes':{'candidate':'b'*64}},'results':[row]}
        schema_validate(schemas[2], sample, 'result example')
        row['status'] = 'passed'
        require(bool(list(Draft202012Validator(schemas[2]).iter_errors(sample))), 'result schema accepts pass without observation/evidence')
        print(f'metaschemas and frozen manifests OK; {len(fs)} fixtures, {len(ps)} portions, 38 N/TC and 6 EXT IDs; runtime/worker/DDL not-run')

if __name__ == '__main__':
    try: main()
    except (ContractError, KeyError, TypeError, ValueError, OSError) as exc:
        print(f'contract validation failed: {exc}', file=sys.stderr); sys.exit(1)
