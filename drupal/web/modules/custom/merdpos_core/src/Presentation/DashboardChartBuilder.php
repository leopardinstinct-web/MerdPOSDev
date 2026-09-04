<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Presentation;

/** Builds Drupal Charts render arrays for MERDPOS dashboard widgets. */
final class DashboardChartBuilder {

  /** @param array<int,array<string,mixed>> $specs */
  public function build(array $specs): array {
    $charts = [];
    foreach ($specs as $spec) {
      $key = (string) ($spec['key'] ?? '');
      $type = (string) ($spec['type'] ?? 'line');
      $labels = is_array($spec['labels'] ?? NULL) ? array_values($spec['labels']) : [];
      $values = is_array($spec['values'] ?? NULL) ? array_values($spec['values']) : [];
      if ($key === '' || !$labels || count($labels) !== count($values)) continue;

      $chart = [
        '#type' => 'chart',
        '#chart_library' => 'google',
        '#chart_type' => $type,
        '#chart_id' => 'merdpos-' . str_replace('_', '-', $key),
        '#title' => '',
        '#tooltips' => TRUE,
        '#legend' => $type === 'donut',
        '#legend_position' => 'bottom',
        '#background' => 'transparent',
        '#height' => (int) ($spec['height'] ?? 280),
        '#accessible_table' => 'invisible',
        '#data_markers' => in_array($type, ['line', 'spline'], TRUE),
        '#attributes' => ['class' => ['merdpos-drupal-chart']],
        'x_axis' => [
          '#type' => 'chart_xaxis',
          '#labels' => $labels,
          '#title' => '',
        ],
        'y_axis' => [
          '#type' => 'chart_yaxis',
          '#title' => '',
          '#min' => 0,
        ],
        'series' => [
          '#type' => 'chart_data',
          '#title' => (string) ($spec['series_label'] ?? ''),
          '#data' => array_map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0, $values),
          '#color' => (string) ($spec['color'] ?? '#1c4587'),
        ],
      ];

      if ($type === 'donut') {
        $chart['#colors'] = is_array($spec['colors'] ?? NULL) ? array_values($spec['colors']) : ['#1c4587', '#23a6a8'];
        $chart['y_axis']['#min'] = NULL;
      }
      $charts[$key] = $chart;
    }
    return $charts;
  }

}