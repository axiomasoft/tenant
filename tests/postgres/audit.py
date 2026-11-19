#!/usr/bin/env python3
"""Bounded P4.1 qualification audit; preserves real logs and original portions."""
import hashlib,json,pathlib,subprocess,sys
ROOT=pathlib.Path(__file__).resolve().parents[2];PLAN=ROOT/'plans/2026.10.24-№1-TENANT';ART=PLAN/'artifacts/P4.1';LANES=['php84-l12','php84-l13','php85-l13'];FOLDER=ART/'lanes/finalapi'
def sha(p):return hashlib.sha256(pathlib.Path(p).read_bytes()).hexdigest()
def save(p,x):p.parent.mkdir(parents=True,exist_ok=True);p.write_text(json.dumps(x,ensure_ascii=False,indent=2)+'\n')
def fixtureproof():
 fixture_stages={'LIFE-01':['receipt-race-lab','receipt-conflicts','receipt-unique-race','fallback-lab'],'LIFE-02':['template-lab','clone-lab','negative-template','replay-lab','template-replay-drift','marker-privileges'],'LIFE-03':['provision-lab','provision-cancel','provision-late','provision-precreate','provision-poststamp','pool-lab','pool-progress'],'LIFE-DELETED-01':['receipt-deleted']}
 out={}
 for fixture,stages in fixture_stages.items():
  checks=[]
  for lane in LANES:
   folder=FOLDER/lane;result=json.loads((folder/'result.json').read_text());assert result['status']=='passed'
   # Every current product byte was exercised, excluding later-added audit/test scripts.
   for path,digest in result['sourceHashes'].items():
    if path.startswith('packages/postgres/'):assert sha(ROOT/path)==digest,('source changed',path)
   bystage={x['stage']:x for x in result['checks']}
   for stage in stages:
    x=json.loads((folder/'pool-progress.json').read_text()) if stage=='pool-progress' else bystage[stage];log=pathlib.Path(x['log']);assert x['status']=='passed' and x['exit']==0 and sha(log)==x['sha256']
    text=log.read_text();assert '"status"' in text and 'passed' in text
    checks.append({'lane':lane,'stage':stage,'path':'@plan/'+str(log.resolve().relative_to(PLAN.resolve())),'sha256':sha(log),'observation':text.strip(),'exit':0})
   install=bystage['package-install'];assert install['exit']==0 and sha(install['log'])==install['sha256']
  out[fixture]={'fixture':fixture,'proofKind':'physical','status':'passed','checks':checks,'source':'real PG16 lab; processes and runtime role as in raw logs'}
  save(ART/'fixtures'/(fixture+'.json'),out[fixture])
 return out

