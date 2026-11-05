<?php
require dirname(__DIR__).'/backend/bootstrap.php';require dirname(__DIR__).'/backend/projects.php';require dirname(__DIR__).'/backend/hosted-writes.php';
$input=json_decode((string)fgets(STDIN),true);$_SESSION=$input['session'];echo "READY\n";flush();if(trim((string)fgets(STDIN))!=='GO')exit(2);
try{$out=$input['action']==='assign'?hostedWrite('assign_lead',$input['data']):updateMembership($input['id'],$input['data']);echo json_encode(['status'=>200,'result'=>$out])."\n";}
catch(Throwable $e){if(db()->inTransaction())db()->rollBack();echo json_encode(['status'=>$e->getCode(),'error'=>$e->getMessage()])."\n";}
