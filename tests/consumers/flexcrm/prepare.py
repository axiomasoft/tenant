"""Build immutable P5.3 package archives and fresh actual app consumers from the bound cohorts."""
import pathlib,json,zipfile,hashlib,subprocess,tarfile,io,shutil,os,time,datetime
ROOT=pathlib.Path(__file__).resolve().parents[3]
PLAN=next((ROOT/'plans').glob('*TENANT')).resolve();ART=PLAN/'artifacts/P5.3'
manifest=json.loads((ART/'checkout-manifest.json').read_text());CHECKOUT=pathlib.Path(manifest['checkout']);assert CHECKOUT.is_absolute() and CHECKOUT.resolve()!=pathlib.Path(manifest['original']).resolve() and not CHECKOUT.is_symlink()
assert pathlib.Path(subprocess.check_output(['git','rev-parse','--show-toplevel'],cwd=CHECKOUT,text=True).strip()).resolve()==CHECKOUT.resolve()
records=[]
def sha(p):return hashlib.sha256(pathlib.Path(p).read_bytes()).hexdigest()
def save(p,d):p.parent.mkdir(parents=True,exist_ok=True);p.write_text(json.dumps(d,ensure_ascii=False,indent=2)+'\n')
def run(args,cwd,raw):
 start=datetime.datetime.now(datetime.timezone.utc).isoformat();t=time.monotonic();q=subprocess.run(args,cwd=cwd,stdout=subprocess.PIPE,stderr=subprocess.STDOUT);p=ART/raw;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(q.stdout)
 records.append({'argv':args,'cwd':str(cwd),'started_at':start,'duration':time.monotonic()-t,'exit':q.returncode,'raw':str(p.relative_to(PLAN)),'sha256':sha(p)})
 save(ART/'prepare-commands.json',records);assert q.returncode==0,(args,q.stdout.decode()[-2500:]);print(raw,'passed',flush=True)
# Source tooling is only applied to the owning diff, preserving all foreign paths.
owned=json.loads((ART/'owning-paths-development.json').read_text());php=[str(CHECKOUT/p) for p in owned if p.endswith('.php') and (CHECKOUT/p).is_file()]
run(['/usr/bin/php8.5',str(CHECKOUT/'vendor/bin/pint'),*php],CHECKOUT,'final-pint-format.log')
corex=pathlib.Path(json.loads((PLAN/'artifacts/P5.1/consumer-manifest.json').read_text())['checkouts']['corex'])
vaulter=pathlib.Path(json.loads((PLAN/'artifacts/P5.2/consumer-manifest.json').read_text())['sources']['vaulter']['checkout'])
az=CHECKOUT.parent/'azguard-0.3';assert (az/'packages/core/composer.json').is_file()
roots={'tenant':ROOT/'packages/tenant','tenant-laravel':ROOT/'packages/tenant-laravel',**{f'corex-{k}':corex/'packages'/k for k in ['core','tenant','tenancy','operations','tasks','intl']},'flex-crm':corex/'products/flex-crm',**{f'flexcrm-{k}':CHECKOUT/'packages/flexcrm'/k for k in ['auth','crm','tasks']},'vaulter-core':vaulter/'packages/core',**{f'azguard-{k}':az/'packages'/k for k in ['core','filament']}}
archives={};dist=CHECKOUT/'delivery/tenant-p53/artifacts';dist.mkdir(parents=True,exist_ok=True)
for key,source in roots.items():
 target=ART/'archives'/(key+'.zip');target.parent.mkdir(exist_ok=True);files={}
 with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED) as archive:
  for path in sorted(source.rglob('*')):
   rel=str(path.relative_to(source))
   if not path.is_file() or any(p in path.relative_to(source).parts for p in ['vendor','node_modules','tests','.git']):continue
   data=path.read_bytes()
   if rel=='composer.json':
    package=json.loads(data);package['version']='0.3.0' if key.startswith('azguard-') else '1.0.0';package.pop('repositories',None);data=json.dumps(package,indent=4).encode();name=package['name']
   files[rel]=hashlib.sha256(data).hexdigest();info=zipfile.ZipInfo(key+'/'+rel,(2020,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;archive.writestr(info,data)
 shutil.copy2(target,dist/target.name);archives[key]={'path':str(target),'name':name,'source':str(source),'sha256':sha(target),'files':files}
save(ART/'archives.json',archives)
# --lock refreshes artifact checksums without changing dependency versions.
shutil.copy2(CHECKOUT/'composer.lock',ART/'development-lock-before-final-refresh.json')
run(['/usr/bin/php8.5','/usr/local/bin/composer','update','--lock','--no-install','--no-scripts','--no-audit','--no-interaction'],CHECKOUT,'final-lock-refresh.log')
assert (CHECKOUT/'vendor').is_dir() and not (CHECKOUT/'vendor').is_symlink();shutil.rmtree(CHECKOUT/'vendor')
run(['/usr/bin/php8.5','/usr/local/bin/composer','install','--no-scripts','--no-interaction','--prefer-dist'],CHECKOUT,'final-development-install.log')
for p in (CHECKOUT/'bootstrap/cache').glob('*.php'):p.unlink()
hosts={}
for lane in ['php85-l13','php85-l13-repeat']:
 host=ART/'hosts'/lane
 assert not host.exists(),('Fresh consumer directory already exists',str(host))
 def ignore(directory,names):
  excluded={n for n in names if n in ['.git','vendor','node_modules','.env']}
  if pathlib.Path(directory)==CHECKOUT/'bootstrap/cache':excluded.update(n for n in names if n.endswith('.php'))
  return excluded
 shutil.copytree(CHECKOUT,host,ignore=ignore,symlinks=True)
 run(['/usr/bin/php8.5','/usr/local/bin/composer','install','--no-dev','--no-scripts','--no-interaction','--prefer-dist'],host,'fresh-'+lane+'-install.log')
 hosts[lane]={'checkout':str(host),'composerSha256':sha(host/'composer.json'),'lockSha256':sha(host/'composer.lock'),'devDependencies':False,'packages':len(json.loads((host/'vendor/composer/installed.json').read_text())['packages'])}
save(ART/'hosts.json',hosts)
print('prepared',len(archives),'archives and two fresh no-dev app installs',flush=True)