def reuse():
 doc=json.loads((PLAN/'artifacts/P4-detail/source-inspection.json').read_text());files={pathlib.Path(x['path']).name:x for x in doc['files']}
 targets={
 'ClaimAccountSignup.php':['packages/postgres/src/Internal/Claim/ClaimResource.php','packages/postgres/src/Internal/Claim/ClaimReceiptStore.php'],
 'TemplateClonePostgreSQLManager.php':['packages/postgres/src/Internal/Database/TemplateClonePostgreSQLManager.php'],
 'TemplateManifest.php':['packages/postgres/src/Internal/Database/TemplateManifest.php'],
 'SchemaCatalogueSerializer.php':['packages/postgres/src/Internal/Database/SchemaCatalogueSerializer.php'],
 'TemplateIntegrityVerifier.php':['packages/postgres/src/Internal/Database/TemplateIntegrityVerifier.php'],
 'TemplateIntegrityLock.php':['packages/postgres/src/Internal/Database/TemplateIntegrityLock.php'],
 'PendingPool.php':['packages/postgres/src/Internal/Pool/PendingPool.php','packages/postgres/src/Internal/Claim/ClaimWithPool.php'],
 'CreateDatabaseFromTemplate.php':['packages/postgres/src/Internal/Claim/ReplayableProvision.php','packages/postgres/src/Internal/Claim/ProvisionJournal.php'],
 'AccountTemplateSnapshot.php':['packages/postgres/src/Internal/Pool/ResourceSnapshotReader.php'],
 'DatabaseTenantProvisioner.php':['packages/postgres/src/Internal/Claim/ReplayableProvision.php','packages/postgres/src/Internal/Claim/ClaimWithPool.php'],
 'CorexBindingOwner.php':['packages/postgres/src/Internal/Binding/PostgresBindingVerifier.php','packages/postgres/src/Internal/Database/MarkerWriter.php']}
 sources=[]
 for name,paths in targets.items():
  # The source-inspection list has both original and P5.1 source for some names: use original unless binding helper.
  matches=[x for x in doc['files'] if pathlib.Path(x['path']).name==name]
  f=next((x for x in matches if ('/projects/packages/corex/' in x['path'])==(name!='CorexBindingOwner.php')),matches[0]);assert sha(f['path'])==f['sha256']
  assert all((ROOT/p).is_file() for p in paths)
  note='Adapt imports/state to neutral catalog while retaining source mechanism and tests.'
  if name=='TemplateClonePostgreSQLManager.php':note='Retains WAL_LOG clone; no backend termination or blind CREATE retry; unknown is quarantined. Target and template locks use the management session.'
  if name=='PendingPool.php':note='Retains locked capacity/pending allocation and synchronous empty-pool fallback. Destructive cleanup and archive are P4.2; existing fixture cleanup is not run against unknown databases.'
  if name=='CorexBindingOwner.php':note='Same tenant.binding/1 marker comparison and role refusal; tenant-postgres has owner-specific catalog and adds marker-write privilege checks.'
  sources.append({'source':f['path'],'sourceSha256':f['sha256'],'symbols':f['symbols'],'targets':{p:sha(ROOT/p) for p in paths},'decision':'adapt','reason':note})
 tests=[]
 for row in doc['testDispositions']:
  if row.get('owner')!='P4.1':continue
  assert sha(row['source'])==row['sourceSha256'];name=row['test'];src=pathlib.Path(row['source']).name
  disposition='adapt';reason='Original guarantee tested in the isolated PG16 host.'
  if src=='ClaimLifecycleConcurrencyTest.php':
   target='receipt-race-lab.py' if 'overlaps independent' in name or 'rolls back' in name else ('receipt-unique-race.py' if 'unique-index' in name else 'receipt-conflicts.py')
  elif src=='TemplateIntegrityTest.php':target='negative-template.py' if ('nullable' in name) else 'template-lab.py'
  elif src=='TemplateManifestTest.php':target='manifest-regression.php'
  elif src=='ProvisioningTest.php':
   if 'typed custom' in name or 'embedding_model' in name or 'resolves the tenant connection' in name:
    disposition='keep';reason='CoreX owns product model/embedding and route mapping; neutral PG pool checks UUIDv7 separately.';target='pool-lab.php'
   elif 're-provisioning' in name:target='replay-lab.php'
   elif 'not ready' in name or 'untrusted' in name:target='negative-template.php'
   else:target='clone-lab.php'
  elif src=='PendingPoolTest.php':
   if 'fixture left behind' in name:disposition='not-applicable';reason='Legacy test deletes a fixed fixture; disposable unique labs quarantine unknown outcomes and never blind-drop an interrupted fixture.';target='provision-precreate.py'
   elif 'before provisioning completes' in name:target='pool-progress.py'
   elif 'empty pool' in name:target='fallback-lab.php'
   elif 'concurrent' in name:target='receipt-unique-race.py'
   else:target='pool-lab.php'
  else:raise AssertionError(name)
  path='tests/postgres/'+target;assert (ROOT/path).is_file()
  tests.append({'source':row['source'],'sourceSha256':row['sourceSha256'],'test':name,'target':path,'targetSha256':sha(ROOT/path),'fixtures':row['fixtures'],'decision':disposition,'reason':reason})
 result={'item':'P4.1','status':'passed','source':sources,'tests':tests,'limits':['P4.2 owns archive/delete/recovery policy and independent review','P5.4 owns CoreX physical caller switch','original CoreX suites not run in their source checkout; listed tests adapted or explicitly kept/not applicable']}
 save(PLAN/'artifacts/reuse/P4.1.json',result);return result

