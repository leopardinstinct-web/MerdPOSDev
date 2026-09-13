<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Presentation;

use Drupal\Core\State\StateInterface;
use InvalidArgumentException;

final class BrandPaletteManager {
  public const STATE_KEY = 'merdpos_core.brand_palette_v1';

  private const ROLE_LABELS = [
    'foundation' => 'Foundation / navigation',
    'accent' => 'Primary accent',
    'secondary' => 'Secondary accent',
  ];

  private const SWATCHES = [
    'brand_navy' => ['label'=>'Brand Navy', 'hex'=>'#031B4B'],
    'logo_midnight' => ['label'=>'Logo Midnight', 'hex'=>'#01102B'],
    'logo_royal' => ['label'=>'Logo Royal Blue', 'hex'=>'#0747FC'],
    'logo_blue' => ['label'=>'Logo Electric Blue', 'hex'=>'#005FF7'],
    'logo_sky' => ['label'=>'Logo Sky Blue', 'hex'=>'#0A91FB'],
    'brand_cyan' => ['label'=>'Brand Cyan', 'hex'=>'#12BDF3'],
    'logo_indigo' => ['label'=>'Logo Indigo', 'hex'=>'#3B3FA0'],
    'logo_violet' => ['label'=>'Logo Electric Violet', 'hex'=>'#591DE9'],
    'logo_orchid' => ['label'=>'Logo Orchid', 'hex'=>'#7943F5'],
    'brand_violet' => ['label'=>'Brand Violet', 'hex'=>'#8B2EFF'],
  ];

  private const DEFAULT_STATE = [
    'entries' => [
      ['id'=>'navy', 'label'=>'Brand Navy', 'swatch'=>'brand_navy'],
      ['id'=>'cyan', 'label'=>'Brand Cyan', 'swatch'=>'brand_cyan'],
      ['id'=>'violet', 'label'=>'Brand Violet', 'swatch'=>'brand_violet'],
    ],
    'roles' => ['foundation'=>'navy', 'accent'=>'cyan', 'secondary'=>'violet'],
  ];

  public function __construct(private readonly StateInterface $state) {}

  public function snapshot(): array {
    $state = $this->rawState();
    $roles = $state['roles'];
    $entries = [];
    foreach ($state['entries'] as $entry) {
      $source = self::SWATCHES[$entry['swatch']];
      $entry['hex'] = $source['hex'];
      $entry['swatch_label'] = $source['label'];
      $entry['in_use'] = in_array($entry['id'], array_values($roles), true);
      $entries[] = $entry;
    }
    return [
      'entries'=>$entries,
      'roles'=>$roles,
      'role_labels'=>self::ROLE_LABELS,
      'swatches'=>$this->swatchOptions(),
      'fixed_white'=>'#FFFFFF',
    ];
  }

  public function cssVariables(): array {
    $state = $this->rawState();
    $hexById = [];
    foreach ($state['entries'] as $entry) {
      $hexById[$entry['id']] = self::SWATCHES[$entry['swatch']]['hex'];
    }
    return [
      '--color-brand-navy'=>$hexById[$state['roles']['foundation']],
      '--color-brand-cyan'=>$hexById[$state['roles']['accent']],
      '--color-brand-violet'=>$hexById[$state['roles']['secondary']],
    ];
  }

  public function save(array $labels, array $swatches, array $roles): string {
    $state = $this->rawState();
    $entries = [];
    $usedSwatches = [];
    foreach ($state['entries'] as $entry) {
      $id = $entry['id'];
      $label = $this->cleanLabel((string) ($labels[$id] ?? ''));
      $swatch = (string) ($swatches[$id] ?? '');
      if (!isset(self::SWATCHES[$swatch])) throw new InvalidArgumentException('Choose only colours from the approved MERDPOS logo palette.');
      if (isset($usedSwatches[$swatch])) throw new InvalidArgumentException('Each logo colour can appear only once in the master palette.');
      $usedSwatches[$swatch] = true;
      $entries[] = ['id'=>$id, 'label'=>$label, 'swatch'=>$swatch];
    }
    $validIds = array_fill_keys(array_column($entries, 'id'), true);
    $cleanRoles = [];
    foreach (array_keys(self::ROLE_LABELS) as $role) {
      $id = (string) ($roles[$role] ?? '');
      if (!isset($validIds[$id])) throw new InvalidArgumentException('Every brand role must use an active palette entry.');
      $cleanRoles[$role] = $id;
    }
    if (count(array_unique($cleanRoles)) !== count($cleanRoles)) throw new InvalidArgumentException('Foundation, primary accent and secondary accent must use different palette entries.');
    $this->state->set(self::STATE_KEY, ['entries'=>$entries, 'roles'=>$cleanRoles]);
    return 'Master palette saved and applied globally.';
  }

