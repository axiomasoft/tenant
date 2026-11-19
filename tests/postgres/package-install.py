#!/usr/bin/env python3
"""Fresh standalone Composer installation; compare installed source bytes."""
import json,os,pathlib,subprocess,hashlib,sys
root=pathlib.Path(__file__).resolve().parents[2];folder=pathlib.Path(os.environ['P41_INSTALL_DIR']);php=os.environ['P41_PHP'];major=os.environ['P41_LARAVEL_MAJOR'];assert major in ('12','13')
if folder.exists():raise SystemExit('Existing host; refuse overwrite')
folder.mkdir(parents=True)
repos=[]
sources={'tenant': root/'packages/core', 'tenant-postgres': root/'packages/postgres'}
for name, source in sources.items():
 repos.append({'type':'path','url':str(source),'options':{'symlink':False,'versions':{'axiomasoft/'+name:'1.0.0'}}})
config={'name':'tenant/pg-qualification-host','type':'project','repositories':repos,'require':{'php':'>=8.4 <8.6','axiomasoft/tenant-postgres':'1.0.0','illuminate/database':'^'+major+'.0'},'config':{'allow-plugins':False,'sort-packages':True}}
(folder/'composer.json').write_text(json.dumps(config,indent=2)+'\n')
p=subprocess.run([php,'/usr/local/bin/composer','update','--no-dev','--no-interaction','--prefer-dist'],cwd=folder,stdout=subprocess.PIPE,stderr=subprocess.STDOUT);(folder.parent/'composer-install.log').write_bytes(p.stdout)
if p.returncode:sys.stdout.buffer.write(p.stdout);raise SystemExit(p.returncode)
for package, source in sources.items():
 installed=folder/'vendor/axiomasoft'/package
 for path in source.rglob('*'):
  if path.is_file():
   target=installed/path.relative_to(source)
   if not target.is_file() or hashlib.sha256(path.read_bytes()).digest()!=hashlib.sha256(target.read_bytes()).digest():raise RuntimeError('Installed source mismatch: '+str(path))
code="require 'vendor/autoload.php'; if (!class_exists('Tenant\\\\Postgres\\\\Internal\\\\ClaimReceiptStore') || !class_exists('Tenant\\\\Postgres\\\\Internal\\\\PostgresBindingVerifier') || !class_exists('Tenant\\\\Postgres\\\\ProvisioningOwner') || !interface_exists('Tenant\\\\Postgres\\\\PostgresLifecycle')) exit(2); echo Composer\\\\InstalledVersions::getPrettyVersion('illuminate/database');".replace('\\\\','\\')
version=subprocess.check_output([php,'-r',code],cwd=folder,text=True)
print(json.dumps({'status':'passed','scope':'fresh standalone install, actual autoload and installed source bytes','illuminate':version,'composerLockHash':hashlib.sha256((folder/'composer.lock').read_bytes()).hexdigest()}))
