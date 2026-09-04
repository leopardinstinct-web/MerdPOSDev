<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\merdpos_core\Integration\ParityDataProviderInterface;
use Drupal\merdpos_core\Presentation\DashboardChartBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class DashboardController extends ControllerBase {

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

  public function dashboard(): array {
    $request = $this->requestStack->getCurrentRequest();
    $query = $request ? $request->query->all() : [];
    $surface = $this->parity->home($query);
    return [
      '#theme' => 'merdpos_dashboard',
      '#surface' => $surface,
      '#charts' => $this->chartBuilder->build($surface['chart_specs'] ?? []),
      '#attached' => ['library' => ['merdpos_core/dashboard']],
      '#cache' => [
        'contexts' => ['user', 'url.query_args:store_id', 'url.query_args:period'],
        'max-age' => 0,
      ],
    ];
  }

}
