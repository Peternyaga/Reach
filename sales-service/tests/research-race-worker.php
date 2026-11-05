<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/backend/bootstrap.php';
require dirname(__DIR__).'/backend/projects.php';
require dirname(__DIR__).'/backend/lead-research.php';
$input=json_decode((string)fgets(STDIN),true,32,JSON_THROW_ON_ERROR);
$_SESSION=$input['session'];setProjectContext($input['project']);
echo "READY\n";flush();
if(trim((string)fgets(STDIN))!=='GO')exit(2);
try{$result=updateLeadResearch($input['lead'],$input['payload'],true);echo json_encode(['status'=>200,'result'=>$result],JSON_THROW_ON_ERROR)."\n";}
catch(RuntimeException $e){echo json_encode(['status'=>$e->getCode(),'error'=>$e->getMessage()],JSON_THROW_ON_ERROR)."\n";}
