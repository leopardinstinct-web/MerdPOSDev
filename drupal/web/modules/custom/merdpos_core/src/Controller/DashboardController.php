<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Provides the first MERDPOS Drupal management shell.
 */
final class DashboardController extends ControllerBase {

  /**
   * Builds the management dashboard shell without fabricating business data.
   */
  public function dashboard(): array {
    return [
      '#theme' => 'merdpos_dashboard',
      '#user_name' => $this->currentUser()->getDisplayName(),
      '#role_labels' => $this->roleLabels(),
      '#kpis' => $this->kpis(),
      '#foundations' => $this->foundations(),
      '#attached' => [
        'library' => ['merdpos_core/dashboard'],
      ],
      '#cache' => [
        'contexts' => ['user.roles', 'user'],
      ],
    ];
  }

  /**
   * Resolves configured Drupal role labels for presentation only.
   */
  private function roleLabels(): array {
    $roles = $this->entityTypeManager()
      ->getStorage('user_role')
      ->loadMultiple($this->currentUser()->getRoles());

    return array_values(array_map(
      static fn($role): string => (string) $role->label(),
      $roles,
    ));
  }

  /**
   * Placeholder cards expose integration state, never invented MERDPOS values.
   */
  private function kpis(): array {
    return [
      ['label' => 'Working now', 'value' => '—', 'meta' => 'MERDPOS API adapter pending', 'tone' => 'info'],
      ['label' => 'Sales today', 'value' => '—', 'meta' => 'MERDPOS API adapter pending', 'tone' => 'brand'],
      ['label' => 'Pending disputes', 'value' => '—', 'meta' => 'MERDPOS API adapter pending', 'tone' => 'warning'],
      ['label' => 'Cash status', 'value' => '—', 'meta' => 'MERDPOS API adapter pending', 'tone' => 'success'],
    ];
  }

  /**
   * Makes the inherited engineering contract visible in the prototype shell.
   */
  private function foundations(): array {
    return [
      ['title' => 'Git is truth', 'text' => 'Code, configuration, roles, and design resources are reconstructed from the branch.'],
      ['title' => 'Named permissions', 'text' => 'Drupal UI roles mirror access, while MERDPOS backend permission and tenant enforcement remains authoritative.'],
      ['title' => 'Canonical design', 'text' => 'Drupal consumes a synchronized copy of the existing MERDPOS design tokens instead of inventing a new palette.'],
      ['title' => 'Parallel migration', 'text' => 'The current MERDPOS beta and operational database remain untouched while Drupal is proven feature by feature.'],
    ];
  }

}
