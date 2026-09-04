<?php
declare(strict_types=1);

namespace Drupal\merdpos_core\Integration;

interface ParityDataProviderInterface {

  public function home(array $query = []): array;

  public function section(string $section, array $query = []): array;

}
