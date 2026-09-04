<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Auth;

interface MerdposAuthenticatorInterface {

  /**
   * Authenticate numeric MERDPOS credentials against authoritative Beta.
   *
   * @return array{status:string,identity?:array<string,mixed>,message?:string}
   */
  public function authenticate(string $userId, string $password): array;

  /**
   * Verify that the authoritative login endpoint is reachable without a login.
   *
   * @return array{status:string,http_status?:int,message?:string}
   */
  public function health(): array;

}
