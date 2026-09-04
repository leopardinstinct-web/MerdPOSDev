<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\merdpos_core\Integration\ParityDataProviderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SectionController extends ControllerBase {

  public function __construct(
    private readonly ParityDataProviderInterface $parity,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('merdpos_core.parity_provider'),
      $container->get('request_stack'),
    );
  }

  public function section(string $section): array {
    if (!in_array($section, ['operations', 'reports', 'finance', 'dev'], true)) {
      throw new NotFoundHttpException();
    }

    $request = $this->requestStack->getCurrentRequest();
    $query = [];
    foreach (['week_start', 'store_id', 'business_date'] as $key) {
      $value = $request?->query->get($key);
      if (is_scalar($value)) $query[$key] = (string)$value;
    }

    return [
      '#theme' => 'merdpos_surface',
      '#surface' => $this->parity->section($section, $query),
      '#attached' => ['library' => ['merdpos_core/dashboard']],
      '#cache' => ['contexts' => ['user.roles', 'user.permissions', 'url.query_args'], 'max-age' => 0],
    ];
  }

}
