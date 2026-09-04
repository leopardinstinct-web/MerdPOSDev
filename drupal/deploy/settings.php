<?php

declare(strict_types=1);

$runtime = '/home/dridsheikh/.merdpos_drupal_runtime.php';
if (!is_readable($runtime)) {
  throw new RuntimeException('MERDPOS Drupal private runtime configuration is missing.');
}
require $runtime;

$settings['trusted_host_patterns'] = [
  '^drupal-beta\.merdpos\.com$',
];
$settings['file_private_path'] = '/home/dridsheikh/.merdpos_drupal_private';
$settings['config_sync_directory'] = '/home/dridsheikh/.merdpos_drupal_config_sync';
