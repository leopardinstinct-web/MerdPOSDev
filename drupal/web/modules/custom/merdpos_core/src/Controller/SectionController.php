<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides safe section landing states before operational adapters are wired.
 */
final class SectionController extends ControllerBase {

  public function section(string $section): array {
    $sections = $this->sections();
    if (!isset($sections[$section])) {
      throw new NotFoundHttpException();
    }

    return [
      '#theme' => 'merdpos_section',
      '#section' => $sections[$section],
      '#attached' => ['library' => ['merdpos_core/dashboard']],
      '#cache' => ['contexts' => ['user.roles', 'user.permissions']],
    ];
  }

  private function sections(): array {
    return [
      'operations' => [
        'eyebrow' => 'Operations',
        'title' => 'Workforce and stores',
        'description' => 'The shell is ready for read-only workforce and store adapters. No operational write path is connected yet.',
        'items' => ['Workforce', 'Stores'],
      ],
      'reports' => [
        'eyebrow' => 'Reports',
        'title' => 'Timesheets and disputes',
        'description' => 'Reporting surfaces will consume existing MERDPOS service contracts without reimplementing payroll reconciliation.',
        'items' => ['Timesheets', 'Disputes'],
      ],
      'finance' => [
        'eyebrow' => 'Finance',
        'title' => 'Financial operations',
        'description' => 'Financial views remain adapter-only until existing cash and financial authorization paths are connected and tested.',
        'items' => ['Financial'],
      ],
      'dev' => [
        'eyebrow' => 'DEV',
        'title' => 'Platform workspace',
        'description' => 'Drupal DEV scaffolding never replaces the MERDPOS requirement for an actual DEV identity at the backend boundary.',
        'items' => ['Platform', 'Clients', 'UI Studio'],
      ],
    ];
  }

}