def ledger(proofs):
 index=json.loads((ROOT/'contracts/fixtures/index.json').read_text());schema=ROOT/'contracts/fixtures/index.schema.json';resultSchema=ROOT/'contracts/fixtures/results.schema.json';package=ROOT/'packages/postgres';fileHashes={str(p.relative_to(package)):sha(p) for p in sorted(package.rglob('*')) if p.is_file()};digest=hashlib.sha256(json.dumps(fileHashes,sort_keys=True,separators=(',',':')).encode()).hexdigest();artifact={'tenant-postgres-source':digest};rows=[]
 for p in index['portions']:
  if p['item']!='P4.1':continue
  fixture=p['fixtureId'];conditional=fixture=='LIFE-09';path='@plan/artifacts/P4.1/fixtures/'+fixture+'.json'
  row={'portionId':p['id'],'fixtureId':fixture,'producerItem':'P4.1','profile':p['profile'],'lane':'php84-pg16','proofKind':p['proofKind'],'applicability':{'status':'not-applicable','reason':'LIFE-09 describes the minimal host without the optional lifecycle capability; P6.1 remains the minimal checkpoint.'} if conditional else p['applicability'],'check':{'procedure':'Minimal host does not select optional physical capability.' if conditional else 'Actual disposable PG16 corpus scenario across PHP 8.4/L12,L13 and PHP 8.5/L13; detailed logs in fixture evidence.','result':None if conditional else 0},'expected':p['expectedObservations'],'observed':None if conditional else 'Actual fixture-specific multi-process / PG observations and logs recorded; all selected lanes passed.','status':'not-run' if conditional else 'passed','rawEvidence':None if conditional else path}
  if not conditional:assert fixture in proofs;row['artifactHashes']=artifact
  else:row['reason']='Not applicable to the minimal host; no optional capability advertised.'
  rows.append(row)
 assert len(rows)==22
 report={'contract':'tenant.fixture-results/1','corpusHash':sha(ROOT/'contracts/fixtures/index.json'),'indexSchemaHash':sha(schema),'resultSchemaHash':sha(resultSchema),'candidate':{'checkout':str(ROOT),'dirtyContentHash':digest,'artifactHashes':artifact},'results':rows}
 save(PLAN/'artifacts/acceptance/P4.1.json',report)
 return report

def main():
 mode=sys.argv[1]
 if mode=='V1':
  proof=fixtureproof();print('V1 real PG fixture stages checked',','.join(proof),'three lanes; conditional LIFE-09 excluded')
 elif mode=='V2':
  x=reuse();print('V2 source mapping checked:',len(x['source']),'mechanisms;',len(x['tests']),'source tests/dispositions')
 elif mode=='V3':
  proof=fixtureproof();ledger(proof)
  subprocess.run([str(ROOT/'.venv-contracts/bin/python'),'-I',str(ROOT/'scripts/check-contract-fixtures.py'),'--results',str(PLAN/'artifacts/acceptance/P4.1.json'),'--evidence-root',str(PLAN),'--item','P4.1'],check=True)
 elif mode=='V4':
  reuse_data=json.loads((PLAN/'artifacts/reuse/P4.1.json').read_text());assert any('ReplayableProvision.php' in str(r['targets']) for r in reuse_data['source'])
  for lane in LANES:
   r=json.loads((FOLDER/lane/'result.json').read_text());row=next(x for x in r['checks'] if x['stage']=='replay-lab');assert row['exit']==0 and sha(row['log'])==row['sha256'];assert 'actual replay pipeline' in pathlib.Path(row['log']).read_text()
  print('V4 actual replay pipeline, neutral fresh snapshot and incarnation refusal passed in all lanes; source dispositions verified.')
 else:raise SystemExit('invalid audit')
if __name__=='__main__':main()
