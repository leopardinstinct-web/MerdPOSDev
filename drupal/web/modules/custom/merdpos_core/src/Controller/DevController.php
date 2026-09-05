<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\merdpos_core\Integration\ParityDataProviderInterface;
use Drupal\merdpos_core\Presentation\DashboardChartBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class DevController extends ControllerBase {
  public function __construct(
    private readonly ParityDataProviderInterface $parity,
    private readonly DashboardChartBuilder $chartBuilder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('merdpos_core.parity_provider'),
      $container->get('merdpos_core.dashboard_chart_builder'),
    );
  }

  public function dev(): array {
    $surface = $this->parity->section('dev');
    return [
      '#theme' => 'merdpos_dev',
      '#surface' => $surface,
      '#charts' => $this->chartBuilder->build($surface['chart_specs'] ?? []),
      '#release' => $this->releaseMarker(),
      '#local_runtime' => ['drupal'=>\Drupal::VERSION, 'php'=>PHP_VERSION, 'environment'=>'Drupal Beta'],
      '#attached' => ['library' => ['merdpos_core/dev']],
      '#cache' => ['contexts'=>['user'],'max-age'=>0],
    ];
  }

  private function releaseMarker(): array {
    $path = DRUPAL_ROOT . '/.merdpos_drupal_release.json';
    if (!is_readable($path)) return ['available'=>false];
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : NULL;
    if (!is_array($data)) return ['available'=>false];
    return [
      'available'=>true,
      'commit'=>substr((string)($data['commit'] ?? ''), 0, 12),
      'branch'=>(string)($data['branch'] ?? ''),
      'deployed_at'=>(string)($data['deployed_at'] ?? ''),
      'parity'=>(string)($data['parity_status'] ?? ''),
      'dashboard'=>(string)($data['dashboard_v2']['status'] ?? ''),
      'operations'=>(string)($data['operations_v2']['status'] ?? ''),
      'reports'=>(string)($data['reports_v2']['status'] ?? ''),
      'finance'=>(string)($data['finance_v2']['status'] ?? ''),
      'dev'=>(string)($data['dev_v2']['status'] ?? ''),
    ];
  }
}
