<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\merdpos_core\Integration\ParityDataProviderInterface;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use Drupal\merdpos_core\Presentation\DashboardChartBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class FinanceController extends ControllerBase {

  private const TOKEN_ID = 'merdpos_finance_write_v1';

  public function __construct(
    private readonly ParityDataProviderInterface $parity,
    private readonly DashboardChartBuilder $chartBuilder,
    private readonly RequestStack $requestStack,
    private readonly PortalGatewayClientInterface $gateway,
    private readonly CsrfTokenGenerator $csrf,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('merdpos_core.parity_provider'),
      $container->get('merdpos_core.dashboard_chart_builder'),
      $container->get('request_stack'),
      $container->get('merdpos_core.portal_gateway'),
      $container->get('csrf_token'),
    );
  }

  public function finance(): array|RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request) throw new AccessDeniedHttpException();
    if ($request->isMethod('POST')) return $this->handlePost($request);

    $stateResult = $this->gateway->call('beta_state', 'GET');
    $state = is_array($stateResult['payload'] ?? NULL) ? $stateResult['payload'] : [];
    $permissions = $this->permissionKeys($state['permissions'] ?? []);
    if (($stateResult['status'] ?? '') !== 'ok' || !in_array('finance.view', $permissions, true)) {
      throw new AccessDeniedHttpException('MERDPOS finance.view permission is required.');
    }

    $query = [];
    foreach (['store_id','business_date'] as $key) {
      $value = $request->query->get($key);
      if (is_scalar($value)) $query[$key] = (string) $value;
    }
    $surface = $this->parity->section('finance', $query);
    if (($surface['status'] ?? '') === 'forbidden') {
      throw new AccessDeniedHttpException('MERDPOS Finance access was rejected.');
    }
    $surface['write'] = [
      'can_submit' => in_array('finance.submit', $permissions, true),
      'can_open_day' => in_array('finance.open_day', $permissions, true) && !empty($surface['selected_store']['can_open_day']),
      'form_token' => $this->csrf->get(self::TOKEN_ID),
      'post_url' => Url::fromRoute('merdpos_core.finance')->toString(),
    ];

    return [
      '#theme' => 'merdpos_finance',
      '#surface' => $surface,
      '#charts' => $this->chartBuilder->build($surface['chart_specs'] ?? []),
      '#attached' => ['library' => ['merdpos_core/finance']],
      '#cache' => ['contexts'=>['user','url.query_args:store_id','url.query_args:business_date'],'max-age'=>0],
    ];
  }

  private function handlePost(Request $request): RedirectResponse {
    $storeId = filter_var($request->request->get('store_id'), FILTER_VALIDATE_INT);
    $storeId = $storeId !== false && $storeId > 0 ? (int) $storeId : 0;
    $businessDate = trim((string) $request->request->get('business_date', ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) $businessDate = '';

    $token = (string) $request->request->get('form_token', '');
    if (!$this->csrf->validate($token, self::TOKEN_ID)) {
      $this->messenger()->addError('This Financials form expired. Refresh the page and try again.');
      return $this->redirectBack($storeId, $businessDate);
    }
    if ($storeId <= 0 || $businessDate === '') {
      $this->messenger()->addError('Choose a valid store and business date.');
      return $this->redirectBack($storeId, $businessDate);
    }

    $stateResult = $this->gateway->call('beta_state', 'GET');
    $state = is_array($stateResult['payload'] ?? NULL) ? $stateResult['payload'] : [];
    $permissions = $this->permissionKeys($state['permissions'] ?? []);
    if (($stateResult['status'] ?? '') !== 'ok' || !in_array('finance.view', $permissions, true)) {
      throw new AccessDeniedHttpException('MERDPOS finance.view permission is required.');
    }

    $action = strtolower(trim((string) $request->request->get('finance_action', '')));
    $requiredPermission = $action === 'open_day' ? 'finance.open_day' : 'finance.submit';
    if (!in_array($requiredPermission, $permissions, true)) {
      $this->messenger()->addError('MERDPOS does not allow this financial action for your role.');
      return $this->redirectBack($storeId, $businessDate);
    }

    $submission = $this->buildSubmission($request, $action, $storeId, $businessDate);
    if (isset($submission['error'])) {
      $this->messenger()->addError((string) $submission['error']);
      return $this->redirectBack($storeId, $businessDate);
    }

    $result = $this->gateway->call('financials', 'POST', [], $submission);
    $payload = is_array($result['payload'] ?? NULL) ? $result['payload'] : [];
    if (($result['status'] ?? '') === 'ok' && !empty($payload['success'])) {
      $inner = is_array($payload['result'] ?? NULL) ? $payload['result'] : [];
      $this->messenger()->addStatus($this->successMessage($action, !empty($inner['duplicate'])));
    }
    else {
      $error = trim((string) ($payload['error'] ?? $result['message'] ?? 'The financial action could not be completed.'));
      $this->messenger()->addError($error ?: 'The financial action could not be completed.');
    }
    return $this->redirectBack($storeId, $businessDate);
  }

  private function buildSubmission(Request $request, string $action, int $storeId, string $businessDate): array {
    $submissionType = $action;
    $payload = [];
    if ($action === 'open_day') {
      $register = $this->amount($request->request->get('register_opening'));
      $petty = $this->amount($request->request->get('petty_cash_opening'));
      if ($register === NULL || $petty === NULL) return ['error'=>'Enter valid opening balances.'];
      $payload = ['register_opening'=>$register, 'petty_cash_opening'=>$petty];
    }
    elseif ($action === 'cash_movement') {
      $submissionType = strtolower(trim((string) $request->request->get('submission_type', '')));
      if (!in_array($submissionType, ['cash_in','cash_out'], true)) return ['error'=>'Choose Cash IN or Cash OUT.'];
      $account = trim((string) $request->request->get('account', ''));
      $head = trim((string) $request->request->get('head', ''));
      $amount = $this->amount($request->request->get('amount'), false);
      if (!in_array($account, ['Register','Petty Cash'], true) || strlen($head) < 2 || strlen($head) > 120 || $amount === NULL) {
        return ['error'=>'Choose an account, enter a clear reason, and use an amount greater than zero.'];
      }
      $payload = ['transactions'=>[['account'=>$account, 'head'=>$head, 'amount'=>$amount]]];
    }
    elseif ($action === 'z_report') {
      $total = $this->amount($request->request->get('register_total'));
      $pettyAddin = $this->amount($request->request->get('petty_cash_addin', '0'));
      if ($total === NULL || $pettyAddin === NULL || $pettyAddin > $total) {
        return ['error'=>'Enter valid closing totals. Petty Cash transfer cannot exceed the Register total.'];
      }
      $denominations = trim((string) $request->request->get('denominations', ''));
      if (strlen($denominations) > 2000) return ['error'=>'Denomination notes are too long.'];
      $payload = [
        'register_total'=>$total,
        'petty_cash_addin'=>$pettyAddin,
        'denominations'=>array_values(array_filter(array_map('trim', explode(',', $denominations)), static fn(string $value): bool => $value !== '')),
      ];
    }
    else {
      return ['error'=>'Invalid financial action.'];
    }

    return [
      'submission_id' => $this->uuidV4(),
      'store_id' => $storeId,
      'business_date' => $businessDate,
      'submission_type' => $submissionType,
      'payload' => $payload,
    ];
  }

  private function amount(mixed $value, bool $allowZero = true): ?float {
    $text = trim((string) $value);
    if ($text === '' || !is_numeric($text)) return NULL;
    $amount = (float) $text;
    if (!is_finite($amount) || $amount < 0 || $amount > 9999999999.99) return NULL;
    if (!$allowZero && $amount <= 0) return NULL;
    return $amount;
  }

  private function uuidV4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return substr($hex,0,8) . '-' . substr($hex,8,4) . '-' . substr($hex,12,4) . '-' . substr($hex,16,4) . '-' . substr($hex,20,12);
  }

  private function redirectBack(int $storeId, string $businessDate): RedirectResponse {
    $query = [];
    if ($storeId > 0) $query['store_id'] = (string) $storeId;
    if ($businessDate !== '') $query['business_date'] = $businessDate;
    return new RedirectResponse(Url::fromRoute('merdpos_core.finance', [], ['query'=>$query])->toString());
  }

  private function permissionKeys(mixed $value): array {
    if (!is_array($value)) return [];
    if (array_is_list($value)) return array_values(array_filter(array_map('strval', $value)));
    $keys = [];
    foreach ($value as $key => $enabled) if (is_string($key) && $enabled) $keys[] = $key;
    return array_values(array_unique($keys));
  }

  private function successMessage(string $action, bool $duplicate): string {
    if ($duplicate) return 'MERDPOS already recorded this financial submission. No duplicate transaction was created.';
    return match ($action) {
      'open_day' => 'Financial day opened successfully.',
      'cash_movement' => 'Cash movement saved successfully.',
      'z_report' => 'Financial day closed successfully.',
      default => 'Financial action completed successfully.',
    };
  }
}
