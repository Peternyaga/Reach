<?php
declare(strict_types=1);
require_once __DIR__.'/team-access.php';

function defaultProject(string $workspaceId): string {
    $project=query('SELECT id FROM projects WHERE workspace_id=? AND name=?',[$workspaceId,'General'])->fetchColumn();
    if($project)return $project;
    $id=uid();
    query('INSERT IGNORE INTO projects (id,workspace_id,name) VALUES (?,?,?)',[$id,$workspaceId,'General']);
    return (string)query('SELECT id FROM projects WHERE workspace_id=? AND name=?',[$workspaceId,'General'])->fetchColumn();
}

function resolveProject(?string $id,string $workspaceId): string {
    if($id===null||$id===''){
        if(isset($_SESSION['user_id'])&&($_SESSION['workspace_id']??null)===$workspaceId){$user=currentAuthority();if($user['role']==='owner'||$user['access_scope']==='workspace')return defaultProject($workspaceId);$list=accessibleProjects();if(!$list)fail('No project access. Ask the workspace owner.',403);foreach($list as $p)if($p['name']==='General')return $p['id'];return $list[0]['id'];}
        return defaultProject($workspaceId);
    }
    if(!preg_match('/^[a-f0-9]{32}$/',$id))fail('Invalid project');
    $found=query('SELECT id FROM projects WHERE workspace_id=? AND id=?',[$workspaceId,$id])->fetchColumn();
    if(!$found)fail('Project not found',404);
    if(isset($_SESSION['user_id'])&&($_SESSION['workspace_id']??null)===$workspaceId&&!userHasProject($_SESSION['user_id'],$id,$workspaceId))fail('Project not found',404);
    return $id;
}

