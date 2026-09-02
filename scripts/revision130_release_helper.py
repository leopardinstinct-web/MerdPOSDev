from pathlib import Path
import ast
import sys

ROOT = Path(__file__).resolve().parents[1]
TRANSFORMER = ROOT / 'scripts' / 'apply_revision130.py'
VALIDATOR = ROOT / 'namecheap_beta_live' / 'backend' / 'cli' / 'validate_beta_runtime_contract.php'
DEPLOY = ROOT / 'scripts' / 'deploy_namecheap_beta.sh'

VERSIONS = {
    'assets/design-tokens.css?v=20260828palette1': 'assets/design-tokens.css?v=20260902ds130',
    'assets/design-system.css?v=20260902ds117': 'assets/design-system.css?v=20260902ds130',
    'assets/shell.css?v=20260902ds117': 'assets/shell.css?v=20260902ds130',
    'assets/navigation.js?v=20260902ds97': 'assets/navigation.js?v=20260902ds130',
    'assets/account-menu.js?v=20260902ds117': 'assets/account-menu.js?v=20260902ds130',
    'assets/dashboard-builder.css?v=20260902ds97': 'assets/dashboard-builder.css?v=20260902ds130',
    'assets/dashboard-builder.js?v=20260902ds97': 'assets/dashboard-builder.js?v=20260902ds130',
    'assets/management.js?v=20260902ds117': 'assets/management.js?v=20260902ds130',
    'assets/directory.js?v=20260902ds97': 'assets/directory.js?v=20260902ds130',
}


def pre():
    text = TRANSFORMER.read_text(encoding='utf-8')
    old = 't=replace_once(t,"  function openMobileTools(trigger) {","  function openMobileTools(trigger, options = {}) {")'
    new = 't=replace_once(t,"  function openMobileTools(trigger) {","  function openMobileTools(trigger, options = {}) {",\'account open signature\')'
    if text.count(old) != 1:
        raise SystemExit(f'expected one transformer typo, found {text.count(old)}')
    text = text.replace(old, new, 1)
    tree = ast.parse(text)
    bad = [node.lineno for node in ast.walk(tree)
           if isinstance(node, ast.Call) and isinstance(node.func, ast.Name)
           and node.func.id == 'replace_once' and len(node.args) < 4]
    if bad:
        raise SystemExit(f'replace_once calls missing labels at {bad}')
    TRANSFORMER.write_text(text, encoding='utf-8')


def replace_versions(text):
    for old, new in VERSIONS.items():
        text = text.replace(old, new)
    return text


def post():
    validator = replace_versions(VALIDATOR.read_text(encoding='utf-8'))
    validator = validator.replace(
        "beta_contract_require_contains($directoryJs, 'window.MERDPOSStoreTimings?.openStore?.', 'Store Edit opens embedded timings', $errors);",
        "beta_contract_require_contains($directoryJs, 'timingsModuleReady.then', 'Store Edit waits for embedded timings', $errors);"
    )
    validator = validator.replace(
        "beta_contract_require_contains($timingsJs, \"scope:'store'\", 'Store timings save is store-scoped', $errors);",
        "beta_contract_require_contains($timingsJs, 'collectForSave', 'Store timings hand off to unified Store Save', $errors);"
    )
    VALIDATOR.write_text(validator, encoding='utf-8')

    deploy = replace_versions(DEPLOY.read_text(encoding='utf-8'))
    anchor = '''if ! grep -q 'directory-edit-icon-btn' "$LIVE/timesheet_portal/assets/directory.js" || grep -q 'class="icon-text-btn" data-edit-employee' "$LIVE/timesheet_portal/assets/directory.js"; then
  echo "ERROR: live DS97 shared directory edit-action contract is missing." >&2
  exit 1
fi
'''
    guard = '''if ! grep -q -- '--color-brand-primary: var(--color-brand-cyan)' "$LIVE/timesheet_portal/assets/design-tokens.css"; then
  echo "ERROR: live revision 130 cyan product accent is missing." >&2
  exit 1
fi
if ! grep -q 'dashboard-page-head' "$LIVE/timesheet_portal/assets/dashboard-builder.js"; then
  echo "ERROR: live revision 130 Dashboard heading is missing." >&2
  exit 1
fi
if ! grep -q 'id="storeProfileFields"' "$LIVE/timesheet_portal/dashboard.php" || ! grep -q 'directory_store_edit_fields' "$LIVE/timesheet_portal/api/admin_directory.php"; then
  echo "ERROR: live revision 130 schema-aware Store profile editor is missing." >&2
  exit 1
fi
if grep -q 'saveTimingsBtn' "$LIVE/timesheet_portal/assets/timings.js" || ! grep -q 'collectForSave' "$LIVE/timesheet_portal/assets/timings.js"; then
  echo "ERROR: live revision 130 unified Store Save contract is missing." >&2
  exit 1
fi
if ! grep -q "ACCOUNT_UI_STATE_KEY='merdpos-account-tools-ui-v1'" "$LIVE/timesheet_portal/assets/account-menu.js"; then
  echo "ERROR: live revision 130 account-tools persistence is missing." >&2
  exit 1
fi
'''
    if 'live revision 130 cyan product accent is missing' not in deploy:
        if anchor not in deploy:
            raise SystemExit('deploy DS97 directory guard anchor missing')
        deploy = deploy.replace(anchor, anchor + guard, 1)
    DEPLOY.write_text(deploy, encoding='utf-8')
    Path(__file__).unlink()


if len(sys.argv) != 2 or sys.argv[1] not in {'pre', 'post'}:
    raise SystemExit('usage: revision130_release_helper.py pre|post')
pre() if sys.argv[1] == 'pre' else post()
