from pathlib import Path

asset = next(Path('panel/public/assets/admin/assets').glob('index-*.js'))
source = asset.read_text()
start = source.index('function r3t(')
end = source.index('function s3t(', start)
toolbar = source[start:end]
needle = ',i&&Q.jsxs(Lf,{variant:"ghost",onClick:()=>e.resetColumnFilters()'
old_button = ',Q.jsx(Lf,{variant:"outline",size:"sm",className:"h-9",disabled:e.getFilteredSelectedRowModel().rows.length===0,onClick:()=>window.DBoardMachineUpgrade?.open(e.getFilteredSelectedRowModel().rows.map(e=>e.original)),children:"升级节点后端"})'
button = ',Q.jsx(Lf,{variant:"outline",size:"sm",className:"h-9",onClick:()=>window.DBoardMachineUpgrade?.open(),children:"升级节点后端"})'
if old_button in toolbar:
    toolbar = toolbar.replace(old_button, button, 1)
    source = source[:start] + toolbar + source[end:]
    asset.write_text(source)

if button not in toolbar:
    if toolbar.count(needle) != 1:
        raise SystemExit('Cannot locate unique server management toolbar insertion point')
    toolbar = toolbar.replace(needle, button + needle, 1)
    source = source[:start] + toolbar + source[end:]
    asset.write_text(source)
