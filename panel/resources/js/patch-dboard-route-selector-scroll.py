from pathlib import Path

asset = next(Path('panel/public/assets/admin/assets').glob('index-*.js'))
source = asset.read_text()
old = 'Q.jsx(Sut,{heading:e,className:"h-full overflow-auto",children:'
new = 'Q.jsx(Sut,{heading:e,children:'
if old in source:
    assert source.count(old) == 1
    asset.write_text(source.replace(old, new, 1))
elif new not in source:
    raise SystemExit('Cannot locate route user selector group')
