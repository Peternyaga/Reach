<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
require dirname(__DIR__).'/backend/migrations.php';
require dirname(__DIR__).'/backend/accounts.php';
require dirname(__DIR__).'/backend/agent-poll.php';
putenv('FIELDWORK_OAUTH_BASE_URL=http://127.0.0.1:8793');putenv('FIELDWORK_OAUTH_ALLOW_HTTP_LOCAL=1');
require dirname(__DIR__).'/backend/workspace-sync.php';
define('FIELDWORK_HOSTED_LIBRARY',true);require dirname(__DIR__).'/backend/hosted-router.php';
$wid=uid();$foreign=uid();$owner=uid();$manager=uid();$seller=uid();$collector=uid();$foreignUser=uid();$pending=uid();$client=uid();$password='Team-'.uid();$checks=0;$cookies=[];$csrf=[];
$loginBucket=hash('sha256','account-login-ip|127.0.0.1');$loginBefore=query('SELECT * FROM oauth_rate_limits WHERE bucket=?',[$loginBucket])->fetch();
function checkTeam(bool $pass,string $name): void {global $checks;if(!$pass)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";$checks++;}
function denyTeam(callable $fn,int $code,string $name): void {try{$fn();throw new LogicException('Expected rejection '.$name);}catch(RuntimeException $e){checkTeam($e->getCode()===$code,$name.' ('.$e->getMessage().')');}finally{if(db()->inTransaction())db()->rollBack();}}
function teamSession(string $user): array {global $wid;$r=query('SELECT * FROM users WHERE id=?',[$user])->fetch();$_SESSION=['workspace_id'=>$r['workspace_id'],'user_id'=>$user,'user_name'=>$r['name'],'user_role'=>$r['role'],'auth_version'=>(int)$r['auth_version']];unset($GLOBALS['fieldwork_project']);return ['workspace_id'=>$r['workspace_id'],'user_id'=>$user,'name'=>$r['name'],'role'=>$r['role'],'auth_version'=>(int)$r['auth_version']];}
function teamHttp(string $user,string $path,?array $data=null,?string $project=null,array $extra=[]): array {
 global $cookies,$csrf;$h=curl_init('http://127.0.0.1:8793/api'.$path);$headers=['Accept: application/json',...$extra];if(isset($cookies[$user]))$headers[]='Cookie: '.$cookies[$user];if($project)$headers[]='X-Fieldwork-Project: '.$project;
 if($data!==null){curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($data));$headers[]='Content-Type: application/json';$headers[]='X-CSRF-Token: '.($csrf[$user]??'');}
 curl_setopt_array($h,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>15]);$raw=curl_exec($h);if($raw===false)throw new RuntimeException(curl_error($h));$n=curl_getinfo($h,CURLINFO_HEADER_SIZE);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);$head=substr($raw,0,$n);if(preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi',$head,$m))$cookies[$user]=$m[1];$out=json_decode(substr($raw,$n),true);if($path==='/session'&&isset($out['csrf']))$csrf[$user]=$out['csrf'];return [$status,$out];
}
function teamLogin(string $user): void {global $password;teamHttp($user,'/session');$email=query('SELECT email FROM users WHERE id=?',[$user])->fetchColumn();[$s]=teamHttp($user,'/login',['email'=>$email,'password'=>$password]);checkTeam($s===200,'local fixture login');}
function memberChange(string $id,array $overrides=[]): array {$r=query('SELECT * FROM users WHERE id=?',[$id])->fetch();return array_replace(membershipSnapshot($r),$overrides);}
try{
 query('DELETE FROM oauth_rate_limits WHERE bucket=?',[$loginBucket]);
 foreach([[$wid,'Isolated team access'],[$foreign,'Foreign fixture']] as [$w,$n])query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$w,$n]);
 foreach([[$owner,$wid,'owner'],[$manager,$wid,'manager'],[$seller,$wid,'salesperson'],[$collector,$wid,'collector'],[$pending,$wid,'salesperson'],[$foreignUser,$foreign,'owner']] as [$u,$w,$r])query('INSERT INTO users (id,workspace_id,name,email,password_hash,role,active,setup_pending) VALUES (?,?,?,?,?,?,?,?)',[$u,$w,'Fixture '.$r.' '.substr($u,0,5),$u.'@local.test',password_hash($password,PASSWORD_DEFAULT),$r,$u===$pending?0:1,$u===$pending?1:0]);
 $a=defaultProject($wid);$b=uid();$c=uid();foreach([[$b,'Project B'],[$c,'Project C']] as [$p,$name])query('INSERT INTO projects (id,workspace_id,name) VALUES (?,?,?)',[$p,$wid,$name]);$fp=defaultProject($foreign);
 applySchema();applySchema();checkTeam(query('SELECT access_scope FROM users WHERE id=?',[$pending])->fetchColumn()==='workspace','repeatable migration preserves pending workspace default');
 $identity=teamSession($owner);$lA=createLead(leadValues(['name'=>'Team A','email'=>'a@example.test','offering'=>'Website']),uid(),$a)['id'];$lB=createLead(leadValues(['name'=>'Team B','email'=>'b@example.test','offering'=>'Website']),uid(),$b)['id'];
 $scoped=['access_scope'=>'selected','project_ids'=>[$a]];
 updateMembership($manager,memberChange($manager,$scoped));updateMembership($seller,memberChange($seller,$scoped));updateMembership($collector,memberChange($collector,$scoped));
 checkTeam((int)query('SELECT COUNT(*) FROM membership_audit WHERE workspace_id=?',[$wid])->fetchColumn()===3,'membership changes audited');
 $audit=json_decode(query('SELECT after_state FROM membership_audit WHERE member_user_id=?',[$manager])->fetchColumn(),true);checkTeam($audit['project_ids']===[$a]&&!isset($audit['password_hash']),'audit retains scope without credentials');
 denyTeam(fn()=>updateMembership($owner,memberChange($owner,['role'=>'manager'])),409,'last active owner protected');
 denyTeam(fn()=>updateMembership($seller,memberChange($seller,['access_scope'=>'selected','project_ids'=>[]])),422,'selected scope requires a project');
 denyTeam(fn()=>updateMembership($foreignUser,memberChange($foreignUser)),404,'cross-workspace member edit denied');
 $stale=memberChange($pending);updateMembership($pending,memberChange($pending,$scoped));denyTeam(fn()=>updateMembership($pending,$stale),409,'stale member version cannot overwrite scope');
 $issued=issueAccountToken(['id'=>$pending,'workspace_id'=>$wid],'invite');$second=issueAccountToken(['id'=>$pending,'workspace_id'=>$wid],'invite');denyTeam(fn()=>findAccountToken($issued['token']),422,'reissue expires old invite');
 updateMembership($pending,memberChange($pending,['project_ids'=>[$b]]));$_SESSION=[];redeemAccountToken($second['token'],$password);$redeemed=query('SELECT * FROM users WHERE id=?',[$pending])->fetch();checkTeam($redeemed['active']==1&&$redeemed['access_scope']==='selected'&&userHasProject($pending,$b,$wid)&&!userHasProject($pending,$a,$wid),'redemption uses latest server scope');
 teamSession($owner);denyTeam(fn()=>membershipConfig(['role'=>'owner','access_scope'=>'selected','project_ids'=>[$a]]),422,'owner cannot be project scoped');
 $mi=teamSession($manager);checkTeam(count(hostedCall('list_projects',[],$mi))===1,'MCP projects filtered to membership');
 checkTeam(count(hostedCall('list_leads',['project_id'=>'all'],$mi))===1,'MCP all view includes accessible projects only');
 denyTeam(fn()=>hostedCall('get_lead',['project_id'=>$b,'id'=>$lB],$mi),404,'MCP guessed project denied');
 denyTeam(fn()=>hostedCall('get_lead',['project_id'=>'all','id'=>$lB],$mi),422,'single-record MCP all context denied');
 denyTeam(fn()=>updateMembership($manager,memberChange($manager,['role'=>'owner'])),403,'project manager cannot self promote');
 teamSession($owner);denyTeam(fn()=>hostedCall('assign_lead',['project_id'=>$b,'id'=>$lB,'version'=>1,'user_id'=>$seller],$identity),422,'assignment does not grant missing project access');
 denyTeam(fn()=>hostedCall('assign_lead',['project_id'=>$a,'id'=>$lA,'version'=>1,'user_id'=>$collector],$identity),422,'collector excluded from assignment');
 $si=teamSession($seller);checkTeam(count(hostedCall('list_leads',['project_id'=>'all'],$si))===0,'project membership does not grant salesperson all records');
 teamSession($owner);hostedCall('assign_lead',['project_id'=>$a,'id'=>$lA,'version'=>1,'user_id'=>$seller],$identity);checkTeam(count(hostedCall('list_leads',['project_id'=>'all'],$si))===1,'salesperson sees assigned accessible lead');
 teamSession($owner);denyTeam(fn()=>hostedCall('assign_lead',['project_id'=>$a,'id'=>$lA,'version'=>1,'user_id'=>$owner],$identity),409,'assignment requires current lead version');
 denyTeam(fn()=>updateMembership($seller,memberChange($seller,['active'=>false])),409,'deactivation blocks orphaned owned leads');
 denyTeam(fn()=>updateMembership($seller,memberChange($seller,['project_ids'=>[$b]])),409,'scope removal blocks orphaned owned leads');
 denyTeam(fn()=>updateMembership($seller,memberChange($seller,['role'=>'collector'])),409,'collector demotion blocks owned leads');
 setProjectContext($a);$row=query('SELECT * FROM leads WHERE id=?',[$lA])->fetch();denyTeam(fn()=>moveLeadProject($row,$b,2),409,'move blocks inaccessible owner');
 $activity=uid();$work=uid();query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$activity,$wid,$lB,'agent_request',json_encode(['instruction'=>'Synthetic request','requested_by_user_id'=>$manager]),'Fixture']);query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,kind) VALUES (?,?,?,?,?)',[$work,$wid,$lB,$activity,'user_request']);
 denyTeam(fn()=>hostedCall('resolve_agent_work',['project_id'=>$b,'id'=>$work,'decision'=>'done','reason'=>'Do not execute'],$identity),403,'queued requester access rechecked on execution');
 foreach([$owner,$manager,$seller,$collector] as $u)teamLogin($u);
 [$s,$rows]=teamHttp($manager,'/projects');checkTeam($s===200&&array_column($rows,'id')===[$a],'browser project list scoped');
 foreach(['/leads','/tasks','/opportunities','/agent-work','/agent-reports','/project-summary','/sync'] as $path){[$s,$out]=teamHttp($manager,$path.'?project=all');checkTeam($s===200,'browser all view works '.$path);checkTeam(!str_contains(json_encode($out),$lB),'browser all view does not leak forbidden lead '.$path);[$s]=teamHttp($manager,$path,null,$b);checkTeam($s===404,'browser guessed project denied '.$path);}
 [$s]=teamHttp($manager,'/leads/'.$lB,null,$a);checkTeam($s===404,'browser guessed lead denied within permitted project');
 [$s]=teamHttp($manager,'/leads/'.$lB.'/profile',['version'=>1,'changes'=>['phone'=>'123'],'provenance'=>'Synthetic'],$b);checkTeam($s===404,'browser guessed project profile write denied');
 [$s]=teamHttp($manager,'/leads',['project_id'=>$b,'name'=>'Never created','request_key'=>uid()],$a);checkTeam($s===404,'intake cannot choose unauthorized project');
 [$s]=teamHttp($manager,'/import/preview',['project_id'=>$b,'csv'=>"name\nNever created"],$a);checkTeam($s===404,'import cannot inspect unauthorized duplicates');
 [$s]=teamHttp($manager,'/projects',['name'=>'Unauthorized creation']);checkTeam($s===403,'project manager cannot create workspace project');
 [$s]=teamHttp($manager,'/team/invite',['name'=>'Never invited','email'=>uid().'@local.test','role'=>'manager','access_scope'=>'workspace','project_ids'=>[]]);checkTeam($s===403,'project manager cannot invite or grant workspace');
 [$s]=teamHttp($manager,'/team/member/'.$manager.'/access',memberChange($manager,['role'=>'owner']));checkTeam($s===403,'browser manager cannot self promote');
 [$s,$team]=teamHttp($owner,'/team',null,$b);checkTeam($s===200&&!in_array($seller,array_column($team,'id'),true)&&!in_array($collector,array_column($team,'id'),true),'recipient roster excludes collector and inaccessible member');
 [$s,$roster]=teamHttp($manager,'/projects/'.$a.'/members');checkTeam($s===200&&!isset($roster[0]['email']),'project roster supports manager without private emails');
 [$s]=teamHttp($manager,'/projects/'.$b.'/members');checkTeam($s===404,'project roster cannot disclose guessed project');
 $inviteEmail=uid().'@local.test';$form=['name'=>'New scoped member','email'=>$inviteEmail,'role'=>'salesperson','access_scope'=>'selected','project_ids'=>[$b]];[$s,$newInvite]=teamHttp($owner,'/team/invite',$form);checkTeam($s===200&&isset($newInvite['token']),'browser scoped invitation created without email');
 [$s]=teamHttp($owner,'/team/invite',$form);checkTeam($s===409,'existing account invitation cannot duplicate or merge');
 [$s]=teamHttp($owner,'/team/invite',array_replace($form,['email'=>$foreignUser.'@local.test']));checkTeam($s===409,'foreign account invitation cannot merge tenants');
 $token=bin2hex(random_bytes(32));$pid=uid();query('INSERT INTO plugin_tokens (id,workspace_id,user_id,token_hash,auth_version,expires_at) VALUES (?,?,?,?,?,?)',[$pid,$wid,$manager,hash('sha256',$token),2,gmdate('Y-m-d H:i:s',time()+3600)]);
 [$s]=teamHttp('local-plugin','/plugin/auth',null,null,['Authorization: Bearer '.$token]);checkTeam($s===200,'local MCP session authenticates current authority');
 [$s,$rows]=teamHttp('local-plugin','/leads?project=all');checkTeam($s===200&&count($rows)===1,'local MCP all view membership enforced');
 [$s]=teamHttp('local-plugin','/leads/'.$lB,null,$b);checkTeam($s===404,'local MCP guessed project denied');
 teamSession($manager);setProjectContext('all',true);$stamp=workspaceSyncStamp();teamSession($owner);query('UPDATE leads SET version=version+1 WHERE id=?',[$lB]);teamSession($manager);setProjectContext('all',true);checkTeam(workspaceSyncStamp()===$stamp,'forbidden project changes do not affect scoped sync fingerprint');
 teamSession($owner);
 $pendingName=query('SELECT name FROM users WHERE id=?',[$pending])->fetchColumn();$task=uid();query('INSERT INTO tasks (id,workspace_id,lead_id,title,owner,due_at) VALUES (?,?,?,?,?,?)',[$task,$wid,$lB,'Keep follow-up',$pendingName,'2030-01-01 10:00:00']);
 denyTeam(fn()=>updateMembership($pending,memberChange($pending,['project_ids'=>[$a]])),409,'scope removal blocks independently assigned pending task');query('DELETE FROM tasks WHERE id=?',[$task]);
 $ownTask=uid();$sellerName=query('SELECT name FROM users WHERE id=?',[$seller])->fetchColumn();query('INSERT INTO tasks (id,workspace_id,lead_id,title,owner,due_at) VALUES (?,?,?,?,?,?)',[$ownTask,$wid,$lA,'Transfer explicitly',$sellerName,'2030-01-02 10:00:00']);
 denyTeam(fn()=>hostedCall('assign_lead',['project_id'=>$a,'id'=>$lA,'user_id'=>$owner,'version'=>2],$identity),409,'reassignment requires explicit pending-task transfer');
 hostedCall('assign_lead',['project_id'=>$a,'id'=>$lA,'user_id'=>$owner,'version'=>2,'reassign_pending_tasks'=>true,'review_pending_work'=>true],$identity);checkTeam(query('SELECT owner FROM tasks WHERE id=?',[$ownTask])->fetchColumn()===query('SELECT name FROM users WHERE id=?',[$owner])->fetchColumn(),'explicit reassignment transfers pending follow-up atomically');
 $poll=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');query('INSERT INTO agent_poll_tokens (id,workspace_id,user_id,token_hash,auth_version) VALUES (?,?,?,?,?)',[uid(),$wid,$manager,hash('sha256',$poll),2]);
 [$s,$ready]=teamHttp('poller','/automation/ready',null,null,['Authorization: Bearer '.$poll]);checkTeam($s===200&&$ready['ready']===false,'poller does not wake for inaccessible project work');
 $pa=uid();$pw=uid();query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$pa,$wid,$lA,'note',json_encode(['text'=>'Synthetic request','requested_by_user_id'=>$owner]),'Fixture']);query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,kind) VALUES (?,?,?,?,?)',[$pw,$wid,$lA,$pa,'review_note']);
 [$s,$ready]=teamHttp('poller','/automation/ready',null,null,['Authorization: Bearer '.$poll]);checkTeam($s===200&&$ready['ready']===true,'poller wakes for accessible project work');query("UPDATE agent_work_items SET status='needs_review' WHERE id=?",[$pw]);
 query('INSERT INTO oauth_clients (id,name,redirect_uris) VALUES (?,?,?)',[$client,'Team synthetic OAuth','[]']);$oauth=bin2hex(random_bytes(32));query('INSERT INTO oauth_grants (id,client_id,user_id,workspace_id,resource,scope,access_hash,refresh_hash,access_expires_at,refresh_expires_at,auth_version) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[uid(),$client,$manager,$wid,hostedResource(),'leads:read',hash('sha256',$oauth),hash('sha256',uid()),gmdate('Y-m-d H:i:s',time()+3600),gmdate('Y-m-d H:i:s',time()+7200),2]);$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$oauth;checkTeam(hostedBearer()['user_id']===$manager,'current OAuth grant authenticates');
 query("UPDATE agent_work_items SET status='needs_review' WHERE id=?",[$work]);$pair=uid();query('INSERT INTO agent_poll_pairings (id,workspace_id,user_id,verifier_hash,approved_at,expires_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),?)',[$pair,$wid,$manager,hash('sha256',uid()),gmdate('Y-m-d H:i:s',time()+600)]);$oldManager=$mi;updateMembership($manager,memberChange($manager,['project_ids'=>[$c]]));
 denyTeam(fn()=>hostedCall('list_projects',[],$oldManager),401,'stale MCP identity rejected after scope change');
 [$s]=teamHttp($manager,'/leads?project=all');checkTeam($s===401,'old browser session invalid after membership edit');
 [$s]=teamHttp('local-plugin','/leads?project=all');checkTeam($s===401,'old local MCP session invalid after membership edit');
 [$s]=teamHttp('new-local','/plugin/auth',null,null,['Authorization: Bearer '.$token]);checkTeam($s===401,'old local plugin credential invalid after membership edit');
 checkTeam(query('SELECT used_at FROM agent_poll_pairings WHERE id=?',[$pair])->fetchColumn()!==null,'membership edit invalidates unfinished poll pairing');
 denyTeam(fn()=>hostedBearer(),401,'scope edit revokes stale OAuth grant');
 [$s]=teamHttp('poller','/automation/ready',null,null,['Authorization: Bearer '.$poll]);checkTeam($s===401,'scope edit revokes stale poll credential');
 echo "Team access checks passed: $checks. Synthetic fixtures only; no email.\n";
}finally{
 if(db()->inTransaction())db()->rollBack();
 foreach([$wid,$foreign] as $w){foreach(['membership_audit','project_memberships','project_senders','mail_accounts','mailboxes','account_tokens','plugin_tokens','oauth_grants','oauth_codes','agent_poll_tokens','agent_poll_pairings','research_requests','lead_research','outbound_emails','agent_work_items','assignment_events','activities','tasks','requests','opportunities','contacts','organisations','leads','projects','users'] as $t)query("DELETE FROM $t WHERE workspace_id=?",[$w]);query('DELETE FROM workspaces WHERE id=?',[$w]);}
 query('DELETE FROM oauth_clients WHERE id=?',[$client]);
 query('DELETE FROM oauth_rate_limits WHERE bucket=?',[$loginBucket]);if($loginBefore)query('INSERT INTO oauth_rate_limits (bucket,window_start,attempts) VALUES (?,?,?)',[$loginBefore['bucket'],$loginBefore['window_start'],$loginBefore['attempts']]);
}
