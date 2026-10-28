import json,os,pathlib,subprocess,socket,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,argparse
p=argparse.ArgumentParser();p.add_argument('--output',required=True);args=p.parse_args();root=pathlib.Path(__file__).resolve().parent
with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
env=dict(os.environ);log=pathlib.Path(args.output);log.parent.mkdir(parents=True,exist_ok=True);serverlog=log.with_suffix('.server.log');responses=[]
def check(ok,what):
 if not ok:raise AssertionError(what)
 print('PASS '+what,flush=True)
with serverlog.open('w') as stream:
 proc=subprocess.Popen(['/usr/bin/php8.5','-S',f'127.0.0.1:{port}',str(root/'http-front.php')],env=env,stdout=stream,stderr=stream)
 try:
  for _ in range(80):
   try:
    with socket.create_connection(('127.0.0.1',port),.1):break
   except OSError:time.sleep(.05)
  else:raise RuntimeError('HTTP server did not start')
  jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
  def request(path,method='GET',data=None,host='tenant-a.test'):
   req=urllib.request.Request(f'http://127.0.0.1:{port}'+path,method=method,data=json.dumps(data).encode() if data is not None else None,headers={'Host':host,'Accept':'application/json','Content-Type':'application/json','Origin':'http://localhost:3000','Referer':'http://localhost:3000/'})
   token=next((c.value for c in jar if c.name=='XSRF-TOKEN'),None)
   if token:req.add_header('X-XSRF-TOKEN',urllib.parse.unquote(token))
   try:
    with opener.open(req,timeout=20) as r:code=r.status;body=r.read().decode()
   except urllib.error.HTTPError as e:code=e.code;body=e.read().decode()
   try:value=json.loads(body) if body else {}
   except json.JSONDecodeError:value={'body':body[:500]}
   responses.append({'path':path,'method':method,'host':host,'status':code,'body':value});log.with_suffix('.responses.json').write_text(json.dumps(responses,indent=2)+'\n');return code,value
  check(request('/sanctum/csrf-cookie')[0]==204,'HTTP actual app boot and session cookie')
  check(request('/login','POST',{'email':'actor-a@example.test','password':'fixture-secret'})[0] in [200,204],'HTTP actual Fortify authenticated A')
  status,body=request('/api/v1/auth/me');check(status==200 and body['data']['workspace']['id']=='00000000-0000-4000-8000-000000000010','HTTP initial UX active-default is separate from command admission')
  check(request('/_tenant/selected/a-w')[0]==200,'HTTP actual workspace route membership')
  check(request('/_tenant/command/a-w','POST',{'id':'00000000-0000-4000-8000-000000000060'})[0]==200,'HTTP authenticated actual workspace command')
  check(request('/_tenant/selected/a-x')[1]['rows']==[],'HTTP X cannot see W effect')
  check(request('/_tenant/selected/a-w')[1]['rows']==['00000000-0000-4000-8000-000000000060'],'HTTP W sees exact own effect')
  check(request('/api/v1/auth/me',host='tenant-b.test')[0]==401,'HTTP A session and auth do not cross into B')
  check(request('/_tenant/termination')[0]==200,'HTTP actual Kernel terminate inside owning A frame')
  check(request('/_tenant/revoke','POST',{})[0]==200,'HTTP revoke selected membership fixture')
  status,body=request('/api/v1/auth/me');check(status==403 and body['error']['code']=='selection_lost','HTTP revoked explicit selection is selection_lost no fallback')
  check(request('/_tenant/command/a-w','POST',{'id':'00000000-0000-4000-8000-000000000061'})[0]==403,'HTTP revoked command denied before write')
  check(request('/api/v1/auth/me',host='untrusted.test')[0]==403,'HTTP unknown host denied before session or tenant IO')
  time.sleep(.05);check(serverlog.read_text().count('CLEANUP missing workspace=null')>=12,'HTTP session save terminate auth and product cleanup observed')
  log.with_suffix('.responses.json').write_text(json.dumps(responses,indent=2)+'\n');print('COMPLETE real FlexCRM HTTP '+env.get('FLEXCRM_SESSION_DRIVER','file'),flush=True)
 finally:proc.terminate();proc.wait(timeout=10)
