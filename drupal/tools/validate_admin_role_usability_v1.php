<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function need(string $s,string $n,string $m): void { if(!str_contains($s,$n)) throw new RuntimeException($m); }
$c=file_get_contents($root.'/web/modules/custom/merdpos_core/src/Controller/AdministrationController.php') ?: '';
$t=file_get_contents($root.'/web/modules/custom/merdpos_core/templates/merdpos-administration.html.twig') ?: '';
need($c,"'save_role_usability' => \$this->saveRoleUsability",'Drupal POST router lacks role usability action.');
need($c,"'action'=>'save_usability'",'Drupal does not call backend save_usability.');
need($c,"in_array(\$roleKey, ['SUPER', 'USER'], true)",'Drupal usability target is not restricted to SUPER/USER.');
need($t,'role_state.can_define_roles','Twig does not use DEV role-definition capability.');
need($t,'role_state.can_manage_usability','Twig does not use ADMIN usability capability.');
need($t,"can_manage_usability and not can_manage_permissions",'ADMIN usability surface is not separated from DEV policy.');
need($t,'name="entity_action" value="save_role_usability"','Usability forms are not wired.');
need($t,'Unavailable capabilities are outside the DEV-defined ceiling','DEV ceiling is not stated in Drupal UI.');
need($t,"{% if can_define_roles %}",'Role creation is not DEV-gated in Drupal.');
if(str_contains($t,'You can manage custom roles at or below your own authority')) throw new RuntimeException('Obsolete ADMIN custom-role delegation copy remains.');
echo "MERDPOS Drupal ADMIN usability validated: DEV defines roles/LOA/policy; ADMIN configures only SUPER/USER usability within the DEV ceiling.\n";