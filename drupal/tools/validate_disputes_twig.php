<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$loader = new Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/web/modules/custom/merdpos_core/templates');
$twig = new Twig\Environment($loader);
$twig->addFunction(new Twig\TwigFunction('path', static fn(string $route, array $parameters = [], array $options = []): string => '/merdpos/disputes'));
$twig->load('merdpos-disputes.html.twig');
$template = (string) file_get_contents(dirname(__DIR__) . '/web/modules/custom/merdpos_core/templates/merdpos-disputes.html.twig');
if (!str_contains($template, 'merdpos-ui-kpi-grid--cards') || !str_contains($template, 'data-kpi-count="{{ can_resolve_flags ? 4 : 3 }}"')) {
  throw new RuntimeException('Disputes KPI collection must adapt 3↔4 to permission visibility.');
}

echo "MERDPOS Drupal Disputes Twig syntax validated.\n";
