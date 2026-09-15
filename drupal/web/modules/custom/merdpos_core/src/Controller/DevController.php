<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\merdpos_core\Auth\MerdposIdentityManager;
use Drupal\merdpos_core\Integration\ParityDataProviderInterface;
use Drupal\merdpos_core\Presentation\BrandPaletteManager;
use Drupal\merdpos_core\Presentation\DashboardChartBuilder;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class DevController extends ControllerBase {
  private const PALETTE_TOKEN_ID = 'merdpos_dev_palette_v1';

  public function __construct(
    private readonly ParityDataProviderInterface $parity,
    private readonly DashboardChartBuilder $chartBuilder,
    private readonly RequestStack $requestStack,
    private readonly BrandPaletteManager $palette,
    private readonly MerdposIdentityManager $identity,
    private readonly CsrfTokenGenerator $csrf,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('merdpos_core.parity_provider'),
      $container->get('merdpos_core.dashboard_chart_builder'),
      $container->get('request_stack'),
      $container->get('merdpos_core.brand_palette'),
      $container->get('merdpos_core.identity_manager'),
      $container->get('csrf_token'),
    );
  }

  public function dev(): array|RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    if (!$this->isActualDev()) return new RedirectResponse('/merdpos', 302);
    $impersonatedEmployeeId = (int) ($request?->getSession()->get('merdpos_context_employee_id', 0) ?? 0);
    if ($impersonatedEmployeeId > 0) return new RedirectResponse('/merdpos', 302);
    $surface = $this->parity->section('dev');
    return [
      '#theme' => 'merdpos_dev',
      '#surface' => $surface,
      '#charts' => $this->chartBuilder->build($surface['chart_specs'] ?? []),
      '#release' => $this->releaseMarker(),
      '#local_runtime' => ['drupal'=>\Drupal::VERSION, 'php'=>PHP_VERSION, 'environment'=>'Drupal Beta'],
      '#palette' => $this->palette->snapshot(),
      '#palette_token' => $this->csrf->get(self::PALETTE_TOKEN_ID),
      '#palette_post_url' => Url::fromRoute('merdpos_core.dev_palette')->toString(),
      '#attached' => ['library' => ['merdpos_core/dev']],
      '#cache' => ['contexts'=>['user'],'max-age'=>0],
    ];
  }

  public function palette(): RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request || !$this->isActualDev()) throw new AccessDeniedHttpException();
    $impersonatedEmployeeId = (int) $request->getSession()->get('merdpos_context_employee_id', 0);
    if ($impersonatedEmployeeId > 0) throw new AccessDeniedHttpException();
    if (!$this->csrf->validate((string) $request->request->get('form_token', ''), self::PALETTE_TOKEN_ID)) {
      $this->messenger()->addError($this->t('Your palette session expired. Refresh DEV and try again.'));
      return new RedirectResponse('/merdpos/dev#merdpos-master-palette', 303);
    }
    try {
      $action = (string) $request->request->get('palette_action', 'save');
      $message = match (true) {
        $action === 'save' => $this->palette->save($request->request->all('labels'), $request->request->all('swatches'), $request->request->all('roles')),
        $action === 'add' => $this->palette->add(),
        $action === 'reset' => $this->palette->reset(),
        str_starts_with($action, 'delete:') => $this->palette->delete(substr($action, 7)),
        str_starts_with($action, 'move_up:') => $this->palette->move(substr($action, 8), -1),
        str_starts_with($action, 'move_down:') => $this->palette->move(substr($action, 10), 1),
        default => throw new InvalidArgumentException('Unsupported palette action.'),
      };
      $this->messenger()->addStatus($message);
      $this->getLogger('merdpos_core')->notice('DEV master palette action @action by Drupal uid @uid.', ['@action'=>$action, '@uid'=>$this->currentUser()->id()]);
    }
    catch (InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    return new RedirectResponse('/merdpos/dev#merdpos-master-palette', 303);
  }

  private function isActualDev(): bool {
    $account = $this->currentUser();
    if (!$account->isAuthenticated()) return false;
    $profile = $this->identity->profile((int) $account->id());
    $key = strtoupper(trim((string) ($profile['role_key'] ?? $profile['role'] ?? '')));
    return $key === 'DEV';
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
