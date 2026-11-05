<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
require dirname(__DIR__).'/backend/migrations.php';
define('FIELDWORK_HOSTED_LIBRARY',true);
require dirname(__DIR__).'/backend/hosted-router.php';
$wid=uid();$user=uid();$seller=uid();$other=uid();$otherUser=uid();$password=uid();$email=uid().'@local.test';$checks=0;
function ok(bool $value,string $label): void {global $checks;if(!$value)throw new RuntimeException('FAIL '.$label);$checks++;echo "PASS $label\n";}
function rejects(callable $fn,int $code,string $label): void {try{$fn();throw new LogicException('Expected rejection: '.$label);}catch(RuntimeException $e){ok($e->getCode()===$code,$label.' ('.$e->getMessage().')');}finally{if(db()->inTransaction())db()->rollBack();}}
function projectApi(string $route,?array $body=null,?string $project=null): array {
 static $cookie='',$csrf='';
 $h=curl_init('http://127.0.0.1:8788/api'.$route);$headers=['Accept: application/json'];if($cookie)$headers[]='Cookie: '.$cookie;if($project)$headers[]='X-Fieldwork-Project: '.$project;
 if($body!==null){curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body));$headers[]='Content-Type: application/json';$headers[]='X-CSRF-Token: '.$csrf;}
 curl_setopt_array($h,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>20]);$raw=curl_exec($h);if($raw===false)throw new RuntimeException(curl_error($h));$len=curl_getinfo($h,CURLINFO_HEADER_SIZE);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
 if(preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi',substr($raw,0,$len),$m))$cookie=$m[1];$data=json_decode(substr($raw,$len),true);if($route==='/session')$csrf=$data['csrf'];return [$status,$data];
}
try{
 foreach([[$wid,'Project QA'],[$other,'Other QA']] as [$w,$n])query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$w,$n]);
 foreach([[$user,$wid,'owner'],[$seller,$wid,'salesperson'],[$otherUser,$other,'owner']] as [$u,$w,$role])query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$u,$w,'Project Tester',$u===$user?$email:uid().'@local.test',password_hash($password,PASSWORD_DEFAULT),$role]);
 $a=defaultProject($wid);$b=uid();query('INSERT INTO projects (id,workspace_id,name) VALUES (?,?,?)',[$b,$wid,'Project B']);$foreign=defaultProject($other);
 $_SESSION=['workspace_id'=>$wid,'user_id'=>$user,'user_role'=>'owner','user_name'=>'Project Tester'];
 setProjectContext($a);$l1=createLead(leadValues(['name'=>'A Lead','organisation'=>'A Org','email'=>'a@example.test','offering'=>'Website']),uid(),$a)['id'];
 setProjectContext($b);$l2=createLead(leadValues(['name'=>'B Lead','organisation'=>'B Org','email'=>'b@example.test','offering'=>'Hosting']),uid(),$b)['id'];
 $shared=query('SELECT organisation_id FROM contacts WHERE source_lead_id=?',[$l1])->fetchColumn();query('UPDATE contacts SET organisation_id=? WHERE source_lead_id=?',[$shared,$l2]);query('UPDATE organisations SET project_id=NULL WHERE id=?',[$shared]);applySchema();
 $orgRows=query('SELECT c.project_id,o.project_id AS org_project,c.organisation_id FROM contacts c JOIN organisations o ON o.id=c.organisation_id WHERE c.workspace_id=? ORDER BY c.id',[$wid])->fetchAll();
 ok(count($orgRows)===2&&$orgRows[0]['project_id']===$orgRows[0]['org_project']&&$orgRows[1]['project_id']===$orgRows[1]['org_project']&&$orgRows[0]['organisation_id']!==$orgRows[1]['organisation_id'],'migration splits legacy organisations across projects');
 ok(query('SELECT COUNT(*) FROM organisations WHERE workspace_id=? AND origin_organisation_id=?',[$wid,$shared])->fetchColumn()==1,'organisation migration keeps exact source lineage');
 query('UPDATE leads SET owner_user_id=?,owner=? WHERE workspace_id=?',[$user,'Project Tester',$wid]);
 $task=uid();query('INSERT INTO tasks (id,workspace_id,lead_id,title,owner,due_at) VALUES (?,?,?,?,?,?)',[$task,$wid,$l2,'B Follow-up','Project Tester','2030-01-01 10:00:00']);
 $identity=['workspace_id'=>$wid,'user_id'=>$user,'name'=>'Project Tester','role'=>'owner'];
 $tools=hostedTools();foreach($tools as $tool)if(!in_array($tool['name'],['get_profile','list_projects','list_team'],true))ok(in_array('project_id',$tool['inputSchema']['required'],true),$tool['name'].' requires project context');
 rejects(fn()=>hostedCall('list_leads',[],$identity),422,'MCP missing context rejected');
 rejects(fn()=>hostedCall('list_leads',['project_id'=>$foreign],$identity),404,'MCP foreign workspace project rejected');
 ok(count(hostedCall('list_leads',['project_id'=>$a],$identity))===1,'MCP selected project list');
 ok(count(hostedCall('list_leads',['project_id'=>'all'],$identity))===2,'MCP explicit All projects');
 $sellerIdentity=array_replace($identity,['user_id'=>$seller,'role'=>'salesperson']);ok(count(hostedCall('list_leads',['project_id'=>'all'],$sellerIdentity))===0,'All projects retains salesperson visibility');
 $collectorIdentity=array_replace($sellerIdentity,['role'=>'collector']);query("UPDATE users SET role='collector' WHERE id=?",[$seller]);rejects(fn()=>hostedCall('list_tasks',['project_id'=>'all'],$collectorIdentity),403,'All projects never exposes sales tasks to collectors');query("UPDATE users SET role='salesperson' WHERE id=?",[$seller]);
 $_SESSION=['workspace_id'=>$wid,'user_id'=>$user,'user_role'=>'owner','user_name'=>'Project Tester'];
 rejects(fn()=>hostedCall('get_lead',['project_id'=>$a,'id'=>$l2],$identity),404,'MCP wrong-project details rejected');
 rejects(fn()=>hostedCall('update_lead_phone',['project_id'=>$a,'id'=>$l2,'version'=>1,'value'=>'0700000000','provenance'=>'QA'],$identity),404,'MCP wrong-project profile rejected');
 rejects(fn()=>hostedCall('assign_lead',['project_id'=>$a,'id'=>$l2,'version'=>1,'user_id'=>$user],$identity),404,'MCP manager cannot bypass context');
 rejects(fn()=>hostedCall('complete_task',['project_id'=>$a,'id'=>$task],$identity),404,'MCP wrong-project task rejected');
 rejects(fn()=>hostedCall('complete_task',['project_id'=>'all','id'=>$task],$identity),422,'MCP All projects cannot mutate');
 projectApi('/session');[$status]=projectApi('/login',['email'=>$email,'password'=>$password]);ok($status===200,'API test sign-in');
 foreach(['/leads/'.$l2,'/leads/'.$l2.'/emails'] as $route){[$status]=projectApi($route,null,$a);ok($status===404,'API wrong-project read '.$route);}
 foreach([['/leads/'.$l2.'/assign',['version'=>1,'user_id'=>$user]],['/leads/'.$l2.'/profile',['version'=>1,'changes'=>['phone'=>'0700000000'],'provenance'=>'QA']],['/tasks/'.$task.'/complete',[]]] as [$route,$data]){[$status]=projectApi($route,$data,$a);ok($status===404,'API wrong-project write '.$route);}
 [$status]=projectApi('/leads',['name'=>'Bad context','request_key'=>uid(),'project_id'=>$b],$a);ok($status===409,'API intake context mismatch');
 [$status,$totals]=projectApi('/project-summary',null,$a);ok($status===200&&$totals['submitted_leads']===1&&$totals['pending_tasks']===0&&$totals['opportunity_stages']['New']===1,'dashboard counts enforce project scope');
 [$status,$totals]=projectApi('/project-summary',null,$b);ok($status===200&&$totals['pending_tasks']===1,'other project counts only its schedule');
 $extra=[];db()->beginTransaction();for($i=0;$i<501;$i++){$extra[]=uid();query('INSERT INTO leads (id,workspace_id,project_id,name,notes) VALUES (?,?,?,?,?)',[end($extra),$wid,$a,'Aggregate QA '.$i,'Synthetic count fixture']);}db()->commit();
 [$status,$totals]=projectApi('/project-summary',null,$a);ok($status===200&&$totals['submitted_leads']===502,'dashboard total is exact beyond the 500-row list limit');
 [$status,$capped]=projectApi('/leads',null,$a);ok($status===200&&count($capped)===500,'large list retains its bounded response');query('DELETE FROM leads WHERE workspace_id=? AND id IN ('.implode(',',array_fill(0,count($extra),'?')).')',array_merge([$wid],$extra));
 [$status,$rows]=projectApi('/tasks',null,$a);ok($status===200&&count($rows)===0,'API scoped schedule');
 [$status,$rows]=projectApi('/opportunities',null,$b);ok($status===200&&count($rows)===1,'API scoped pipeline');
 setProjectContext($a);
 $setting=['smtp_host'=>'smtp.example.com','smtp_port'=>587,'security'=>'starttls','username'=>'sender@example.com','password'=>' secret ','from_email'=>'sender@example.com','from_name'=>'A Sender'];
 $ma=mailboxSave($setting);$mb=mailboxSave(array_replace($setting,['username'=>'b@example.com','from_email'=>'b@example.com','from_name'=>'B Sender']));
 ok($ma['id']!==$mb['id'],'two independent accounts retained');ok(mailboxFor($user,$a)===null,'new accounts do not auto-select sender');
 mailboxSelect($a,$ma['id']);mailboxSelect($b,$mb['id']);
 $calls=[];$transport=function($account,$to)use(&$calls,$l2,$a){
  ok(!db()->inTransaction(),'SMTP claim committed before transport');
  if($to==='b@example.test'){$row=query('SELECT * FROM leads WHERE id=?',[$l2])->fetch();rejects(fn()=>moveLeadProject($row,$a,1),409,'move cannot race an actual send claim');}
  $calls[]=[$account['id'],$to];
 };
 outboundSend($l1,uid(),'A send','Synthetic',null,null,$transport,$ma);
 setProjectContext($b);outboundSend($l2,uid(),'B send','Synthetic',null,null,$transport,$mb);
 // Durable claim survives transport failure and cannot be retried.
 setProjectContext($a);$uncertain=uid();rejects(fn()=>outboundSend($l1,$uncertain,'Uncertain','Synthetic',null,null,static function(){throw new RuntimeException('fake timeout');},$ma),503,'transport uncertainty is durable');
 ok(query('SELECT status FROM outbound_emails WHERE request_key=?',[$uncertain])->fetchColumn()==='needs_check','failure claim survives transaction rollback');
 $replayed=outboundSend($l1,$uncertain,'Uncertain','Synthetic',null,null,$transport,$ma);ok($replayed['status']==='needs_check'&&count($calls)===2,'uncertain delivery replay never calls transport');
 $inflight=uid();query("INSERT INTO outbound_emails (id,workspace_id,lead_id,user_id,request_key,recipient,subject,body,status) VALUES (?,?,?,?,?,?,?,?, 'sending')",[$inflight,$wid,$l1,$user,uid(),'a@example.test','Inflight','Synthetic']);
 $row=query('SELECT * FROM leads WHERE id=?',[$l1])->fetch();rejects(fn()=>moveLeadProject($row,$b,1),409,'move blocked during durable sending claim');query("UPDATE outbound_emails SET status='needs_check' WHERE id=?",[$inflight]);
 ok($calls===[[$ma['id'],'a@example.test'],[$mb['id'],'b@example.test']],'fake transport uses correct project sender');
 $history=query("SELECT sender_email,project_id FROM outbound_emails WHERE workspace_id=? AND status='sent' ORDER BY recipient",[$wid])->fetchAll();ok($history[0]['sender_email']==='sender@example.com'&&$history[0]['project_id']===$a&&$history[1]['sender_email']==='b@example.com','history snapshots sender and origin project');
 $_SESSION['user_id']=$seller;rejects(fn()=>mailboxSelect($a,$ma['id']),404,'teammate cannot select another user account');$_SESSION['user_id']=$user;
 setProjectContext($b);mailboxSelect($b,$ma['id']);rejects(fn()=>outboundSend($l2,uid(),'Stale','Synthetic',null,null,$transport,$mb),409,'sender change invalidates composer snapshot');mailboxSelect($b,$mb['id']);
 $mb2=mailboxSave(array_replace($setting,['id'=>$mb['id'],'from_email'=>'b@example.com']));rejects(fn()=>outboundSend($l2,uid(),'Stale revision','Synthetic',null,null,$transport,$mb),409,'credential/config revision invalidates consent');
 [$status,$work]=projectApi('/leads/'.$l2.'/agent-requests',['instruction'=>'Synthetic email task','request_key'=>uid(),'allow_email_send'=>true,'sender_id'=>$mb2['id'],'sender_revision'=>$mb2['revision'],'email_recipient'=>'b@example.test'],$b);ok($status===200,'project sender consent captured');
 [$status]=projectApi('/agent-work/'.$work['id'].'/review',['action'=>'close','reason'=>'QA','review_token'=>str_repeat('a',64),'request_id'=>uid()],$a);ok($status===404,'API wrong-project human review rejected');
 [$status]=projectApi('/agent-work/'.$work['id'].'/request-reversal',['reason'=>'QA'],$a);ok($status===404,'API wrong-project reversal rejected');
 [$status,$rows]=projectApi('/agent-reports',null,$a);ok($status===200&&count($rows)===0,'Agent activity defaults to selected project');
 [$status,$rows]=projectApi('/agent-reports?project=all',null,$a);ok($status===200&&count($rows)===1&&$rows[0]['project_id']===$b&&$rows[0]['project_name']==='Project B','All projects reports carry context');
 rejects(fn()=>hostedCall('resolve_agent_work',['project_id'=>$a,'id'=>$work['id'],'decision'=>'done','reason'=>'QA'],$identity),404,'MCP wrong-project work rejected');
 setProjectContext($b);mailboxSelect($b,$ma['id']);rejects(fn()=>hostedCall('send_agent_email',['project_id'=>$b,'id'=>$work['id'],'subject'=>'Never send','body'=>'Synthetic'],$identity),409,'agent sender change blocked before SMTP');
 [$status]=projectApi('/leads/'.$l2.'/project',['project_id'=>$a,'version'=>1],$b);ok($status===200,'lead move transaction');
 [$status,$detail]=projectApi('/leads/'.$l2,null,$a);ok($status===200&&count($detail['tasks'])===1&&count($detail['opportunities'])===1,'move preserves tasks and opportunities');
 [$status]=projectApi('/leads/'.$l2,null,$b);ok($status===404,'source project cannot access moved graph');
 ok(query('SELECT project_id FROM contacts WHERE source_lead_id=?',[$l2])->fetchColumn()===$a,'contact moves with lead');
 $org=query('SELECT o.project_id FROM contacts c JOIN organisations o ON o.id=c.organisation_id WHERE c.source_lead_id=?',[$l2])->fetchColumn();ok($org===$a,'organisation belongs to destination');
 $moveAudit=json_decode(query("SELECT body FROM activities WHERE workspace_id=? AND lead_id=? AND kind='project_moved' ORDER BY created_at DESC LIMIT 1",[$wid,$l2])->fetchColumn(),true);ok(count($moveAudit['organisation_links'])===1,'move retains organisation lineage in audit');
 ok(query('SELECT status FROM agent_work_items WHERE id=?',[$work['id']])->fetchColumn()==='needs_review','move pauses pending work');
 rejects(fn()=>hostedCall('send_agent_email',['project_id'=>$a,'id'=>$work['id'],'subject'=>'Never send','body'=>'Synthetic'],$identity),409,'moved pending consent cannot send');
 setProjectContext($a);$legacy=substr(hash('sha256','legacy-mailbox:'.$wid.':'.$seller),0,32);$cipher=mailEncrypt('legacy secret');
 query('INSERT INTO mailboxes (workspace_id,user_id,smtp_host,smtp_port,security,username,password_cipher,from_email,from_name) VALUES (?,?,?,?,?,?,?,?,?)',[$wid,$seller,'smtp.example.com',587,'starttls','legacy@example.com',$cipher,'legacy@example.com','Legacy']);
 applySchema();applySchema();ok(query('SELECT COUNT(*) FROM mail_accounts WHERE id=?',[$legacy])->fetchColumn()==1,'legacy credentials copy is repeatable');
 ok(mailDecrypt(query('SELECT password_cipher FROM mail_accounts WHERE id=?',[$legacy])->fetchColumn())==='legacy secret','legacy encryption preserved');ok(mailboxFor($seller,$a)===null&&mailboxFor($seller,$b)===null,'legacy migration makes no sender assumptions');
 query('UPDATE mail_accounts SET active=0 WHERE id=?',[$legacy]);applySchema();ok(query('SELECT active FROM mail_accounts WHERE id=?',[$legacy])->fetchColumn()==0,'rerun never resurrects disconnected legacy mailbox');
 ok(query('SELECT COUNT(*) FROM mailboxes WHERE workspace_id=? AND user_id=?',[$wid,$seller])->fetchColumn()==1,'legacy source retained');
 echo "Project isolation checks passed: $checks. No real email sent.\n";
}finally{
 if(db()->inTransaction())db()->rollBack();unset($GLOBALS['fieldwork_project']);
 foreach([$wid,$other] as $w){
   foreach(['project_senders','mail_accounts','mailboxes','outbound_emails','agent_work_items','activities','tasks','requests','assignment_events','opportunities','contacts','organisations','leads','projects','users'] as $table)query("DELETE FROM $table WHERE workspace_id=?",[$w]);query('DELETE FROM workspaces WHERE id=?',[$w]);
 }
 if(isset($legacy))query('DELETE FROM data_migrations WHERE name=?',['legacy-mailbox:'.$legacy]);
}
