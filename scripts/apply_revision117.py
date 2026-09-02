from pathlib import Path

root = Path('.')
portal = root / 'namecheap_beta_live' / 'timesheet_portal'


def read(path):
    return Path(path).read_text(encoding='utf-8-sig')


def write(path, text):
    Path(path).write_text(text, encoding='utf-8')


def replace_once(text, old, new, label):
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected exactly 1 match, found {count}')
    return text.replace(old, new, 1)


# DevStudio palette: keep the protected five-color brand master, while exposing
# canonical semantic warning/danger/success tokens in the editable palette.
p = portal / 'assets' / 'ui-studio.js'
text = read(p)
old = """  const MERDPOS_PALETTE_DEFAULT=[
    {id:'white',token:'--color-brand-white',label:'White',value:'#FFFFFF'},
    {id:'background',token:'--color-brand-background',label:'Background',value:'#F5F7FC'},
    {id:'navy',token:'--color-brand-navy',label:'Navy',value:'#031B4B'},
    {id:'cyan',token:'--color-brand-cyan',label:'Cyan',value:'#12BDF3'},
    {id:'violet',token:'--color-brand-violet',label:'Violet',value:'#8B2EFF'}
  ];"""
new = """  const MERDPOS_PALETTE_DEFAULT=[
    {id:'white',token:'--color-brand-white',label:'White',value:'#FFFFFF'},
    {id:'background',token:'--color-brand-background',label:'Background',value:'#F5F7FC'},
    {id:'navy',token:'--color-brand-navy',label:'Navy',value:'#031B4B'},
    {id:'cyan',token:'--color-brand-cyan',label:'Cyan',value:'#12BDF3'},
    {id:'violet',token:'--color-brand-violet',label:'Violet',value:'#8B2EFF'},
    {id:'warning',token:'--color-warning',label:'Warning / Action',value:'#8A5300'},
    {id:'danger',token:'--color-danger',label:'Inactive / Danger',value:'#B42318'},
    {id:'success',token:'--color-success',label:'Active / Success',value:'#18794E'}
  ];"""
text = replace_once(text, old, new, 'ui-studio semantic palette defaults')
text = replace_once(
    text,
    "!/^--color-brand-[a-z0-9_-]{1,48}$/.test(token)",
    "!/^--color-(?:brand-[a-z0-9_-]{1,48}|success|warning|danger)$/.test(token)",
    'ui-studio palette token validation',
)
old_selector = "function selectorFor(el){if(el.id)return `#${cssEscape(el.id)}`;const unique=uniqueAttributeSelector(el);if(unique)return unique;const parts=[];let node=el;while(node&&node!==document.body&&parts.length<7){if(node.id){parts.unshift(`#${cssEscape(node.id)}`);break;}"
new_selector = "function selectorFor(el){const directId=String(el?.getAttribute?.('id')||'').trim();if(directId)return `#${cssEscape(directId)}`;const unique=uniqueAttributeSelector(el);if(unique)return unique;const parts=[];let node=el;while(node&&node!==document.body&&parts.length<7){const nodeId=String(node?.getAttribute?.('id')||'').trim();if(nodeId){parts.unshift(`#${cssEscape(nodeId)}`);break;}"
text = replace_once(text, old_selector, new_selector, 'ui-studio DOM-clobber-safe selector')
text = replace_once(
    text,
    "state.revealHidden=false;clearHover();if(wasRevealing)applyAll();",
    "state.revealHidden=false;clearHover();clearSelected();if(wasRevealing)applyAll();",
    'ui-studio deselect on hide',
)
write(p, text)

# Server-side palette validation mirrors the browser editor.
p = portal / 'includes' / 'ui_studio_history.php'
text = read(p)
text = replace_once(
    text,
    "!preg_match('/^--color-brand-[a-z0-9_-]{1,48}$/', $token)",
    "!preg_match('/^--color-(?:brand-[a-z0-9_-]{1,48}|success|warning|danger)$/', $token)",
    'server palette token validation',
)
write(p, text)

