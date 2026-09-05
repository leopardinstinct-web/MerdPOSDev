<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\merdpos_core\Integration\ParityDataProviderInterface;
use Drupal\merdpos_core\Presentation\DashboardChartBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class OperationsController extends ControllerBase {

  public function __construct(
    private readonly ParityDataProviderInterface $parity,
    private readonly DashboardChartBuilder $chartBuilder,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('merdpos_core.parity_provider'),
      $container->get('merdpos_core.dashboard_chart_builder'),
      $container->get('request_stack'),
    );
  }

  public function operations(): array {
    $request = $this->requestStack->getCurrentRequest();
    $query = [];
    foreach (['store_id', 'period'] as $key) {
      $value = $request?->query->get($key);
      if (is_scalar($value)) $query[$key] = (string) $value;
    }
    $surface = $this->parity->section('operations', $query);
    return [
      '#theme' => 'merdpos_operations',
      '#surface' => $surface,
      '#charts' => $this->chartBuilder->build($surface['chart_specs'] ?? []),
      '#attached' => ['library' => ['merdpos_core/operations']],
      '#cache' => [
        'contexts' => ['user', 'url.query_args:store_id', 'url.query_args:period'],
        'max-age' => 0,
      ],
    ];
  }

}
