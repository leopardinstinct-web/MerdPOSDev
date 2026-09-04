<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$source = $repoRoot . '/namecheap_beta_live/timesheet_portal/assets/design-tokens.css';
$target = dirname(__DIR__) . '/web/modules/custom/merdpos_core/css/design-tokens.css';
$checkOnly = in_array('--check', $argv, true);

if (!is_file($source)) {
  fwrite(STDERR, "Canonical MERDPOS design tokens not found: {$source}\n");
  exit(2);
}

$canonical = file_get_contents($source);
$current = is_file($target) ? file_get_contents($target) : false;

if ($checkOnly) {
  if ($current !== $canonical) {
    fwrite(STDERR, "Drupal MERDPOS design tokens are out of sync.\n");
    exit(1);
  }
  fwrite(STDOUT, "Drupal MERDPOS design tokens match canonical Beta tokens.\n");
  exit(0);
}

if (file_put_contents($target, $canonical) === false) {
  fwrite(STDERR, "Unable to synchronize Drupal MERDPOS design tokens.\n");
  exit(3);
}

fwrite(STDOUT, "Synchronized canonical MERDPOS design tokens into Drupal.\n");
