<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Auth;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use RuntimeException;

final class MerdposIdentityManager {

  private const ROLE_MAP = [
    'USER' => 'merdpos_user',
    'ADMIN' => 'merdpos_admin',
    'SUPER' => 'merdpos_super',
    'DEV' => 'merdpos_dev',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UserDataInterface $userData,
  ) {}

  /** @param array<string,mixed> $identity */
  public function synchronize(array $identity): UserInterface {
    $employeeId = (int) ($identity['id'] ?? 0);
    $clientId = (int) ($identity['client_id'] ?? 0);
    $userId = trim((string) ($identity['user_id'] ?? ''));
    $role = strtoupper(trim((string) ($identity['role'] ?? $identity['actual_employee_type'] ?? 'USER')));
    if ($employeeId < 1 || $clientId < 1 || !preg_match('/^\d{1,20}$/', $userId) || !isset(self::ROLE_MAP[$role])) {
      throw new RuntimeException('Invalid MERDPOS identity.');
    }

    $storage = $this->entityTypeManager->getStorage('user');
    $username = sprintf('merdpos-c%d-e%d', $clientId, $employeeId);
    $matches = $storage->loadByProperties(['name' => $username]);
    $account = reset($matches);
    if (!$account instanceof UserInterface) {
      $account = $storage->create([
        'name' => $username,
        'status' => 1,
        'pass' => bin2hex(random_bytes(32)),
      ]);
    }

    $account->activate();
    $knownRoles = array_values(self::ROLE_MAP);
    $roles = array_values(array_diff($account->getRoles(TRUE), $knownRoles));
    $roles[] = self::ROLE_MAP[$role];
    $account->set('roles', array_values(array_unique($roles)));
    $account->save();

    $profile = [
      'employee_id' => $employeeId,
      'client_id' => $clientId,
      'store_id' => isset($identity['store_id']) ? (int) $identity['store_id'] : NULL,
      'user_id' => $userId,
      'full_name' => trim((string) ($identity['full_name'] ?? $identity['name'] ?? 'MERDPOS User')),
      'role' => $role,
      'role_key' => trim((string) ($identity['role_key'] ?? $role)),
      'role_label' => trim((string) ($identity['role_label'] ?? $identity['role_name'] ?? $role)),
      'authority_level' => (int) ($identity['authority_level'] ?? 0),
      'authenticated_at_utc' => gmdate(DATE_ATOM),
    ];
    $this->userData->set('merdpos_core', (int) $account->id(), 'identity', $profile);
    return $account;
  }

  /** @return array<string,mixed>|null */
  public function profile(int $uid): ?array {
    $profile = $this->userData->get('merdpos_core', $uid, 'identity');
    return is_array($profile) ? $profile : NULL;
  }

}
