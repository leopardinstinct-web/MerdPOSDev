<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\merdpos_core\Integration\ParityDataProviderInterface;
use Drupal\merdpos_core\Presentation\DashboardChartBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportsController extends ControllerBase {

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

  public function reports(): array {
    $query = $this->reportQuery();
    $surface = $this->parity->section('reports', $query);
    $exportUrl = Url::fromRoute('merdpos_core.reports_export', [], ['query'=>$query])->toString();
    return [
      '#theme' => 'merdpos_reports',
      '#surface' => $surface,
      '#charts' => $this->chartBuilder->build($surface['chart_specs'] ?? []),
      '#export_url' => $exportUrl,
      '#attached' => ['library' => ['merdpos_core/reports']],
      '#cache' => [
        'contexts' => ['user','url.query_args:week_start','url.query_args:store','url.query_args:employee','url.query_args:attendance'],
        'max-age' => 0,
      ],
    ];
  }

  public function exportCsv(): StreamedResponse {
    $surface = $this->parity->section('reports', $this->reportQuery());
    $columns = is_array($surface['export_columns'] ?? NULL) ? $surface['export_columns'] : [];
    $rows = is_array($surface['export_rows'] ?? NULL) ? $surface['export_rows'] : [];
    $week = preg_replace('/[^0-9-]/', '', (string)($surface['selected_week'] ?? 'report')) ?: 'report';
    $response = new StreamedResponse(static function() use ($columns,$rows): void {
      $out = fopen('php://output','wb');
      if ($out === false) return;
      fputcsv($out, array_map(static fn(array $c): string => (string)($c['label'] ?? $c['key'] ?? ''), $columns), ',', '"', '');
      foreach ($rows as $row) {
        fputcsv($out, array_map(static fn(array $c): string => (string)($row[(string)($c['key'] ?? '')] ?? ''), $columns), ',', '"', '');
      }
      fclose($out);
    });
    $response->headers->set('Content-Type','text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition','attachment; filename="merdpos-timesheet-' . $week . '.csv"');
    $response->headers->set('Cache-Control','private, no-store, max-age=0');
    return $response;
  }

  private function reportQuery(): array {
    $request = $this->requestStack->getCurrentRequest();
    $query = [];
    foreach (['week_start','store','employee','attendance'] as $key) {
      $value = $request?->query->get($key);
      if (is_scalar($value)) $query[$key] = (string)$value;
    }
    return $query;
  }

}

