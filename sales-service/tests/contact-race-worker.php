<?php
require dirname(__DIR__).'/backend/bootstrap.php';require dirname(__DIR__).'/backend/hosted-writes.php';
$input=json_decode((string)fgets(STDIN),true);$_SESSION=$input['session'];echo "READY\n";flush();if(trim((string)fgets(STDIN))!=='GO')exit(2);
try{echo json_encode(['status'=>200,'result'=>editLeadContacts($input['id'],$input['data'])])."\n";}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();echo json_encode(['status'=>$e->getCode(),'error'=>$e->getMessage()])."\n";}
