"""Portable adversarial aggregation tests; fixtures never certify executed IO."""
import copy
import importlib.util
import json
from pathlib import Path
import runpy
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'scripts/qualify-acceptance.py'
spec = importlib.util.spec_from_file_location('acceptance', SCRIPT)
acceptance = importlib.util.module_from_spec(spec)
spec.loader.exec_module(acceptance)
validator = runpy.run_path(str(ROOT / 'scripts/check-contract-fixtures.py'))
PRODUCERS = {'P2.1', 'P2.2', 'P3.1', 'P3.2', 'P5.1', 'P5.2', 'P5.3'}


class AcceptanceNegativeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.profiles = validator['validate_manifests'](validator['load_contracts']())
        fixtures = ROOT / 'contracts/fixtures'
        cls.index = validator['parse'](fixtures / 'index.json')
        _, cls.portions = validator['validate_index'](
            cls.index, validator['parse'](fixtures / 'index.schema.json'), cls.profiles,
        )
        cls.schema = validator['parse'](fixtures / 'results.schema.json')
        cls.scope = [p for p in cls.portions.values() if p['item'] in PRODUCERS]

    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='tenant-acceptance-unit-')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.plan = self.root / 'unit-plan'
        self.evidence = self.plan / 'artifacts/unit-only-evidence.json'
        self.evidence.parent.mkdir(parents=True)
        self.evidence.write_text(json.dumps({
            'proofKind': 'synthetic',
            'purpose': 'Negative unit-test input; never actual acceptance evidence.',
        }))
        digest = acceptance.sha(self.evidence)
        fixtures = ROOT / 'contracts/fixtures'
        self.ledger = {
            'contract': 'tenant.fixture-results/1',
            'corpusHash': acceptance.sha(fixtures / 'index.json'),
            'indexSchemaHash': acceptance.sha(fixtures / 'index.schema.json'),
            'resultSchemaHash': acceptance.sha(fixtures / 'results.schema.json'),
            'candidate': {
                'checkout': str(self.plan), 'dirtyContentHash': digest,
                'artifactHashes': {'unit-test-input': digest},
            },
            'results': [{
                'portionId': p['id'], 'fixtureId': p['fixtureId'],
                'producerItem': p['item'], 'profile': p['profile'],
                'lane': self.profiles[p['profile']]['lanes'][0],
                'proofKind': p['proofKind'],
                'applicability': copy.deepcopy(p['applicability']),
                'check': {'procedure': 'Unit-test declaration; not executed.', 'result': None},
                'expected': p['expectedObservations'], 'observed': None,
                'status': 'not-run', 'rawEvidence': None,
            } for p in self.scope],
        }

    def validate(self, ledger):
        return validator['validate_results'](
            ledger, self.schema, self.index, self.portions, self.profiles, self.plan,
        )

    def forged_io_claim(self):
        """An intentionally invalid pass, used only to prove a specific refusal."""
        ledger = copy.deepcopy(self.ledger)
        row = next(r for r in ledger['results']
                   if r['proofKind'] == 'IO' and r['applicability']['status'] == 'required')
        row.update(
            status='passed', observed='Deliberately forged execution claim for a negative test.',
            check={'procedure': 'Unit-test forgery; no IO executed.', 'result': 0},
            rawEvidence=acceptance.raw(self.plan, self.evidence),
            artifactHashes=copy.deepcopy(ledger['candidate']['artifactHashes']),
        )
        return ledger, row

    def test_nonexecuted_fixture_matches_the_frozen_scope(self):
        self.assertEqual(self.validate(self.ledger), {p['id'] for p in self.scope})
        acceptance.check_scope(self.ledger['results'], self.scope)
        self.assertTrue(all(row['status'] == 'not-run' for row in self.ledger['results']))

    def test_missing_original_portion(self):
        with self.assertRaisesRegex(ValueError, 'missing, duplicate or foreign portion'):
            acceptance.check_scope(self.ledger['results'][:-1], self.scope)

    def test_duplicate_across_lanes(self):
        ledger = copy.deepcopy(self.ledger)
        row = copy.deepcopy(ledger['results'][0])
        row['lane'] = next(lane for lane in self.profiles[row['profile']]['lanes'] if lane != row['lane'])
        ledger['results'].append(row)
        with self.assertRaisesRegex(ValueError, 'duplicate portion result'):
            self.validate(ledger)

    def test_wrong_producer(self):
        ledger = copy.deepcopy(self.ledger)
        ledger['results'][0]['producerItem'] = 'P3.1'
        with self.assertRaisesRegex(ValueError, 'producerItem mismatch'):
            self.validate(ledger)

    def test_forged_portion_and_fixture(self):
        for field, message in [('portionId', 'unknown portion'), ('fixtureId', 'fixtureId mismatch')]:
            with self.subTest(field=field):
                ledger = copy.deepcopy(self.ledger)
                ledger['results'][0][field] = 'FORGED'
                with self.assertRaisesRegex(ValueError, message):
                    self.validate(ledger)

    def test_candidate_hash_mismatch(self):
        ledger, row = self.forged_io_claim()
        row['artifactHashes']['unit-test-input'] = '0' * 64
        with self.assertRaisesRegex(ValueError, 'candidate artifact hashes mismatch'):
            self.validate(ledger)

    def test_stale_raw_hash(self):
        with self.assertRaisesRegex(ValueError, 'evidence hash mismatch'):
            acceptance.raw(self.plan, self.evidence, '0' * 64)

    def test_missing_raw_evidence(self):
        with self.assertRaisesRegex(ValueError, 'evidence outside plan or missing'):
            acceptance.raw(self.plan, 'artifacts/missing.json')

    def test_foreign_evidence_escape(self):
        outside = self.root / 'outside.json'
        outside.write_text('outside the unit plan')
        for path in [outside, Path('../outside.json')]:
            with self.subTest(path=path), self.assertRaisesRegex(ValueError, 'evidence outside plan or missing'):
                acceptance.raw(self.plan, path)

    def test_synthetic_evidence_cannot_prove_io(self):
        ledger, _ = self.forged_io_claim()
        with self.assertRaisesRegex(ValueError, 'synthetic evidence cannot establish real proof'):
            self.validate(ledger)

    def test_import_does_not_discover_a_private_plan(self):
        with patch.object(Path, 'glob', side_effect=AssertionError('Import must not discover plans')):
            runpy.run_path(str(SCRIPT))

    def test_plan_discovery_requires_one_directory_or_an_explicit_choice(self):
        with self.assertRaisesRegex(ValueError, 'no acceptance plan found'):
            acceptance.discover_plan(root=self.root)
        first = self.root / 'plans/first-TENANT'
        first.mkdir(parents=True)
        self.assertEqual(acceptance.discover_plan(root=self.root), first.resolve())
        second = self.root / 'plans/second-TENANT'
        second.mkdir()
        with self.assertRaisesRegex(ValueError, 'multiple acceptance plans found'):
            acceptance.discover_plan(root=self.root)
        self.assertEqual(acceptance.discover_plan(second, root=self.root), second.resolve())

    def test_cli_help_needs_no_plan(self):
        result = subprocess.run([sys.executable, str(SCRIPT), '--help'], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('--plan', result.stdout)

    def test_cli_refuses_missing_plan_or_original_evidence_without_writing_results(self):
        output = self.plan / 'artifacts/P6.1/result.json'
        for plan, message in [
            (self.root / 'absent', 'acceptance plan directory is missing'),
            (self.plan, 'acceptance plan has no original producer evidence index'),
        ]:
            with self.subTest(plan=plan):
                result = subprocess.run([
                    sys.executable, str(SCRIPT), '--plan', str(plan),
                    '--item', 'P6.1', '--stage', 'ledger',
                    '--inputs', str(plan / 'artifacts/P6-detail/producer-evidence.json'),
                    '--output', str(output),
                ], capture_output=True, text=True)
                self.assertEqual(result.returncode, 2, result.stderr)
                self.assertIn(message, result.stderr)
                self.assertFalse(output.exists())


if __name__ == '__main__':
    unittest.main()
