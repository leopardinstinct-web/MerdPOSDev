<?php
declare(strict_types=1);

function merd_ui_studio_role_key(mixed $value): string
{
    $role = strtoupper(trim((string)$value));
    return in_array($role, ['DEV','ADMIN','SUPER','USER'], true) ? $role : 'DEV';
}
function merd_ui_studio_role_targets(string $role): array
{
    return match (merd_ui_studio_role_key($role)) {
        'DEV' => ['DEV','ADMIN','SUPER','USER'],
        'ADMIN' => ['ADMIN','SUPER','USER'],
        'SUPER' => ['SUPER','USER'],
        'USER' => ['USER'],
    };
}
function merd_ui_studio_request_targets(string $role): array
{
    $role = merd_ui_studio_role_key($role);
    return $role === 'DEV' ? ['DEV'] : ['DEV', $role];
}
function merd_ui_studio_role_rank(string $role): int
{
    return match (merd_ui_studio_role_key($role)) { 'DEV'=>0, 'ADMIN'=>1, 'SUPER'=>2, 'USER'=>3 };
}
function merd_ui_studio_style_identity(array $patch): string
{
    return implode('|', [(string)($patch['scope'] ?? 'element'), (string)($patch['selector'] ?? ''), (string)($patch['property'] ?? '')]);
}

function merd_ui_studio_patch_status(mixed $value): string
{
    $status = strtolower(trim((string)$value));
    if ($status === 'proposed') $status = 'pending';
    return in_array($status, ['pending','implementing','implemented','blocked','confirmed_applied'], true) ? $status : 'pending';
}
function merd_ui_studio_patch_id(array $patch): string
{
    $existing = trim((string)($patch['patchId'] ?? ''));
    if (preg_match('/^patch-[a-z0-9_-]{8,80}$/i', $existing)) return $existing;
    $seed = (string)($patch['requestKey'] ?? $patch['addedKey'] ?? $patch['contextKey'] ?? $patch['createdAt'] ?? '');
    if ($seed === '') $seed = json_encode([$patch['kind'] ?? '', $patch['roleScope'] ?? 'DEV', $patch['selector'] ?? '', $patch['property'] ?? ''], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return 'patch-' . substr(hash('sha256', $seed), 0, 24);
}

function merd_ui_studio_patch_key(array $patch): string
{
    $patchId = trim((string)($patch['patchId'] ?? ''));
    if ($patchId !== '') return 'id|' . $patchId;
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
        'request' => implode('|', [
            'request', (string)($patch['requestedFromPreview'] ?? $role),
            (string)($patch['requestType'] ?? ''), (string)($patch['requestKey'] ?? ''), $selector,
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
        if (!in_array($kind, ['style','text','move','add','comment','request'], true)) continue;
        $patch['patchId'] = merd_ui_studio_patch_id($patch);
        $patch['status'] = merd_ui_studio_patch_status($patch['status'] ?? 'pending');
        if ($patch['status'] === 'confirmed_applied') continue;
        $role = merd_ui_studio_role_key($patch['roleScope'] ?? 'DEV');
        if (($kind === 'add' || $kind === 'comment') && $role !== 'DEV') {
            $patch['kind'] = 'request';
            $patch['requestType'] = $kind;
            $patch['requestedFromPreview'] = $role;
            $patch['implementationOrigin'] = 'DEV';
            $patch['status'] = merd_ui_studio_patch_status($patch['status'] ?? 'pending');
            $patch['requestKey'] = (string)($patch['requestKey'] ?? $patch['addedKey'] ?? $patch['contextKey'] ?? $patch['createdAt'] ?? $patch['selector'] ?? 'legacy-request');
            $patch['roleScope'] = $role;
            $patch['roleTargets'] = merd_ui_studio_request_targets($role);
        } elseif ($kind === 'request') {
            $preview = merd_ui_studio_role_key($patch['requestedFromPreview'] ?? $role);
            $patch['kind'] = 'request';
            $patch['requestedFromPreview'] = $preview;
            $patch['implementationOrigin'] = 'DEV';
            $patch['status'] = merd_ui_studio_patch_status($patch['status'] ?? 'pending');
            $patch['roleScope'] = $preview;
            $patch['roleTargets'] = merd_ui_studio_request_targets($preview);
        } else {
            $patch['kind'] = $kind;
            $patch['roleScope'] = $role;
            $patch['roleTargets'] = merd_ui_studio_role_targets($role);
        }
        $patches[] = $patch;
    }
    $allPatches = $patches;
    $patches = array_values(array_filter($patches, static function(array $patch) use ($allPatches): bool {
        if (($patch['kind'] ?? '') !== 'style' || !empty($patch['explicitOverride']) || merd_ui_studio_role_key($patch['roleScope'] ?? 'DEV') === 'DEV') return true;
        $rank = merd_ui_studio_role_rank((string)($patch['roleScope'] ?? 'DEV'));
        $identity = merd_ui_studio_style_identity($patch);
        foreach ($allPatches as $other) {
            if (($other['kind'] ?? '') !== 'style') continue;
            if (merd_ui_studio_role_rank((string)($other['roleScope'] ?? 'DEV')) >= $rank) continue;
            if (merd_ui_studio_style_identity($other) !== $identity) continue;
            if ((string)($other['value'] ?? '') === (string)($patch['value'] ?? '')) return false;
        }
        return true;
    }));
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
    $before = merd_ui_studio_normalize_patches($before);
    $after = merd_ui_studio_normalize_patches($after);
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
        $patches = merd_ui_studio_normalize_patches($patches);
    }
    return $patches;
}

function merd_ui_studio_public_id(): string
{
    return 'studio-' . bin2hex(random_bytes(16));
}
