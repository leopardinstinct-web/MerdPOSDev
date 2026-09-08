<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$fixture=(string)file_get_contents($root.'/backend/cli/finance_e2e_fixture.php');
$runner=(string)file_get_contents($root.'/browser_tests/live-dummy-finance-acceptance.js');
function fin_e2e_check(bool $ok,string $m): void { if(!$ok) throw new RuntimeException($m); }
fin_e2e_check(str_contains($fixture,"UPPER(client_code)='DUMMY'"),'Exact DUMMY boundary missing.');
fin_e2e_check(str_contains($fixture,"const FINFX='AUTOTEST Finance E2E'"),'Finance AUTOTEST prefix missing.');
fin_e2e_check(str_contains($fixture,"/home/dridsheikh/.merdpos-test/"),'Private fixture path guard missing.');
fin_e2e_check(str_contains($fixture,'cleanup boundary exceeded'),'Bounded cleanup guard missing.');
fin_e2e_check(str_contains($fixture,'FINANCE_E2E_AUDIT'),'Finance residue audit missing.');
fin_e2e_check(str_contains($fixture,'attendance_disputes'),'Search fixture dispute missing.');
fin_e2e_check(str_contains($runner,'insufficient_balance'),'Insufficient-balance acceptance missing.');
fin_e2e_check(str_contains($runner,'idempotency_conflict'),'Idempotency-conflict acceptance missing.');
fin_e2e_check(str_contains($runner,'WRITE_WAIT_MS'),'Finance write pacing missing.');
fin_e2e_check(!str_contains($fixture,"client_code='MRG'"),'Finance fixture must never target MRG.');
echo "MERDPOS Finance DUMMY E2E fixture + acceptance contract validated.\n";