# Account/DevStudio shell: retain only the ChatGPT-copy metric, place it directly
# left of the DS toggle, synchronize settings across windows, and use supplied icon.
p = portal / 'assets' / 'account-menu.js'
text = read(p)
old_metrics = "const studioMetrics = auth.is_dev===true ? `<div class=\"rail-studio-metrics\" aria-label=\"DevStudio unresolved patch inbox\"><button type=\"button\" class=\"rail-studio-metric\" data-studio-metric=\"requests\" title=\"Implementation requests\"><span class=\"rail-studio-metric-count\">0</span><img src=\"assets/vendor/google-material-symbols/comment_48px.svg\" alt=\"\"></button><button type=\"button\" class=\"rail-studio-metric\" data-studio-metric=\"patches\" title=\"Global unresolved patches\"><span class=\"rail-studio-metric-count\">0</span><img src=\"assets/vendor/devstudio/create_new_folder_24dp.svg\" alt=\"\"></button><button type=\"button\" class=\"rail-studio-metric\" data-studio-metric=\"copy\" title=\"Copy unresolved patches for ChatGPT\"><span class=\"rail-studio-metric-count\">0</span><img src=\"assets/vendor/devstudio/folder_match_24dp.svg\" alt=\"\"></button></div>` : '';"
new_metrics = "const studioMetrics = auth.is_dev===true ? `<button type=\"button\" class=\"rail-studio-metric rail-studio-copy-metric\" data-studio-metric=\"copy\" title=\"Copy unresolved patches for ChatGPT\" hidden><span class=\"rail-studio-metric-count\">0</span><img src=\"assets/vendor/devstudio/folder_match_24dp.svg\" alt=\"\"></button>` : '';"
text = replace_once(text, old_metrics, new_metrics, 'account metric markup')
old_summary = "<div class=\"rail-user-summary\">${studioMetrics}<span class=\"rail-user-avatar\">${esc(name.charAt(0).toUpperCase())}</span><span class=\"rail-user-copy\"><strong>${esc(name)}</strong><small class=\"account-role-badge account-role-${roleClass}\">${esc(roleLabel)}</small></span>${auth.is_dev===true?'<button type=\"button\" class=\"rail-devstudio-toggle\" data-ui-studio=\"toggle\" aria-label=\"Enable DevStudio\" aria-pressed=\"false\" title=\"DevStudio\"><span aria-hidden=\"true\"></span></button>':''}</div>"
new_summary = "<div class=\"rail-user-summary\"><span class=\"rail-user-avatar\">${esc(name.charAt(0).toUpperCase())}</span><span class=\"rail-user-copy\"><strong>${esc(name)}</strong><small class=\"account-role-badge account-role-${roleClass}\">${esc(roleLabel)}</small></span>${studioMetrics}${auth.is_dev===true?'<button type=\"button\" class=\"rail-devstudio-toggle\" data-ui-studio=\"toggle\" aria-label=\"Enable DevStudio\" aria-pressed=\"false\" title=\"DevStudio\"><span aria-hidden=\"true\"></span></button>':''}</div>"
text = replace_once(text, old_summary, new_summary, 'copy control placement')
text = replace_once(
    text,
    "studioToggle.title = enabled ? 'DevStudio enabled' : 'DevStudio disabled';\n    }",
    "studioToggle.title = enabled ? 'DevStudio enabled' : 'DevStudio disabled';\n      const copyMetric=utilities.querySelector('[data-studio-metric=\"copy\"]');\n      if(copyMetric)copyMetric.hidden=!enabled;\n    }",
    'copy visibility follows DS',
)
text = replace_once(
    text,
    "window.addEventListener('merdpos-uistudio-state', event => syncStudioToggle(event.detail||{}));",
    "window.addEventListener('merdpos-uistudio-state', event => syncStudioToggle(event.detail||{}));\n      window.addEventListener('storage', event => { if(event.key===STUDIO_SETTINGS_KEY) syncStudioToggle(); });",
    'cross-window account accent sync',
)
text = replace_once(
    text,
    "assets/brand/M_Icon.svg?v=20260828about1",
    "assets/brand/M_Icon_v2.svg?v=20260902ds117",
    'About icon source',
)
write(p, text)

# Canonical page/dialog markup.
p = portal / 'dashboard.php'
text = read(p)
text = replace_once(
    text,
    '<div><h2>Employees</h2></div>',
    '<div><h2 class="ui-page-title">Workforce</h2></div>',
    'Workforce heading',
)
count_bad = text.count('>Ã—</button>')
if count_bad < 2:
    raise SystemExit(f'dialog close glyph: expected at least 2 mojibake buttons, found {count_bad}')
