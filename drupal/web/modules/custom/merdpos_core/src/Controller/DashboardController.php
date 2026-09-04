<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\merdpos_core\Integration\ParityDataProviderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly ParityDataProviderInterface $parity,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('merdpos_core.parity_provider'));
  }

  public function dashboard(): array {
    return [
      '#theme' => 'merdpos_surface',
      '#surface' => $this->parity->home(),
      '#attached' => ['library' => ['merdpos_core/dashboard']],
      '#cache' => ['contexts' => ['user.roles', 'user.permissions'], 'max-age' => 0],
    ];
  }

}
