<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$loader = new Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/web/modules/custom/merdpos_core/templates');
$twig = new Twig\Environment($loader);
$twig->addFunction(new Twig\TwigFunction('path', static fn(string $route, array $parameters = [], array $options = []): string => '/merdpos/disputes'));
$twig->load('merdpos-disputes.html.twig');

echo "MERDPOS Drupal Disputes Twig syntax validated.\n";
