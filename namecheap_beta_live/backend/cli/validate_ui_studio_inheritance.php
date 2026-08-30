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
