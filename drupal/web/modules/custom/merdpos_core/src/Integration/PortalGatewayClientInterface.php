<?php
declare(strict_types=1);

namespace Drupal\merdpos_core\Integration;

interface PortalGatewayClientInterface {

  public function call(
    string $route,
    string $method = 'GET',
    array $query = [],
    array $body = [],
  ): array;

}
