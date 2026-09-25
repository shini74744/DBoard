#!/usr/bin/env python3
"""Fetch upstream frontend releases pinned by Nezha, with a committed digest lock."""
import argparse, hashlib, io, json, re, urllib.request, zipfile
from pathlib import Path
root=Path(__file__).resolve().parent
parser=argparse.ArgumentParser()
parser.add_argument("--update-lock",action="store_true",help="Explicitly accept new upstream release digests")
args=parser.parse_args()
lock_path=root/"frontend-assets.lock.json"
lock=json.loads(lock_path.read_text()) if lock_path.exists() else {}
text=(root/"dashboard/service/singleton/frontend-templates.yaml").read_text()
entries=[]
for block in re.split(r"(?m)^- path:",text)[1:]:
 name=re.match(r'\s*"([^"]+)"',block).group(1)
 repo=re.search(r'repository:\s*"([^"]+)"',block).group(1)
 version=re.search(r'version:\s*"([^"]+)"',block).group(1)
 entries.append((name,repo+"/releases/download/"+version+"/dist.zip"))
for name,url in entries:
 target=root/"dashboard/cmd/dashboard"/name
 marker=target/".source-sha256"
 expected=lock.get(name,{})
 if not args.update_lock and expected.get("url")!=url:
  raise SystemExit("Frontend pin changed: review upstream and use --update-lock")
 if not args.update_lock and marker.exists() and marker.read_text().strip()==expected.get("sha256") and (target/"index.html").exists():
  continue
 with urllib.request.urlopen(url,timeout=60) as response:
  archive=response.read(64*1024*1024+1)
 if len(archive)>64*1024*1024:raise SystemExit("Frontend archive is too large")
 digest=hashlib.sha256(archive).hexdigest()
 if not args.update_lock and expected.get("sha256")!=digest:
  raise SystemExit("Frontend digest mismatch: "+name)
 with zipfile.ZipFile(io.BytesIO(archive)) as z:
  for entry in z.infolist():
   if entry.is_dir():continue
   parts=Path(entry.filename).parts
   if not parts or parts[0]!="dist" or ".." in parts:raise SystemExit("Unexpected archive path")
   dest=target.joinpath(*parts[1:]);dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(z.read(entry))
 marker.write_text(digest+"\n")
 lock[name]={"url":url,"sha256":digest}
 print("Prepared",name,digest)
if args.update_lock:lock_path.write_text(json.dumps(lock,indent=2)+"\n")
