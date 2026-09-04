<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Replaces Drupal's local credential form with authoritative MERDPOS login.
 */
final class MerdposRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('user.login');
    if ($route === NULL) return;
    $route->setPath('/login');
    $route->setDefault('_form', 'Drupal\merdpos_core\Form\MerdposLoginForm');
    $route->setDefault('_title', 'MERDPOS Login');
  }

}