text = text.replace('>Ã—</button>', '>×</button>')
write(p, text)

# Owner stylesheet refinements.
p = portal / 'assets' / 'shell.css'
text = read(p)
marker = '/* DS117 desktop bottom-navigation label geometry. */'
if marker in text:
    raise SystemExit('shell DS117 marker already exists')
text += """

/* DS117 desktop bottom-navigation label geometry. */
@media (min-width: 51.3125rem) {
  .app-frame.nav-bottom .rail-group-btn {
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    padding-inline: var(--space-3);
  }
  .app-frame.nav-bottom .rail-group-btn > .rail-label {
    width: auto;
    max-width: 9rem;
    text-align: left;
  }
}
"""
write(p, text)

p = portal / 'assets' / 'design-system.css'
text = read(p)
marker = '/* DS117 shared directory/page-toolbar alignment. */'
if marker in text:
    raise SystemExit('design-system DS117 marker already exists')
text += """

/* DS117 shared directory/page-toolbar alignment. */
.merd-shell .directory-card > .directory-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
}
.merd-shell .directory-card > .directory-toolbar > :first-child {
  min-width: 0;
  align-self: center;
}
.merd-shell .directory-card > .directory-toolbar > :is(.directory-actions,.merd-action-cluster,.dev-store-actions,.directory-toolbar-actions) {
  margin-left: auto;
  align-self: center;
}
.merd-shell .admin-dialog-header > .icon-btn[data-close-dialog] {
  width: var(--size-icon-action);
  height: var(--size-icon-action);
  min-width: var(--size-icon-action);
  display: grid;
  place-items: center;
  padding: 0;
  border-radius: 50%;
  line-height: 1;
}
"""
write(p, text)

p = portal / 'assets' / 'account-menu.css'
text = read(p)
marker = '/* DS117 DevStudio copy-control placement. */'
if marker in text:
    raise SystemExit('account-menu DS117 marker already exists')
text += """

/* DS117 DevStudio copy-control placement. */
.rail-user-summary:has(.rail-devstudio-toggle) {
  grid-template-columns: var(--size-icon-lg) minmax(0, 1fr) auto auto;
}
.rail-user-summary > .rail-studio-copy-metric {
  grid-column: auto;
  width: var(--size-icon-action);
  min-width: var(--size-icon-action);
  min-height: var(--size-icon-action);
  padding: .2rem;
}
.rail-user-summary > .rail-studio-copy-metric[hidden] { display: none !important; }
"""
write(p, text)

# Cache/deployment contracts for changed runtime owners.
replacements = {
    'assets/shell.css?v=20260830bottom1': 'assets/shell.css?v=20260902ds117',
    'assets/design-system.css?v=20260902ds97': 'assets/design-system.css?v=20260902ds117',
    'assets/account-menu.css?v=20260902about3': 'assets/account-menu.css?v=20260902ds117',
    'assets/account-menu.js?v=20260901timesheetsync1': 'assets/account-menu.js?v=20260902ds117',
    'assets/ui-studio.js?v=20260902studio30': 'assets/ui-studio.js?v=20260902ds117',
    'assets/management.js?v=20260902about3': 'assets/management.js?v=20260902ds117',
}
for path in [
    portal / 'assets' / 'management.js',
    portal / 'dashboard.php',
    root / 'namecheap_beta_live' / 'backend' / 'cli' / 'validate_beta_runtime_contract.php',
    root / 'scripts' / 'deploy_namecheap_beta.sh',
]:
    text = read(path)
    for old, new in replacements.items():
        text = text.replace(old, new)
    write(path, text)

# Live deploy must require the exact supplied About icon and hash.
p = root / 'scripts' / 'deploy_namecheap_beta.sh'
text = read(p)
anchor = '  "$LIVE/timesheet_portal/assets/brand/brand-assets.js" \\\n'
if anchor not in text:
    raise SystemExit('deploy brand-assets anchor missing')
text = text.replace(
    anchor,
    anchor + '  "$LIVE/timesheet_portal/assets/brand/M_Icon_v2.svg" \\\n',
    1,
)
hash_gate = """
if [[ "$(sha256sum "$LIVE/timesheet_portal/assets/brand/M_Icon_v2.svg" | awk '{print $1}')" != "fd98914e2dcb700477e04b0ed4ddece75ecebf304a632e775149b57b3f1d9177" ]]; then
  echo "ERROR: live About control is missing the exact supplied M_Icon_v2.svg artwork." >&2
  exit 1
fi
"""
brand_gate = "if ! grep -q 'assets/brand/brand-assets.js?v=20260827brand4'"
if brand_gate not in text:
    raise SystemExit('deploy brand registry gate missing')
