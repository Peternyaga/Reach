<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/backend/bootstrap.php';
require dirname(__DIR__).'/backend/projects.php';
require dirname(__DIR__).'/backend/lead-records.php';
$fixtureFile=dirname(__DIR__).'/var/workspace-sync-qa.json';
function syncCheck(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function syncApi(array &$client,string $path,?array $body=null,?string $etag=null): array {
    $h=curl_init('http://127.0.0.1:8788/api'.$path);$headers=['Accept: application/json'];
    if($client['cookie']??'')$headers[]='Cookie: '.$client['cookie'];
    if($etag)$headers[]='If-None-Match: '.$etag;
    if($body!==null){curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));$headers[]='Content-Type: application/json';$headers[]='X-CSRF-Token: '.($client['csrf']??'');}
    curl_setopt_array($h,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>10]);
    $raw=curl_exec($h);if($raw===false)throw new RuntimeException(curl_error($h));
    $status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);$length=curl_getinfo($h,CURLINFO_HEADER_SIZE);curl_close($h);
    $head=substr($raw,0,$length);$response=substr($raw,$length);
    if(preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi',$head,$m))$client['cookie']=$m[1];
    preg_match('/^ETag:\s*([^\r\n]+)/mi',$head,$tag);
    return [$status,json_decode($response,true),$tag[1]??null,$response];
}
function syncLogin(string $email,string $password): array {
    $client=[];[, $session]=syncApi($client,'/session');$client['csrf']=$session['csrf'];
    [$status]=syncApi($client,'/login',['email'=>$email,'password'=>$password]);syncCheck($status===200,'independent session login');return $client;
}
function syncCleanup(array $ids): void {
    if(db()->inTransaction())db()->rollBack();
    foreach($ids as $id){
        if(query('SELECT name FROM workspaces WHERE id=?',[$id])->fetchColumn()!=='Workspace sync test')throw new RuntimeException('Wrong fixture workspace');
        db()->beginTransaction();
        foreach(['project_senders','mail_accounts','mailboxes','outbound_emails','requests','account_tokens','plugin_tokens','oauth_codes','oauth_grants','agent_poll_pairings','agent_poll_tokens','agent_work_items','activities','tasks','assignment_events','opportunities','contacts','organisations','leads','projects','users'] as $table)query("DELETE FROM $table WHERE workspace_id=?",[$id]);
        query('DELETE FROM workspaces WHERE id=?',[$id]);db()->commit();
    }
}
if(in_array('--cleanup-browser-fixture',$argv,true)){
    $fixture=json_decode(file_get_contents($fixtureFile),true,32,JSON_THROW_ON_ERROR);syncCleanup($fixture['workspaces']);unlink($fixtureFile);exit("Browser fixture removed.\n");
}
$workspace=uid();$otherWorkspace=uid();$owner=uid();$seller=uid();$collector=uid();$outsider=uid();$lead=uid();$hidden=uid();$task=uid();$password=bin2hex(random_bytes(16));$suffix=bin2hex(random_bytes(6));$keep=false;
try {
    foreach([$workspace,$otherWorkspace] as $id)query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$id,'Workspace sync test']);
    $project=defaultProject($workspace);$otherProject=defaultProject($otherWorkspace);
    foreach([[$owner,$workspace,'Sync Desktop','owner'],[$seller,$workspace,'Sync Mobile','salesperson'],[$collector,$workspace,'Sync Collector','collector'],[$outsider,$otherWorkspace,'Sync Outsider','owner']] as [$id,$wid,$name,$role])query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$id,$wid,$name,strtolower(str_replace(' ','-',$name)).'-'.$suffix.'@local.test',password_hash($password,PASSWORD_DEFAULT),$role]);
    foreach([[$lead,'Sync QA contact',$seller,$collector],[$hidden,'Hidden QA contact',$owner,$owner]] as [$id,$name,$assigned,$submitted]){
        query('INSERT INTO leads (id,workspace_id,project_id,name,organisation,notes,owner,owner_user_id,collector_user_id,offering) VALUES (?,?,?,?,?,?,?,?,?,?)',[$id,$workspace,$project,$name,'Synthetic QA business','','Sync Mobile',$assigned,$submitted,'Website development']);
        materialiseLeadRecords(query('SELECT * FROM leads WHERE id=?',[$id])->fetch());
    }
    query('INSERT INTO tasks (id,workspace_id,lead_id,title,owner,due_at) VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))',[$task,$workspace,$lead,'Sync QA follow-up','Sync Mobile']);
    if(in_array('--browser-fixture',$argv,true)){
        $fixture=['workspaces'=>[$workspace,$otherWorkspace],'owner_email'=>'sync-desktop-'.$suffix.'@local.test','seller_email'=>'sync-mobile-'.$suffix.'@local.test','password'=>$password,'lead'=>$lead,'task'=>$task,'project'=>$project];
        if(is_file($fixtureFile))throw new RuntimeException('Existing browser fixture must be cleaned first');
        file_put_contents($fixtureFile,json_encode($fixture,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));$keep=true;echo 'Browser fixture ready: '.$fixtureFile."\n";
    }else{
        $anonymous=[];[$status]=syncApi($anonymous,'/sync');syncCheck($status===401,'sync requires authentication');
        $desktop=syncLogin('sync-desktop-'.$suffix.'@local.test',$password);$mobile=syncLogin('sync-desktop-'.$suffix.'@local.test',$password);
        $sales=syncLogin('sync-mobile-'.$suffix.'@local.test',$password);$collection=syncLogin('sync-collector-'.$suffix.'@local.test',$password);$outside=syncLogin('sync-outsider-'.$suffix.'@local.test',$password);
        syncCheck($desktop['cookie']!==$mobile['cookie'],'browser sessions use independent cookies');
        [$status,$data,$stamp]=syncApi($mobile,'/sync?project=all');syncCheck($status===200&&preg_match('/^"[a-f0-9]{64}"$/',$stamp)&&$data['user']['id']===$owner,'opaque scoped stamp includes authenticated identity');
        [$status,,$second]=syncApi($desktop,'/sync');syncCheck($second===$stamp,'same account and state yield same stamp');
        [$status,,, $body]=syncApi($mobile,'/sync?project=all',null,$stamp);syncCheck($status===304&&$body==='','unchanged sync has no response body');
        [,,$salesStamp]=syncApi($sales,'/sync');[,,$collectorStamp]=syncApi($collection,'/sync');[,,$outsideStamp]=syncApi($outside,'/sync');
        syncCheck($salesStamp!==$stamp&&$outsideStamp!==$stamp,'stamps cannot be reused across users or workspaces');
        [$status]=syncApi($desktop,'/tasks/'.$task.'/complete',[]);syncCheck($status===200,'desktop completes task');
        [$status,,$changed]=syncApi($mobile,'/sync?project=all',null,$stamp);syncCheck($status===200&&$changed!==$stamp,'mobile observes task completion without reload');
        [, $detail]=syncApi($mobile,'/leads/'.$lead);syncCheck($detail['tasks'][0]['status']==='completed','open lead detail contains completed task');
        [$status]=syncApi($collection,'/sync',null,$collectorStamp);syncCheck($status===304,'private task/activity changes do not affect collector fingerprint');
        [$status]=syncApi($outside,'/sync',null,$outsideStamp);syncCheck($status===304,'other workspace change does not affect outsider');
        [,,$salesStamp]=syncApi($sales,'/sync');
        syncApi($desktop,'/leads/'.$hidden.'/notes',['note'=>'Private QA note']);
        [$status]=syncApi($sales,'/sync',null,$salesStamp);syncCheck($status===304,'inaccessible lead note is absent from salesperson fingerprint');
        [,,$stamp]=syncApi($mobile,'/sync?project=all');syncApi($desktop,'/leads/'.$lead.'/notes',['note'=>'Visible QA conversation']);
        [$status,,$changed]=syncApi($mobile,'/sync?project=all',null,$stamp);syncCheck($status===200&&$changed!==$stamp,'conversation and queued agent work change fingerprint');
        $work=query("SELECT id FROM agent_work_items WHERE workspace_id=? AND lead_id=? ORDER BY id LIMIT 1",[$workspace,$lead])->fetchColumn();
        query("UPDATE agent_work_items SET status='needs_review',result=? WHERE id=?",[json_encode(['summary'=>'QA worker result']),$work]);
        [$status]=syncApi($mobile,'/sync?project=all',null,$changed);syncCheck($status===200,'background agent result changes fingerprint');
        [,,$stamp]=syncApi($mobile,'/sync?project=all');syncApi($desktop,'/leads/'.$lead.'/opportunities',['offering'=>'Website maintenance','request_key'=>uid()]);
        [$status]=syncApi($mobile,'/sync?project=all',null,$stamp);syncCheck($status===200,'opportunity changes are propagated');
        [,,$stamp]=syncApi($mobile,'/sync?project=all');syncApi($desktop,'/leads/'.$lead.'/update',['stage'=>'Engaged','version'=>1]);
        [$status]=syncApi($mobile,'/sync?project=all',null,$stamp);syncCheck($status===200,'lead/stage changes are propagated');
        [,,$stamp]=syncApi($mobile,'/sync?project=all');[$status,$newProject]=syncApi($desktop,'/projects',['name'=>'Sync Other project']);syncCheck($status===200,'project creation');
        [$status]=syncApi($mobile,'/sync?project=all',null,$stamp);syncCheck($status===200,'project selector changes are propagated');
        [,,$stamp]=syncApi($mobile,'/sync?project=all');syncApi($desktop,'/leads/'.$lead.'/project',['project_id'=>$newProject['id'],'version'=>2]);
        [$status]=syncApi($mobile,'/sync?project=all',null,$stamp);syncCheck($status===200,'project moves change fingerprint');
        [, $oldTasks]=syncApi($mobile,'/tasks?project='.$project);[, $newTasks]=syncApi($mobile,'/tasks?project='.$newProject['id']);
        syncCheck(count($oldTasks)===0&&count($newTasks)===1,'refreshed task lists respect selected project');
        [,,$stamp]=syncApi($mobile,'/sync?project=all');db()->beginTransaction();query("UPDATE tasks SET status='pending' WHERE id=?",[$task]);
        [$status]=syncApi($mobile,'/sync?project=all',null,$stamp);syncCheck($status===304,'uncommitted changes are not exposed');db()->rollBack();
        query('DELETE FROM tasks WHERE id=?',[$task]);[$status]=syncApi($mobile,'/sync?project=all',null,$stamp);syncCheck($status===200,'deletions change fingerprint');
        [,,$stamp]=syncApi($mobile,'/sync?project=all');query('UPDATE users SET name=? WHERE id=?',['Renamed Sync Mobile',$seller]);
        [$status]=syncApi($mobile,'/sync?project=all',null,$stamp);syncCheck($status===200,'team changes are propagated');
        [,,$stamp]=syncApi($mobile,'/sync?project=all');[,,$salesStamp]=syncApi($sales,'/sync');
        $localToken=uid();query('INSERT INTO plugin_tokens (id,workspace_id,user_id,token_hash,auth_version,expires_at) VALUES (?,?,?,?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))',[$localToken,$workspace,$owner,hash('sha256',uid())]);
        [$status]=syncApi($mobile,'/sync?project=all',null,$stamp);syncCheck($status===200,'own plugin connection state changes fingerprint');
        [$status]=syncApi($sales,'/sync',null,$salesStamp);syncCheck($status===304,'another account plugin state is not exposed');
        [,,$stamp]=syncApi($mobile,'/sync?project=all');query('UPDATE plugin_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?',[$localToken]);
        [$status]=syncApi($mobile,'/sync?project=all',null,$stamp);syncCheck($status===200,'plugin verification is propagated');
        [,,$stamp]=syncApi($mobile,'/sync?project=all');$poller=uid();query('INSERT INTO agent_poll_tokens (id,workspace_id,user_id,token_hash,auth_version) VALUES (?,?,?,?,1)',[$poller,$workspace,$owner,hash('sha256',uid())]);
        [$status]=syncApi($mobile,'/sync?project=all',null,$stamp);syncCheck($status===200,'owner sees poller connection changes');
        [,,$stamp]=syncApi($sales,'/sync');query("UPDATE users SET role='collector' WHERE id=?",[$seller]);
        [$status,$identity]=syncApi($sales,'/sync',null,$stamp);syncCheck($status===200&&$identity['user']['role']==='collector','role changes revalidate identity even with conditional requests');
        query('UPDATE users SET active=0 WHERE id=?',[$seller]);[$status]=syncApi($sales,'/sync',null,$stamp);syncCheck($status===401,'inactive account cannot obtain even a conditional stamp');
        echo "Workspace sync integration checks passed.\n";
    }
}finally{if(!$keep)syncCleanup([$workspace,$otherWorkspace]);}
