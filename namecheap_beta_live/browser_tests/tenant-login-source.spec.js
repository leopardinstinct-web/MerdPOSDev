const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('portal login scopes explicit tenant authentication and lockout to resolved client', async () => {
  const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
  const loginPath = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'api', 'login.php');
  const source = fs.readFileSync(loginPath, 'utf8');

  expect(source).toContain("$requestedClientCode = strtoupper(trim((string)($input['client_code'] ?? '')))");
  expect(source).toContain("SELECT id FROM clients WHERE UPPER(client_code)=? AND status='active' LIMIT 1");
  expect(source).toContain('$authClientId = PORTAL_CLIENT_ID;');
  expect(source).toContain('$lockout->assertNotLocked($authClientId');
  expect(source).toContain('$lockout->recordFailure($authClientId');
  expect(source).toContain('$lockout->recordSuccess($authClientId');
  expect(source).toContain('$stmt->execute([$authClientId, $userId]);');

  expect(source).not.toContain('$lockout->assertNotLocked(PORTAL_CLIENT_ID');
  expect(source).not.toContain('$stmt->execute([PORTAL_CLIENT_ID, $userId]);');
});
