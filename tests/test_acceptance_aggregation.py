import copy, importlib.util, json, pathlib, runpy, tempfile, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
PLAN=next((ROOT/'plans').glob('*TENANT')).resolve()
spec=importlib.util.spec_from_file_location('acceptance',ROOT/'scripts/qualify-acceptance.py');acceptance=importlib.util.module_from_spec(spec);spec.loader.exec_module(acceptance)
v=runpy.run_path(str(ROOT/'scripts/check-contract-fixtures.py'))
profiles=v['validate_manifests'](v['load_contracts']());index=v['parse'](ROOT/'contracts/fixtures/index.json');_,portions=v['validate_index'](index,v['parse'](ROOT/'contracts/fixtures/index.schema.json'),profiles);schema=v['parse'](ROOT/'contracts/fixtures/results.schema.json')
ledger=json.loads((PLAN/'artifacts/acceptance/P6.1.json').read_text());scope=json.loads((PLAN/'artifacts/P6-detail/portion-map.json').read_text())['items']['P6.1']
def validate(x):return v['validate_results'](x,schema,index,portions,profiles,PLAN)
class AcceptanceNegativeTest(unittest.TestCase):
 def test_missing_original_portion(self):
  with self.assertRaises(ValueError):acceptance.check_scope(ledger['results'][:-1],scope)
 def test_duplicate_across_lanes(self):
  x=copy.deepcopy(ledger);r=copy.deepcopy(x['results'][0]);r['lane']='php85-cli';x['results'].append(r)
  with self.assertRaises(Exception):validate(x)
 def test_wrong_producer(self):
  x=copy.deepcopy(ledger);x['results'][0]['producerItem']='P3.1'
  with self.assertRaises(Exception):validate(x)
 def test_candidate_hash_mismatch(self):
  x=copy.deepcopy(ledger);x['results'][0]['artifactHashes'][next(iter(x['results'][0]['artifactHashes']))]='0'*64
  with self.assertRaises(Exception):validate(x)
 def test_stale_raw_hash(self):
  with self.assertRaises(ValueError):acceptance.raw(PLAN/'artifacts/P6.1/core-runs.json','0'*64)
 def test_foreign_evidence_escape(self):
  with self.assertRaises(ValueError):acceptance.raw('/etc/passwd')
 def test_synthetic_evidence_cannot_prove_io(self):
  x=copy.deepcopy(ledger);row=next(r for r in x['results'] if r['status']=='passed' and r['proofKind']=='IO')
  with tempfile.TemporaryDirectory(dir=PLAN/'artifacts/P6.1') as tmp:
   path=pathlib.Path(tmp)/'synthetic.json';path.write_text('{"proofKind":"synthetic"}')
   row['rawEvidence']='@plan/'+str(path.relative_to(PLAN))
   with self.assertRaises(Exception):validate(x)
if __name__=='__main__':unittest.main()
