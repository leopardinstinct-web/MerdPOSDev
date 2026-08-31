<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/timesheet_portal/includes/ui_studio_history.php';

function studio_inheritance_fail(string $message): never
{
    fwrite(STDERR, "MERDPOS Studio inheritance validation FAILED: {$message}\n");
    exit(1);
}

$devHide = ['kind'=>'style','scope'=>'element','selector'=>'#studio-test','property'=>'display','value'=>'none','roleScope'=>'DEV'];
$userLegacyHide = ['kind'=>'style','scope'=>'element','selector'=>'#studio-test','property'=>'display','value'=>'none','roleScope'=>'USER'];
$userExplicitHide = $userLegacyHide;
$userExplicitHide['explicitOverride'] = true;

$collapsed = merd_ui_studio_normalize_patches([$devHide, $userLegacyHide]);
if (count($collapsed) !== 1 || ($collapsed[0]['roleScope'] ?? '') !== 'DEV') {
    studio_inheritance_fail('legacy duplicate child hide did not collapse into the DEV master.');
}
$preserved = merd_ui_studio_normalize_patches([$devHide, $userExplicitHide]);
if (count($preserved) !== 2) {
    studio_inheritance_fail('explicit USER override was not preserved.');
}
$setDev = merd_ui_studio_patch_mutation([], [$devHide]);
$setLegacy = merd_ui_studio_patch_mutation([$devHide], [$devHide, $userLegacyHide]);
$undoDev = merd_ui_studio_patch_mutation([$devHide], []);
$legacyReplay = merd_ui_studio_replay_mutations([$setDev, $setLegacy, $undoDev]);
if ($legacyReplay !== []) {
    studio_inheritance_fail('DEV master Undo resurrected a legacy child duplicate.');
}

$setExplicit = merd_ui_studio_patch_mutation([$devHide], [$devHide, $userExplicitHide]);
$explicitReplay = merd_ui_studio_replay_mutations([$setDev, $setExplicit, $undoDev]);
if (count($explicitReplay) !== 1 || empty($explicitReplay[0]['explicitOverride'])
    || ($explicitReplay[0]['roleScope'] ?? '') !== 'USER') {
    studio_inheritance_fail('explicit USER override did not survive DEV master Undo.');
}

echo "MERDPOS Studio inheritance/Undo semantics validated.\n";

$inboxPatch = merd_ui_studio_normalize_patches([[
    'kind'=>'request','requestType'=>'comment','requestKey'=>'receipt-test-1','selector'=>'#studio-test',
    'roleScope'=>'ADMIN','requestedFromPreview'=>'ADMIN','status'=>'proposed'
]]);
if (count($inboxPatch) !== 1 || !preg_match('/^patch-[a-z0-9_-]{8,80}$/i', (string)($inboxPatch[0]['patchId'] ?? ''))
    || ($inboxPatch[0]['status'] ?? '') !== 'pending') {
    studio_inheritance_fail('unresolved inbox patch did not receive stable patchId + pending status.');
}
$confirmed = $inboxPatch[0];
$confirmed['status'] = 'confirmed_applied';
if (merd_ui_studio_normalize_patches([$confirmed]) !== []) {
    studio_inheritance_fail('confirmed_applied patch remained in active Studio state.');
}
$changed = $inboxPatch[0];
$changed['selector'] = '#studio-test-renamed';
$mutation = merd_ui_studio_patch_mutation($inboxPatch, [$changed]);
if (($mutation['remove'] ?? []) !== [] || count($mutation['set'] ?? []) !== 1) {
    studio_inheritance_fail('patchId did not remain the mutation identity after selector change.');
}

echo "MERDPOS Studio unresolved-inbox identity/status semantics validated.\n";
