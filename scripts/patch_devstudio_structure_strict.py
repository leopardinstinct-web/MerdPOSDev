from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
p=ROOT/'namecheap_beta_live/timesheet_portal/assets/ui-studio-structure.js'
t=p.read_text(encoding='utf-8')
old="""    const explicit=explicitType(el);if(explicit)return explicit;\n    const component=componentType(el);if(component)return component;\n    if(parentType==='page')return 'section';\n"""
new="""    const explicit=explicitType(el);if(explicit)return explicit;\n    if(parentType==='page')return 'section';\n    const component=componentType(el);if(component)return component;\n"""
if old not in t: raise SystemExit('strict Page > Section anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')

spec=ROOT/'namecheap_beta_live/browser_tests/ui-studio-structure-runtime.spec.js'
s=spec.read_text(encoding='utf-8')
needle="""  expect(structure).toContain(\"page:['section']\");\n"""
insert="""  expect(structure).toContain(\"page:['section']\");\n  expect(structure.indexOf(\"if(parentType==='page')return 'section';\")).toBeLessThan(structure.indexOf('const component=componentType(el);'));\n"""
if needle not in s: raise SystemExit('structure spec anchor missing')
spec.write_text(s.replace(needle,insert,1),encoding='utf-8')
Path(__file__).unlink()