// Context belongs to this request, never to mutable session or browser selection.
function setProjectContext(?string $id, bool $allowAll=false): void {
    if($id==='all'){
        if(!$allowAll)fail('Choose one project for this action',422);
        $GLOBALS['fieldwork_project']=null;
    }else $GLOBALS['fieldwork_project']=resolveProject($id,workspace());
}
function requestedProject(): ?string {
    if(array_key_exists('fieldwork_project',$GLOBALS))return $GLOBALS['fieldwork_project'];
    $id=$_GET['project']??null;
    if($id!==null&&!is_string($id))fail('Invalid project');
    if($id==='all')return null;
    return resolveProject($id,workspace());
}
function projectAllowsLead(array $lead): bool {

    $project=$lead['project_id']??null;
    if($project===null&&isset($lead['lead_id']))$project=query('SELECT project_id FROM leads WHERE workspace_id=? AND id=?',[workspace(),$lead['lead_id']])->fetchColumn();
    if(!$project&&isset($lead['id']))$project=query('SELECT project_id FROM leads WHERE workspace_id=? AND id=?',[workspace(),$lead['id']])->fetchColumn();
    if(!$project||!userHasProject($_SESSION['user_id'],$project))return false;
    return !isset($GLOBALS['fieldwork_project'])||$project===$GLOBALS['fieldwork_project'];
}
function requireProjectLead(array $lead): void {if(!projectAllowsLead($lead))fail('Record not found in this project',404);}
function beginWorkTransaction(): void {if(!db()->inTransaction()){db()->beginTransaction();try{if(isset($_SESSION['user_id'],$_SESSION['workspace_id']))lockWorkspaceAuthority();}catch(Throwable $e){db()->rollBack();throw $e;}}}
function moveLeadProject(array $lead,string $target,int $version): array {
    beginWorkTransaction();requireRole(['owner','manager']);requireProjectLead($lead);$target=resolveProject($target,workspace());
    beginWorkTransaction();
    $current=query('SELECT * FROM leads WHERE workspace_id=? AND id=? FOR UPDATE',[workspace(),$lead['id']])->fetch();
    if(!$current)fail('Lead not found',404);requireProjectLead($current);
    if((int)$current['version']!==$version)fail('This lead changed. Reload before moving.',409);
    if(query("SELECT id FROM outbound_emails WHERE workspace_id=? AND lead_id=? AND status='sending' LIMIT 1",[workspace(),$current['id']])->fetch())fail('An email is in progress. Check its delivery before moving this lead.',409);
    if($current['owner_user_id']&&!userHasProject($current['owner_user_id'],$target))fail('The current owner cannot access the destination. Reassign this lead to a member with access to both projects before moving.',409);
    if(query("SELECT id FROM tasks WHERE workspace_id=? AND lead_id=? AND status='pending' LIMIT 1",[workspace(),$current['id']])->fetch()&&$current['owner_user_id']===null)fail('Assign a responsible owner before moving a lead with pending follow-ups.',409);
    foreach(query("SELECT owner FROM tasks WHERE workspace_id=? AND lead_id=? AND status='pending'",[workspace(),$current['id']])->fetchAll() as $task){$members=$task['owner']===$current['owner']&&$current['owner_user_id']?[['id'=>$current['owner_user_id']]]:query("SELECT id FROM users WHERE workspace_id=? AND name=? AND active=1 AND role IN ('owner','manager','salesperson')",[workspace(),$task['owner']])->fetchAll();if(count($members)!==1||!userHasProject($members[0]['id'],$target))fail('A pending follow-up owner cannot access the destination. Reassign or resolve that follow-up before moving.',409);}
    if($current['project_id']===$target){db()->commit();return ['ok'=>true];}
    if(matchingLeads(['email'=>$current['email'],'phone'=>$current['phone']],$target))fail('A matching contact exists in the destination project. Review it before moving.',409);
    // All tasks, activity, opportunities, email and reports inherit the lead's boundary.
    // Pending instructions need human renewal after moving; old consent cannot travel.
    $pending=query("SELECT id,result FROM agent_work_items WHERE workspace_id=? AND lead_id=? AND status='pending' FOR UPDATE",[workspace(),$current['id']])->fetchAll();
    foreach($pending as $work){
        $result=json_decode($work['result']??'null',true)?:[];
        $result['summary']='Lead moved to another project. Review and queue fresh instructions in the destination project.';
        $result['project_move']=['from'=>$current['project_id'],'to'=>$target];
        query("UPDATE agent_work_items SET status='needs_review',completed_at=UTC_TIMESTAMP(),result=? WHERE workspace_id=? AND id=?",[json_encode($result,JSON_THROW_ON_ERROR),workspace(),$work['id']]);
    }
    // Organisations are private to the intake contact today. Clone shared links on move.
    $contacts=query('SELECT id,organisation_id FROM contacts WHERE workspace_id=? AND source_lead_id=? FOR UPDATE',[workspace(),$current['id']])->fetchAll();
    $organisationLinks=[];
    foreach($contacts as $contact){
        if($contact['organisation_id']){
            $org=query('SELECT * FROM organisations WHERE workspace_id=? AND id=?',[workspace(),$contact['organisation_id']])->fetch();
            $newOrg=uid();query('INSERT INTO organisations (id,workspace_id,project_id,name,origin_organisation_id) VALUES (?,?,?,?,?)',[$newOrg,workspace(),$target,$org['name'],$org['id']]);
            $organisationLinks[]=['contact_id'=>$contact['id'],'from'=>$org['id'],'to'=>$newOrg];
            query('UPDATE contacts SET organisation_id=? WHERE workspace_id=? AND id=?',[$newOrg,workspace(),$contact['id']]);
        }
        query('UPDATE contacts SET project_id=? WHERE workspace_id=? AND id=?',[$target,workspace(),$contact['id']]);
    }
    query('UPDATE leads SET project_id=?,version=version+1 WHERE workspace_id=? AND id=?',[$target,workspace(),$current['id']]);
    audit($current['id'],'project_moved',['from'=>$current['project_id'],'to'=>$target,'pending_work_review'=>count($pending),'organisation_links'=>$organisationLinks]);
    db()->commit();return ['ok'=>true];
}
