<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$fixture=(string)file_get_contents($root.'/backend/cli/administration_e2e_fixture.php');
function admin_fixture_check(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
admin_fixture_check(str_contains($fixture,"UPPER(client_code)='DUMMY'"),'Exact DUMMY client boundary missing.');
admin_fixture_check(str_contains($fixture,"MERD_ADMIN_E2E_PREFIX = 'AUTOTEST Administration E2E'"),'AUTOTEST prefix missing.');
admin_fixture_check(str_contains($fixture,"MERD_ADMIN_E2E_CLIENT_PREFIX = 'DUMMYADM'"),'Temporary client prefix missing.');
admin_fixture_check(str_contains($fixture,"/home/dridsheikh/.merdpos-test/"),'Private fixture path guard missing.');
admin_fixture_check(str_contains($fixture,'chmod($output,0600)'),'Private fixture permissions missing.');
admin_fixture_check(str_contains($fixture,'cleanup boundary count exceeded'),'Bounded cleanup guard missing.');
admin_fixture_check(str_contains($fixture,'temporary client unexpectedly owns operational rows'),'Temporary-client deletion guard missing.');
admin_fixture_check(str_contains($fixture,'ADMIN_E2E_AUDIT'),'Residue audit missing.');
admin_fixture_check(!str_contains($fixture,"client_code='MRG'"),'Fixture must never target MRG.');
echo "MERDPOS Administration DUMMY E2E fixture contract validated.\n";
