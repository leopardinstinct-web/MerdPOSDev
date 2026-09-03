<?php
declare(strict_types=1);

namespace Drupal\merdpos_core\Integration;

interface WorkingNowProviderInterface {

  /**
   * Returns a normalized read-only Working Now snapshot.
   *
   * @return array{status:string,count:?int,people:array,message:string,generated_at:?string}
   */
  public function load(): array;

}
