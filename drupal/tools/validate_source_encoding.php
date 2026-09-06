<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
  $root . '/tools',
  $root . '/web/modules/custom',
  $root . '/web/themes/custom',
];
$extensions = ['php','twig','yml','yaml','css','js','md','txt','json'];
$bad = ["\u{00C2}", "\u{00C3}", "\u{FFFD}", "\u{00E2}\u{20AC}", "\u{00E2}\u{2020}", "\u{00F0}\u{0178}"];
$failures = [];
foreach ($paths as $base) {
  if (!is_dir($base)) continue;
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $file) {
    if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $extensions, true)) continue;
    $data = file_get_contents($file->getPathname());
    if (!is_string($data)) { $failures[] = $file->getPathname() . ': unreadable'; continue; }
    if (preg_match('//u', $data) !== 1) { $failures[] = $file->getPathname() . ': invalid UTF-8'; continue; }
    foreach ($bad as $marker) {
      if (str_contains($data, $marker)) { $failures[] = $file->getPathname() . ': suspicious mojibake marker ' . json_encode($marker); break; }
    }
  }
}
if ($failures) {
  fwrite(STDERR, "MERDPOS source encoding validation failed:\n - " . implode("\n - ", $failures) . "\n");
  exit(1);
}
echo "MERDPOS UTF-8/mojibake source validation passed.\n";
