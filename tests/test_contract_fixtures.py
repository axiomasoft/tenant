"""Adversarial examples authored separately from the validator's built-in cases."""
import copy
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('tenant_contracts', ROOT/'scripts/check-contract-fixtures.py')
v = importlib.util.module_from_spec(spec)
spec.loader.exec_module(v)

class Contracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.contracts = v.load_contracts()
        cls.profiles = v.validate_manifests(cls.contracts)
        cls.index = v.parse(v.FIXTURES/'index.json')
        cls.index_schema = v.parse(v.FIXTURES/'index.schema.json')
        cls.result_schema = v.parse(v.FIXTURES/'results.schema.json')
        cls.context_schema = v.parse(v.CONTRACTS/'tenant.context.v1.schema.json')
        cls.fs, cls.ps = v.validate_index(cls.index, cls.index_schema, cls.profiles)
        cls.context = {'schema':'tenant.context/1','mode':'tenant','profileId':'php-scoped','profileVersion':1,'namespace':'acme','isolationId':'42','resourceRef':'db:42','incarnation':'inc1','routeRevision':'rev1','extensions':[]}

    def context_check(self, c, registry=None):
        v.validate_context(c, self.context_schema, self.profiles, {'acme'}, registry or {})

    def test_valid_context_and_registered_extension(self):
        c = copy.deepcopy(self.context)
        c['extensions'] = [{'type':'acme:selection','version':1,'required':True,'refs':{'workspace':'w1'}}]
        self.context_check(c, {'acme:selection':{'version':1,'refs':['workspace']}})

    def test_malicious_contexts(self):
        for changes in [ {'mode':'missing'}, {'profileId':'laravel-pg-database','profileVersion':2}, {'namespace':'evil'}, {'incarnation':None}, {'resourceRef':'postgres://secret'}, {'isolationId':'e\u0301'}, {'isolationId':'é'*60}, {'class':'Injected'} ]:
            with self.subTest(changes=changes), self.assertRaises(v.ContractError):
                c = {**self.context, **changes}; self.context_check(c)

    def test_extension_forgery(self):
        registry = {'acme:selection':{'version':1,'refs':['workspace']}}
        for extensions in [
          [{'type':'evil:selection','version':1,'required':True,'refs':{'workspace':'w1'}}],
          [{'type':'acme:selection','version':2,'required':True,'refs':{'workspace':'w1'}}],
          [{'type':'acme:selection','version':1,'required':True,'refs':{'workspace':'w1','class':'evil'}}],
          [{'type':'acme:selection','version':1,'required':True,'refs':{'workspace':'w1'}}]*2,
        ]:
            with self.subTest(extensions=extensions), self.assertRaises(v.ContractError):
                self.context_check({**self.context,'extensions':extensions}, registry)

    def test_index_missing_and_unknown(self):
        for mutation in ('missing','unknown','profile','expected','ext'):
            d=copy.deepcopy(self.index)
            if mutation == 'missing': d['portions'].pop()
            elif mutation == 'unknown': d['portions'][0]['fixtureId']='FORGED'
            elif mutation == 'profile': d['portions'][0]['profile']='single'
            elif mutation == 'expected': d['portions'][0]['expectedObservations']='fake'
            else: d['fixtures']=[f for f in d['fixtures'] if f['id']!='EXT06-STREAM']
            with self.subTest(mutation=mutation), self.assertRaises(v.ContractError):
                v.validate_index(d,self.index_schema,self.profiles,check_frozen=False)

    def test_manifest_conflicts(self):
        for change in ('spi','physical','lane','reason'):
            d=copy.deepcopy(self.contracts)
            if change=='spi': d['api']['spiVersion']=2
            if change=='physical': d['ownership']['physicalOperations'][0]['writerByCheckpoint']['P5.1']='other'
            if change=='lane': d['profiles']['lanes']['php85-l13']['php']='8.4.x'
            if change=='reason': d['errors']['errors'].append(copy.deepcopy(d['errors']['errors'][0]))
            with self.subTest(change=change), self.assertRaises(v.ContractError): v.validate_manifests(d)

    def ledger(self):
        item='P2.1'; profiles='php-scoped'; rows=[]
        for p in self.ps.values():
            if p['item'] != item or p['profile'] != profiles: continue
            rows.append({'portionId':p['id'],'fixtureId':p['fixtureId'],'producerItem':item,'profile':profiles,'lane':'php84-cli','proofKind':p['proofKind'],'applicability':p['applicability'],'check':{'procedure':'pending','result':None},'expected':p['expectedObservations'],'observed':None,'status':'not-run','rawEvidence':None})
        hashes={'artifact':'a'*64}
        return {'contract':'tenant.fixture-results/1','corpusHash':v.digest(v.FIXTURES/'index.json'),'indexSchemaHash':v.digest(v.FIXTURES/'index.schema.json'),'resultSchemaHash':v.digest(v.FIXTURES/'results.schema.json'),'candidate':{'checkout':'test','dirtyContentHash':'b'*64,'artifactHashes':hashes},'results':rows}

    def check_ledger(self, data, root):
        return v.validate_results(data,self.result_schema,self.index,self.ps,self.profiles,root,'P2.1','php-scoped')

    def test_ledger_scope_and_negative_forgery(self):
        with tempfile.TemporaryDirectory() as tmp:
            good=self.ledger(); self.assertTrue(self.check_ledger(good,tmp))
            for change in ('missing','fixture','profile','hash','synthetic','empty','declaration','syntheticfile','artifact'):
                d=copy.deepcopy(good); row=d['results'][0]
                if change=='missing': d['results'].pop()
                elif change=='fixture': row['fixtureId']='UNKNOWN'
                elif change=='profile': row['profile']='single'
                elif change=='hash': d['corpusHash']='c'*64
                elif change=='synthetic': row['proofKind']='synthetic'; row.update(status='passed',observed='ok',rawEvidence='raw',artifactHashes={'artifact':'a'*64})
                else:
                    row.update(status='passed',observed='ok',rawEvidence='raw',artifactHashes={'artifact':'a'*64},check={'procedure':'real command','result':0})
                    if change=='empty': Path(tmp,'raw').write_text('')
                    elif change=='declaration': Path(tmp,'index.json').write_text('{}'); row['rawEvidence']='index.json'
                    elif change=='syntheticfile': Path(tmp,'raw').write_text('{"proofKind":"synthetic"}')
                    elif change=='artifact': Path(tmp,'raw').write_text('actual output'); row['artifactHashes']={'artifact':'c'*64}
                with self.subTest(change=change), self.assertRaises(v.ContractError): self.check_ledger(d,tmp)
            d=copy.deepcopy(good); row=d['results'][0]; Path(tmp,'raw').write_text('observed output')
            row.update(status='passed',observed='ok',rawEvidence='raw',artifactHashes={'artifact':'a'*64},check={'procedure':'real command','result':0})
            self.check_ledger(d,tmp)

    def test_duplicate_json_keys_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp,'bad.json'); p.write_text('{"mode":"tenant","mode":"central"}')
            with self.assertRaises(v.ContractError): v.parse(p)

if __name__ == '__main__': unittest.main()
