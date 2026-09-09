<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$script = file_get_contents($root . '/tools/namecheap_deploy.sh');
if (!is_string($script)) throw new RuntimeException('Unreadable deployment script.');
function deploy_clean_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$restore = 'git -C "$REPO" checkout -- drupal/.gitattributes drupal/web/.htaccess';
deploy_clean_check(str_contains($script, $restore), 'Deploy must restore canonical Drupal scaffold files after Composer.');
deploy_clean_check(str_contains($script, 'status --short --untracked-files=no'), 'Deploy must inspect tracked Git drift before verification.');
deploy_clean_check(str_contains($script, 'Deployment left tracked Git drift:'), 'Deploy tracked-drift failure message is missing.');
$composer = strpos($script, 'php84 "$COMPOSER" install --no-interaction --prefer-dist --optimize-autoloader');
$restoreAt = strpos($script, $restore);
deploy_clean_check($composer !== false && $restoreAt !== false && $restoreAt > $composer, 'Scaffold restore must run after Composer install.');

echo "MERDPOS Drupal clean-checkout deployment v1 validated.\n";
