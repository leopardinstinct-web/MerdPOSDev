<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\merdpos_core\Integration\WorkingNowProviderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the MERDPOS Drupal management dashboard.
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly WorkingNowProviderInterface $workingNowProvider,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('merdpos_core.working_now_provider'));
  }

  /**
   * Builds the dashboard without fabricating MERDPOS business data.
   */
  public function dashboard(): array {
    $workingNow = $this->workingNowProvider->load();

    return [
      '#theme' => 'merdpos_dashboard',
      '#user_name' => $this->currentUser()->getDisplayName(),
      '#role_labels' => $this->roleLabels(),
      '#kpis' => $this->kpis($workingNow),
      '#working_now' => $this->workingNowView($workingNow),
      '#foundations' => $this->foundations(),
      '#attached' => ['library' => ['merdpos_core/dashboard']],
      '#cache' => ['contexts' => ['user.roles', 'user'], 'max-age' => 0],
    ];
  }

  private function roleLabels(): array {
    $roles = $this->entityTypeManager()
      ->getStorage('user_role')
      ->loadMultiple($this->currentUser()->getRoles());

    return array_values(array_map(
      static fn($role): string => (string)$role->label(),
      $roles,
    ));
  }

  private function kpis(array $workingNow): array {
    $workingMeta = match ($workingNow['status'] ?? 'unavailable') {
      'ok' => 'Live read-only data from MERDPOS',
      'forbidden' => 'Blocked by MERDPOS permission policy',
      'unconfigured' => 'Service adapter not configured',
      default => 'MERDPOS service unavailable',
    };

    return [
      [
        'label' => 'Working now',
        'value' => ($workingNow['status'] ?? '') === 'ok' ? (string)($workingNow['count'] ?? 0) : '—',
        'meta' => $workingMeta,
        'tone' => 'info',
      ],
      ['label' => 'Sales today', 'value' => '—', 'meta' => 'MERDPOS API adapter pending', 'tone' => 'brand'],
      ['label' => 'Pending disputes', 'value' => '—', 'meta' => 'MERDPOS API adapter pending', 'tone' => 'warning'],
      ['label' => 'Cash status', 'value' => '—', 'meta' => 'MERDPOS API adapter pending', 'tone' => 'success'],
    ];
  }

  private function workingNowView(array $workingNow): array {
    $people = [];
    foreach ((array)($workingNow['people'] ?? []) as $person) {
      if (!is_array($person)) continue;
      $minutes = max(0, (int)($person['working_minutes'] ?? 0));
      $hours = intdiv($minutes, 60);
      $remainder = $minutes % 60;
      $people[] = $person + [
        'duration_label' => $hours > 0 ? sprintf('%dh %dm', $hours, $remainder) : sprintf('%dm', $remainder),
      ];
    }

    return [
      'status' => (string)($workingNow['status'] ?? 'unavailable'),
      'count' => $workingNow['count'] ?? null,
      'people' => $people,
      'message' => (string)($workingNow['message'] ?? 'Working Now is unavailable.'),
      'generated_at' => $workingNow['generated_at'] ?? null,
    ];
  }

  private function foundations(): array {
    return [
      ['title' => 'Git is truth', 'text' => 'Code, configuration, roles, and design resources are reconstructed from the branch.'],
      ['title' => 'Named permissions', 'text' => 'Drupal UI roles mirror access, while MERDPOS backend permission and tenant enforcement remains authoritative.'],
      ['title' => 'Canonical design', 'text' => 'Drupal consumes a synchronized copy of the existing MERDPOS design tokens instead of inventing a new palette.'],
      ['title' => 'Parallel migration', 'text' => 'The current MERDPOS beta and operational database remain untouched while Drupal is proven feature by feature.'],
    ];
  }

}
