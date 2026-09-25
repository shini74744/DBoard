#!/usr/bin/env python3
"""Run the real installer in a temporary filesystem with systemd/network doubles."""
import hashlib, json, os, pathlib, subprocess, tempfile
SOURCE=pathlib.Path(__file__).with_name('install-agent.sh').read_text()
MOCK=r'''#!/usr/bin/env python3
import json,os,pathlib,shutil,sys
r=pathlib.Path(os.environ['FIXTURE']);args=sys.argv[1:];name=pathlib.Path(sys.argv[0]).name
if name=='systemctl':
 state=json.loads((r/'state.json').read_text())
 with (r/'calls').open('a') as f:f.write(' '.join(args)+'\n')
 op=args[0];unit=args[-1]
 if op=='is-active':sys.exit(0 if state.get(unit,False) else 3)
 if op=='is-enabled':sys.exit(1)
 if op in ('stop','start','restart'):state[unit]=op!='stop'
 (r/'state.json').write_text(json.dumps(state));sys.exit(0)
if name=='sleep':sys.exit(0)
if name=='curl':
 out=args[args.index('-o')+1]
 src='SHA256SUMS' if any(x.endswith('/SHA256SUMS') for x in args) else 'asset'
 shutil.copyfile(r/src,out);sys.exit(0)
if '-v' in args:print('Integrated Nezha Agent v0.2.0');sys.exit(0)
if '--health-check' in args:sys.exit(0 if os.environ['HEALTHY']=='1' else 1)
if '--enroll' in args:
 p=pathlib.Path(args[args.index('-c')+1]);p.write_text('new integrated configuration')
 sys.exit(0)
raise SystemExit('unexpected mock call')
'''
for healthy in (True,False):
 with tempfile.TemporaryDirectory() as tmp:
  root=pathlib.Path(tmp)
  for d in ('usr/local/bin','etc/systemd/system','etc/nezha-agent','etc/DBoard-node','var/lib','run/lock','mocks'):
   (root/d).mkdir(parents=True,exist_ok=True)
  originals={'etc/nezha-agent/config.yml':b'original secret','etc/systemd/system/nezha-agent.service':b'original service','usr/local/bin/nezha-agent':b'original agent'}
  for p,b in originals.items():(root/p).write_bytes(b)
  (root/'etc/DBoard-node/config.yml').write_text('legacy configuration')
  (root/'state.json').write_text(json.dumps({'nezha-agent.service':True,'DBoard-node.service':True}))
  (root/'asset').write_text(MOCK)
  digest=hashlib.sha256((root/'asset').read_bytes()).hexdigest()
  (root/'SHA256SUMS').write_text(digest+'  nezha-agent-linux-amd64\n')
  for name in ('systemctl','curl','sleep'):
   p=root/'mocks'/name;p.write_text(MOCK);p.chmod(0o755)
  source=SOURCE
  for prefix in ('/usr/local/bin','/etc/','/var/lib/','/run/lock/'):
   source=source.replace(prefix,str(root)+prefix)
  script=root/'installer.sh';script.write_text(source)
  env=dict(os.environ,FIXTURE=tmp,HEALTHY='1' if healthy else '0',PATH=str(root/'mocks')+':'+os.environ['PATH'])
  run=subprocess.run(['bash',str(script),'--endpoint','https://probe.example.test','--uuid','c9a2215c-a675-448e-b6ac-c96dd420a79b','--enrollment','a'*48,'--version','v0.2.0','--takeover'],env=env,capture_output=True,text=True)
  assert (run.returncode==0)==healthy,run.stdout+run.stderr
  state=json.loads((root/'state.json').read_text())
  assert state['nezha-agent.service'] is True
  assert state['DBoard-node.service'] is (not healthy)
  for p,b in originals.items():assert (root/p).read_bytes()==b,p
  assert all('nezha-agent.service' not in x.split() for x in (root/'calls').read_text().splitlines())
  assert (root/'etc/systemd/system/nezha-integrated-agent.service').exists()==healthy
  assert (root/'etc/nezha-integrated-agent/config.json').exists()==healthy
  print('PASS stock Nezha preserved; integrated '+('success' if healthy else 'rollback'))
