const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const repo = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const read = rel => fs.readFileSync(path.join(repo, rel), 'utf8');

test('repository continuity layer describes current DevStudio truth', () => {
  const invariants = read('.ai/invariants.md');
  const memory = read('.ai/memory.md');
  const studio = read('namecheap_beta_live/timesheet_portal/UI_STUDIO.md');
  expect(memory).toContain('**Updated:** 2026-09-01');
  expect(memory).toContain('Current DevStudio checkpoint');
  expect(memory).toContain('Studio29');
  expect(memory).toContain('migration 035');
  expect(memory).toContain('migration 036:');
  expect(invariants).toContain('dedicated client-scoped Studio state/audit subsystem');
  expect(invariants).not.toContain('Draft UI changes may persist only as local browser preview state');
  expect(studio).toContain('# MERDPOS DevStudio — Current Contract');
  expect(studio).toContain('Global unresolved inbox');
  expect(studio).toContain('Palette-standard escalation');
  expect(studio).not.toContain('getHistory()');
});
test('active work index and release pipeline are continuity guarded', () => {
  const active = read('.ai/work/ACTIVE.yaml');
  const validator = read('namecheap_beta_live/backend/cli/validate_ai_continuity.php');
  const workflow = read('.github/workflows/beta-guardrails.yml');
  const deploy = read('scripts/deploy_namecheap_beta.sh');
  const activeDir = path.join(repo, '.ai', 'work', 'active');
  const files = fs.readdirSync(activeDir).filter(name => name.endsWith('.yaml')).sort();
  const indexed = [...active.matchAll(/path:\s+\.ai\/work\/active\/([^\s]+\.yaml)/g)].map(match => match[1]).sort();
  expect(files).toEqual(indexed);
  expect(validator).toContain('Orphan active work packet');
  expect(validator).toContain('Encoding/mojibake marker');
  expect(workflow).toContain('Validate AI continuity truthfulness');
  expect(workflow).toContain('validate_ai_continuity.php');
  expect(deploy).toContain('validating GitHub AI continuity truthfulness');
  expect(deploy).toContain('validate_ai_continuity.php');
});