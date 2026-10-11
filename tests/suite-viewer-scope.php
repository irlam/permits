<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/PermitAccess.php';
$user=['id'=>'viewer','email'=>'viewer@example.invalid','role'=>'user'];
$check=static function(bool $ok):void{if(!$ok)throw new RuntimeException('Permit scope regression');};
$check(!\Permits\PermitAccess::canViewAll($user));
$GLOBALS['suite_gate_binding']=['module_key'=>'permits'];$GLOBALS['suite_gate_identity']=['role'=>'viewer'];
$check(!\Permits\PermitAccess::canViewAll($user));
$_SESSION['suite_gate']=['token'=>str_repeat('a',64)];
$check(\Permits\PermitAccess::sqlScope($user)['sql']==='1 = 1');
$GLOBALS['suite_gate_binding']['module_key']='documents';$check(!\Permits\PermitAccess::canViewAll($user));
$GLOBALS['suite_gate_binding']['module_key']='permits';$GLOBALS['suite_gate_identity']['role']='user';$check(!\Permits\PermitAccess::canViewAll($user));
unset($GLOBALS['suite_gate_binding'],$GLOBALS['suite_gate_identity'],$_SESSION['suite_gate']);
$check(\Permits\PermitAccess::canAccessPermit($user,['holder_id'=>'viewer']));
$check(!\Permits\PermitAccess::canAccessPermit($user,['holder_id'=>'other','holder_email'=>'other@example.invalid']));
echo "7 dedicated Suite Viewer and standalone ownership checks passed.\n";
