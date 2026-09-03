<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$manifestPath = $repoRoot . '/drupal/resources/merdpos-resources.json';
$checkOnly = in_array('--check', $argv, true);

if (!is_file($manifestPath)) {
  fwrite(STDERR, "MERDPOS resource manifest not found.\n");
  exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$resources = $manifest['resources'] ?? [];
if (!is_array($resources) || $resources === []) {
  fwrite(STDERR, "MERDPOS resource manifest contains no resources.\n");
  exit(1);
}

$failed = false;
foreach ($resources as $resource) {
  $id = (string) ($resource['id'] ?? 'unknown');
  $source = $repoRoot . '/' . ltrim((string) ($resource['source'] ?? ''), '/');
  $target = $repoRoot . '/' . ltrim((string) ($resource['target'] ?? ''), '/');

  if (!is_file($source)) {
    fwrite(STDERR, "Missing canonical resource {$id}: {$source}\n");
    $failed = true;
    continue;
  }

  if ($checkOnly) {
    if (!is_file($target) || hash_file('sha256', $source) !== hash_file('sha256', $target)) {
      fwrite(STDERR, "Resource drift detected for {$id}.\n");
      $failed = true;
    }
    continue;
  }

  $targetDir = dirname($target);
  if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    fwrite(STDERR, "Unable to create target directory for {$id}.\n");
    $failed = true;
    continue;
  }

  if (!copy($source, $target)) {
    fwrite(STDERR, "Unable to synchronize {$id}.\n");
    $failed = true;
    continue;
  }

  fwrite(STDOUT, "Synchronized {$id}.\n");
}

if ($failed) {
  exit(1);
}

fwrite(STDOUT, $checkOnly ? "MERDPOS resources match canonical sources.\n" : "MERDPOS resource synchronization complete.\n");
