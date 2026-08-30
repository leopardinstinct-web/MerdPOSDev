<?php
declare(strict_types=1);

function merd_ui_studio_patch_key(array $patch): string
{
    $kind = strtolower(trim((string)($patch['kind'] ?? '')));
    $role = strtoupper(trim((string)($patch['roleScope'] ?? 'DEV')));
    $scope = strtolower(trim((string)($patch['scope'] ?? 'element')));
    $selector = trim((string)($patch['selector'] ?? ''));

    return match ($kind) {
        'style' => implode('|', ['style', $role, $scope, $selector, (string)($patch['property'] ?? '')]),
        'text' => implode('|', ['text', $role, $selector]),
        'move' => implode('|', ['move', $role, $selector]),
        'add' => implode('|', ['add', (string)($patch['addedKey'] ?? $patch['runtimeKey'] ?? $selector)]),
        'comment' => implode('|', [
            'comment', $role, (string)($patch['contextKey'] ?? ''), $selector,
            (string)($patch['createdAt'] ?? ''), (string)($patch['comment'] ?? ''),
        ]),
        default => 'patch|' . hash('sha256', json_encode($patch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    };
}
function merd_ui_studio_normalize_patches(mixed $value): array
{
    if (!is_array($value)) {
        throw new MerdWorkforceException('invalid_studio_patches', 'Studio patches must be an array.');
    }
    if (count($value) > 300) {
        throw new MerdWorkforceException('studio_patch_limit', 'Studio supports up to 300 active preview patches.');
    }
    $patches = [];
    foreach ($value as $patch) {
        if (!is_array($patch)) continue;
        $kind = strtolower(trim((string)($patch['kind'] ?? '')));
        if (!in_array($kind, ['style','text','move','add','comment'], true)) continue;
        $patch['kind'] = $kind;
        $patches[] = $patch;
    }
    $json = json_encode($patches, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (strlen($json) > 524288) {
        throw new MerdWorkforceException('studio_patch_size', 'Studio preview state is too large to synchronize.');
    }
    return $patches;
}
function merd_ui_studio_patch_map(array $patches): array
{
    $map = [];
    foreach ($patches as $patch) {
        if (!is_array($patch)) continue;
        $map[merd_ui_studio_patch_key($patch)] = $patch;
    }
    return $map;
}

function merd_ui_studio_patch_mutation(array $before, array $after): array
{
    $beforeMap = merd_ui_studio_patch_map($before);
    $afterMap = merd_ui_studio_patch_map($after);
    $remove = [];
    $set = [];
    foreach ($beforeMap as $key => $_patch) {
        if (!array_key_exists($key, $afterMap)) $remove[] = $key;
    }
    foreach ($afterMap as $key => $patch) {
        $prior = $beforeMap[$key] ?? null;
        if ($prior === null || json_encode($prior) !== json_encode($patch)) {
            $set[] = ['key' => $key, 'patch' => $patch];
        }
    }
    return ['remove' => $remove, 'set' => $set];
}
function merd_ui_studio_apply_mutation(array $patches, array $mutation): array
{
    $map = merd_ui_studio_patch_map($patches);
    foreach ((array)($mutation['remove'] ?? []) as $key) {
        unset($map[(string)$key]);
    }
    foreach ((array)($mutation['set'] ?? []) as $item) {
        if (!is_array($item) || !is_array($item['patch'] ?? null)) continue;
        $key = trim((string)($item['key'] ?? ''));
        if ($key === '') $key = merd_ui_studio_patch_key($item['patch']);
        $map[$key] = $item['patch'];
    }
    return array_values($map);
}

function merd_ui_studio_replay_mutations(array $mutations): array
{
    $patches = [];
    foreach ($mutations as $mutation) {
        if (!is_array($mutation)) continue;
        $patches = merd_ui_studio_apply_mutation($patches, $mutation);
    }
    return merd_ui_studio_normalize_patches($patches);
}

function merd_ui_studio_public_id(): string
{
    return 'studio-' . bin2hex(random_bytes(16));
}