text = text.replace(brand_gate, hash_gate + '\n' + brand_gate, 1)
write(p, text)

# Regression contract for revision 117.
test = root / 'namecheap_beta_live' / 'browser_tests' / 'revision117-runtime.spec.js'
test.write_text(r'''const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
test.use({ channel: 'chrome' });
const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const portal = rel => path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', rel);
const source = rel => fs.readFileSync(portal(rel), 'utf8');

test('revision 117 palette exposes canonical semantic status colors without changing five brand masters', () => {
  const studio = source('assets/ui-studio.js');
  const tokens = source('assets/design-tokens.css');
  const server = source('includes/ui_studio_history.php');
  for (const [token, hex] of [['--color-warning','#8A5300'],['--color-danger','#B42318'],['--color-success','#18794E']]) {
    expect(tokens).toContain(`${token}: ${hex}`);
    expect(studio).toContain(`token:'${token}'`);
  }
  expect(studio).toContain('brand-[a-z0-9_-]{1,48}|success|warning|danger');
  expect(server).toContain('brand-[a-z0-9_-]{1,48}|success|warning|danger');
});

test('revision 117 fixes Workforce naming, close glyphs and DevStudio selector clobbering', () => {
  const dashboard = source('dashboard.php');
  const studio = source('assets/ui-studio.js');
  expect(dashboard).toContain('<h2 class="ui-page-title">Workforce</h2>');
  expect(dashboard).not.toContain('>Ã—</button>');
  expect(studio).toContain("getAttribute?.('id')");
  expect(studio).toContain('clearHover();clearSelected();');
});

test('revision 117 account shell keeps only copy metric, syncs storage and uses exact About icon', () => {
  const account = source('assets/account-menu.js');
  expect(account).not.toContain('data-studio-metric="requests"');
  expect(account).not.toContain('data-studio-metric="patches"');
  expect(account).toContain('data-studio-metric="copy"');
  expect(account).toContain("event.key===STUDIO_SETTINGS_KEY");
  expect(account).toContain('copyMetric.hidden=!enabled');
  expect(account).toContain('assets/brand/M_Icon_v2.svg?v=20260902ds117');
  const icon = fs.readFileSync(portal('assets/brand/M_Icon_v2.svg'));
  expect(crypto.createHash('sha256').update(icon).digest('hex')).toBe('fd98914e2dcb700477e04b0ed4ddece75ecebf304a632e775149b57b3f1d9177');
});

test('revision 117 desktop bottom navigation is icon-left label-right', async ({ page }) => {
  await page.setViewportSize({width:1280,height:800});
  await page.setContent('<div class="app-frame nav-bottom"><aside class="app-rail"><button class="rail-group-btn"><span class="ui-icon"></span><span class="rail-label">Home</span></button></aside></div>');
  await page.addStyleTag({path:portal('assets/design-tokens.css')});
  await page.addStyleTag({path:portal('assets/shell.css')});
  await expect(page.locator('.rail-group-btn')).toHaveCSS('flex-direction','row');
});

test('revision 117 shared directory toolbar and copy/toggle ordering are canonical', () => {
  const design = source('assets/design-system.css');
  const accountCss = source('assets/account-menu.css');
  const accountJs = source('assets/account-menu.js');
  expect(design).toContain('DS117 shared directory/page-toolbar alignment');
  expect(accountCss).toContain('grid-template-columns: var(--size-icon-lg) minmax(0, 1fr) auto auto');
  const summary = accountJs.match(/<div class="rail-user-summary">[^`]+/s)?.[0] || '';
  expect(summary.indexOf('${studioMetrics}')).toBeGreaterThan(summary.indexOf('rail-user-copy'));
  expect(summary.indexOf('rail-devstudio-toggle')).toBeGreaterThan(summary.indexOf('${studioMetrics}'));
});
''', encoding='utf-8')

# This helper is intentionally disposable; the product commit must not retain it.
Path('scripts/apply_revision117.py').unlink()
