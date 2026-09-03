<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Applies the MERDPOS app theme only to MERDPOS application routes.
 */
final class MerdposThemeNegotiator implements ThemeNegotiatorInterface {

  public function applies(RouteMatchInterface $route_match): bool {
    $route_name = (string) $route_match->getRouteName();
    return str_starts_with($route_name, 'merdpos_core.');
  }

  public function determineActiveTheme(RouteMatchInterface $route_match): string {
    return 'merdpos_app';
  }

}
