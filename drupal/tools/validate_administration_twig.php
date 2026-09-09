<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$loader = new Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/web/modules/custom/merdpos_core/templates');
$twig = new Twig\Environment($loader);
$twig->addFunction(new Twig\TwigFunction('path', static fn(string $route, array $parameters = [], array $options = []): string => '/merdpos/admin'));
$twig->load('merdpos-administration.html.twig');

echo "MERDPOS Drupal Administration Twig syntax validated.\n";

$cases = [
 ['permission_key'=>'both.on','label'=>'On','category'=>'Test','super_available'=>true,'user_available'=>true,'super_enabled'=>true,'user_enabled'=>true],
 ['permission_key'=>'both.off','label'=>'Off','category'=>'Test','super_available'=>true,'user_available'=>true,'super_enabled'=>false,'user_enabled'=>false],
 ['permission_key'=>'super.only','label'=>'Super','category'=>'Test','super_available'=>true,'user_available'=>false,'super_enabled'=>true,'user_enabled'=>false],
 ['permission_key'=>'unavailable','label'=>'Unavailable','category'=>'Test','super_available'=>false,'user_available'=>false,'super_enabled'=>true,'user_enabled'=>true],
 ['permission_key'=>'dev.only','label'=>'Dev','category'=>'Test','dev_only'=>true,'super_available'=>true,'user_available'=>true,'super_enabled'=>true,'user_enabled'=>true],
];
$html=$twig->render('merdpos-administration.html.twig',[
 'can_manage_roles'=>true,'role_state'=>['can_manage_usability'=>true,'can_manage_permissions'=>false,'permissions'=>$cases],
]);
$dom=new DOMDocument; @$dom->loadHTML($html); $xpath=new DOMXPath($dom);
foreach (['SUPER'=>['both.on','both.off','super.only'],'USER'=>['both.on','both.off']] as $role=>$expected) {
 $form=$xpath->query('//form[input[@name="entity_action" and @value="save_role_usability"] and input[@name="role_key" and @value="'.$role.'"]]')->item(0);
 if (!$form) throw new RuntimeException('Missing '.$role.' usability form');
 $keys=[]; foreach($xpath->query('.//input[@name="permission_keys[]" and not(@disabled)]',$form) as $input) $keys[]=$input->getAttribute('value');
 if ($keys!==$expected) throw new RuntimeException($role.' submits keys outside its ceiling or drops unchecked available keys');
 $enabled=[]; foreach($xpath->query('.//input[@name="enabled_keys[]" and @checked and not(@disabled)]',$form) as $input) $enabled[]=$input->getAttribute('value');
 if ($enabled!==array_values(array_diff($expected,['both.off']))) throw new RuntimeException('Native checkbox payload mismatch');
}
echo "ADMIN rendered form payload validated for SUPER/USER: unavailable and DEV-only omitted; unchecked available retained.\n";