  public function add(): string {
    $state = $this->rawState();
    $used = array_fill_keys(array_column($state['entries'], 'swatch'), true);
    $swatch = '';
    foreach (array_keys(self::SWATCHES) as $candidate) {
      if (!isset($used[$candidate])) { $swatch = $candidate; break; }
    }
    if ($swatch === '') throw new InvalidArgumentException('All approved MERDPOS logo colours are already in the master palette.');
    $id = 'p_' . bin2hex(random_bytes(4));
    $state['entries'][] = ['id'=>$id, 'label'=>self::SWATCHES[$swatch]['label'], 'swatch'=>$swatch];
    $this->state->set(self::STATE_KEY, $state);
    return 'Logo colour added to the master palette.';
  }

  public function delete(string $id): string {
    $state = $this->rawState();
    if (in_array($id, array_values($state['roles']), true)) throw new InvalidArgumentException('This colour is assigned to a brand role. Reassign that role before deleting it.');
    if (count($state['entries']) <= 3) throw new InvalidArgumentException('The master palette must keep at least three active logo colours.');
    $before = count($state['entries']);
    $state['entries'] = array_values(array_filter($state['entries'], static fn(array $entry): bool => $entry['id'] !== $id));
    if (count($state['entries']) === $before) throw new InvalidArgumentException('Palette entry not found.');
    $this->state->set(self::STATE_KEY, $state);
    return 'Palette colour deleted.';
  }

  public function move(string $id, int $direction): string {
    $state = $this->rawState();
    $index = array_search($id, array_column($state['entries'], 'id'), true);
    if ($index === false) throw new InvalidArgumentException('Palette entry not found.');
    $target = $index + ($direction < 0 ? -1 : 1);
    if ($target < 0 || $target >= count($state['entries'])) return 'Palette order unchanged.';
    [$state['entries'][$index], $state['entries'][$target]] = [$state['entries'][$target], $state['entries'][$index]];
    $this->state->set(self::STATE_KEY, $state);
    return 'Palette order updated.';
  }

  public function reset(): string {
    $this->state->set(self::STATE_KEY, self::DEFAULT_STATE);
    return 'Master palette reset to MERDPOS defaults.';
  }

  private function rawState(): array {
    $raw = $this->state->get(self::STATE_KEY, self::DEFAULT_STATE);
    if (!is_array($raw) || !is_array($raw['entries'] ?? NULL) || !is_array($raw['roles'] ?? NULL)) return self::DEFAULT_STATE;
    $entries = [];
    $seenIds = [];
    $seenSwatches = [];
    foreach ($raw['entries'] as $entry) {
      if (!is_array($entry)) return self::DEFAULT_STATE;
      $id = strtolower(trim((string) ($entry['id'] ?? '')));
      $swatch = (string) ($entry['swatch'] ?? '');
      if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $id) || isset($seenIds[$id]) || !isset(self::SWATCHES[$swatch]) || isset($seenSwatches[$swatch])) return self::DEFAULT_STATE;
      try { $label = $this->cleanLabel((string) ($entry['label'] ?? '')); }
      catch (InvalidArgumentException) { return self::DEFAULT_STATE; }
      $seenIds[$id] = true;
      $seenSwatches[$swatch] = true;
      $entries[] = ['id'=>$id, 'label'=>$label, 'swatch'=>$swatch];
    }
    if (count($entries) < 3) return self::DEFAULT_STATE;
    $roles = [];
    foreach (array_keys(self::ROLE_LABELS) as $role) {
      $id = (string) ($raw['roles'][$role] ?? '');
      if (!isset($seenIds[$id])) return self::DEFAULT_STATE;
      $roles[$role] = $id;
    }
    if (count(array_unique($roles)) !== count($roles)) return self::DEFAULT_STATE;
    return ['entries'=>$entries, 'roles'=>$roles];
  }

  private function swatchOptions(): array {
    $options = [];
    foreach (self::SWATCHES as $key=>$swatch) $options[] = ['key'=>$key, 'label'=>$swatch['label'], 'hex'=>$swatch['hex']];
    return $options;
  }

  private function cleanLabel(string $label): string {
    $label = trim($label);
    if ($label === '' || mb_strlen($label) > 40 || preg_match('/[\x00-\x1F\x7F]/', $label)) throw new InvalidArgumentException('Palette labels must be 1-40 printable characters.');
    return $label;
  }
}
