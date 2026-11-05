<?php
declare(strict_types=1);

// Project scope is independent of record ownership and OAuth capability scopes.
function projectAccessSql(string $column,?string $user=null,?string $tenant=null): string {
    if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/',$column))fail('Invalid scope column',500);
    $u=db()->quote($user??$_SESSION['user_id']);$w=db()->quote($tenant??workspace());
    return " EXISTS (SELECT 1 FROM users access_user WHERE access_user.id=$u AND access_user.workspace_id=$w AND access_user.active=1 AND (access_user.role='owner' OR access_user.access_scope='workspace' OR EXISTS (SELECT 1 FROM project_memberships pm WHERE pm.workspace_id=access_user.workspace_id AND pm.user_id=access_user.id AND pm.project_id=$column)))";
}
function userHasProject(string $user,string $project,?string $tenant=null): bool {
    $tenant??=workspace();
    return (bool)query('SELECT p.id FROM projects p WHERE p.workspace_id=? AND p.id=? AND '.projectAccessSql('p.id',$user,$tenant),[$tenant,$project])->fetchColumn();
}
function accessibleProjects(): array {
    return query('SELECT p.id,p.name,p.created_at FROM projects p WHERE p.workspace_id=? AND '.projectAccessSql('p.id').' ORDER BY p.name',[workspace()])->fetchAll();
}
function currentAuthority(): array {
    $user=query('SELECT * FROM users WHERE workspace_id=? AND id=?',[workspace(),$_SESSION['user_id']])->fetch();
    if(!$user||!$user['active']||isset($_SESSION['auth_version'])&&(int)$_SESSION['auth_version']!==(int)$user['auth_version'])fail('Access changed. Sign in or reconnect to continue.',401);
    $_SESSION['user_role']=$user['role'];$_SESSION['user_name']=$user['name'];
    return $user;
}
function lockWorkspaceAuthority(): void {
    query('SELECT id FROM workspaces WHERE id=? FOR UPDATE',[workspace()]);
    currentAuthority();
}
function eligibleAssignee(string $user,string $project): array {
    $member=query("SELECT id,name,role FROM users WHERE workspace_id=? AND id=? AND active=1 AND setup_pending=0 AND role IN ('owner','manager','salesperson')",[workspace(),$user])->fetch();
    if(!$member||!userHasProject($user,$project))fail('Choose an active salesperson or manager with access to this project. Collectors cannot own sales leads; assignment never grants access.',422);
    return $member;
}
function eligibleTaskOwner(string $name,string $project): array {
    $rows=query("SELECT id FROM users WHERE workspace_id=? AND name=? AND active=1 AND setup_pending=0 AND role IN ('owner','manager','salesperson')",[workspace(),$name])->fetchAll();
    if(count($rows)!==1)fail('Choose an active team member by their exact, unique name for this follow-up.',422);
    return eligibleAssignee($rows[0]['id'],$project);
}
function prepareLeadAssignment(array $lead,array $member,array $options): void {
    if(($lead['owner_user_id']??null)===$member['id'])return;
    $pending=!empty($lead['owner_user_id'])&&$lead['owner']!==''?query("SELECT id FROM tasks WHERE workspace_id=? AND lead_id=? AND owner=? AND status='pending'",[workspace(),$lead['id'],$lead['owner']])->fetchAll():[];
    $work=query("SELECT q.id,a.body FROM agent_work_items q JOIN activities a ON a.workspace_id=q.workspace_id AND a.id=q.source_activity_id WHERE q.workspace_id=? AND q.lead_id=? AND q.status='pending'",[workspace(),$lead['id']])->fetchAll();
    $affected=[];
    foreach($work as $q){$source=json_decode($q['body'],true);$requester=$source['requested_by_user_id']??null;if(!$requester)continue;
        $u=query('SELECT role FROM users WHERE workspace_id=? AND id=?',[workspace(),$requester])->fetch();
        if($u&&$u['role']==='salesperson'&&$requester!==$member['id']&&$requester!==$lead['collector_user_id'])$affected[]=$q['id'];
    }
    if($pending&&($options['reassign_pending_tasks']??false)!==true)fail('This lead has '.count($pending).' pending follow-ups for the current owner. Explicitly choose to transfer them with this assignment.',409);
    if($affected&&($options['review_pending_work']??false)!==true)fail('This assignment affects '.count($affected).' queued requests. Explicitly choose to pause them for review before reassigning.',409);
    foreach($pending as $t){query('UPDATE tasks SET owner=? WHERE workspace_id=? AND id=?',[$member['name'],workspace(),$t['id']]);audit($lead['id'],'task_reassigned',['task_id'=>$t['id'],'from'=>$lead['owner'],'to'=>$member['name']]);}
    foreach($affected as $id){query("UPDATE agent_work_items SET status='needs_review',completed_at=UTC_TIMESTAMP(),result=? WHERE workspace_id=? AND id=?",[json_encode(['summary'=>'Lead owner changed. Review the request and queue fresh instructions; old email consent cannot be reused.']),workspace(),$id]);audit($lead['id'],'agent_access_review',['work_id'=>$id,'reason'=>'Lead reassigned']);}
}
function projectTeam(?string $project,bool $full=false): array {
    if($project!==null)resolveProject($project,workspace());
    $members=query('SELECT id,name,email,role,active,setup_pending,access_scope,membership_version FROM users WHERE workspace_id=? ORDER BY name,id',[workspace()])->fetchAll();
    $visible=[];
    foreach($members as $member){
        $member['project_ids']=array_column(query('SELECT project_id FROM project_memberships WHERE workspace_id=? AND user_id=? ORDER BY project_id',[workspace(),$member['id']])->fetchAll(),'project_id');
        $inProject=$member['role']==='owner'||$member['access_scope']==='workspace'||$project!==null&&in_array($project,$member['project_ids'],true);
        if(!$full&&(!$member['active']||$member['setup_pending']||!in_array($member['role'],['owner','manager','salesperson'],true)))continue;
        if($project!==null&&!$inProject)continue;
        if($project===null&&role()!=='owner'){
            $shared=false;foreach(accessibleProjects() as $p)if(userHasProject($member['id'],$p['id'])){$shared=true;break;}
            if(!$shared)continue;
        }
        if(!$full)unset($member['email'],$member['membership_version']);
        if(role()!=='owner')$member['project_ids']=array_values(array_intersect($member['project_ids'],array_column(accessibleProjects(),'id')));
        $visible[]=$member;
    }
    return $visible;
}
function membershipConfig(array $data): array {
    $role=textField($data,'role',20,true);$scope=textField($data,'access_scope',20,true);
    if(!in_array($role,['owner','manager','salesperson','collector'],true)||!in_array($scope,['workspace','selected'],true))fail('Choose a valid role and access scope');
    $projects=$data['project_ids']??[];
    if(!is_array($projects)||!array_is_list($projects)||count($projects)>250)fail('Choose project access');
    foreach($projects as $p)if(!is_string($p))fail('Invalid project');else resolveProject($p,workspace());
    $projects=array_values(array_unique($projects));sort($projects);
    if($role==='owner'&&$scope!=='workspace')fail('Owners require workspace-wide access');
    if($scope==='selected'&&!$projects)fail('Select at least one project');
    return ['role'=>$role,'access_scope'=>$scope,'project_ids'=>$scope==='workspace'?[]:$projects];
}
function membershipSnapshot(array $member): array {
    return ['role'=>$member['role'],'active'=>(bool)$member['active'],'setup_pending'=>(bool)$member['setup_pending'],'access_scope'=>$member['access_scope'],'project_ids'=>array_column(query('SELECT project_id FROM project_memberships WHERE workspace_id=? AND user_id=? ORDER BY project_id',[$member['workspace_id'],$member['id']])->fetchAll(),'project_id'),'membership_version'=>(int)$member['membership_version']];
}
function membershipAudit(string $member,?array $before,array $after,string $action): void {
    query('INSERT INTO membership_audit (id,workspace_id,member_user_id,actor_user_id,action,before_state,after_state) VALUES (?,?,?,?,?,?,?)',[uid(),workspace(),$member,$_SESSION['user_id'],$action,$before===null?null:json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR)]);
}
function storeMembershipProjects(string $member,array $projects): void {
    query('DELETE FROM project_memberships WHERE workspace_id=? AND user_id=?',[workspace(),$member]);
    foreach($projects as $p)query('INSERT INTO project_memberships (workspace_id,user_id,project_id) VALUES (?,?,?)',[workspace(),$member,$p]);
}
function updateMembership(string $id,array $data): array {
    beginWorkTransaction();requireRole(['owner']);
    $member=query('SELECT * FROM users WHERE workspace_id=? AND id=? FOR UPDATE',[workspace(),$id])->fetch();
    if(!$member)fail('Team member not found',404);
    if(!is_int($data['membership_version']??null)||$data['membership_version']!==(int)$member['membership_version'])fail('This membership changed. Reload the saved membership and review your draft before retrying.',409);
    $config=membershipConfig($data);$active=$data['active']??(bool)$member['active'];
    if(!is_bool($active))fail('Choose an account status');
    if($member['setup_pending']&&$active)fail('A pending member must redeem their private invitation first');
    if($member['role']==='owner'&&$member['active']&&($config['role']!=='owner'||!$active)&&(int)query("SELECT COUNT(*) FROM users WHERE workspace_id=? AND role='owner' AND active=1",[workspace()])->fetchColumn()<=1)fail('Keep at least one active workspace owner.',409);
    $before=membershipSnapshot($member);
    $rank=['collector'=>0,'salesperson'=>1,'manager'=>2,'owner'=>3];$demoted=$rank[$config['role']]<$rank[$member['role']];
    $lostProjects=[];foreach(query('SELECT id FROM projects WHERE workspace_id=?',[workspace()])->fetchAll() as $p){
        if(!$active||$demoted||$config['role']==='collector'||$config['access_scope']==='selected'&&!in_array($p['id'],$config['project_ids'],true))$lostProjects[]=$p['id'];
    }
    if($lostProjects){
        $marks=implode(',',array_fill(0,count($lostProjects),'?'));
        $leads=(int)query("SELECT COUNT(*) FROM leads WHERE workspace_id=? AND owner_user_id=? AND project_id IN ($marks)",array_merge([workspace(),$id],$lostProjects))->fetchColumn();
        $tasks=(int)query("SELECT COUNT(*) FROM tasks t JOIN leads l ON l.workspace_id=t.workspace_id AND l.id=t.lead_id WHERE t.workspace_id=? AND t.owner=? AND t.status='pending' AND l.project_id IN ($marks)",array_merge([workspace(),$member['name']],$lostProjects))->fetchColumn();
        $work=(int)query("SELECT COUNT(*) FROM agent_work_items q JOIN leads l ON l.workspace_id=q.workspace_id AND l.id=q.lead_id JOIN activities a ON a.workspace_id=q.workspace_id AND a.id=q.source_activity_id WHERE q.workspace_id=? AND q.status='pending' AND l.project_id IN ($marks) AND (l.owner_user_id=? OR JSON_UNQUOTE(JSON_EXTRACT(a.body,'$.requested_by_user_id'))=?)",array_merge([workspace()],$lostProjects,[$id,$id]))->fetchColumn();
        if($leads||$tasks||$work)fail("Access change blocked: $leads owned leads, $tasks pending follow-ups and $work queued requests would lose a responsible member. Reassign the leads and follow-ups, and review/close or requeue the requests with an authorized requester, then retry. Nothing was changed.",409);
    }
    if(query("SELECT id FROM outbound_emails WHERE workspace_id=? AND user_id=? AND status='sending' LIMIT 1",[workspace(),$id])->fetch())fail('An email is being sent. Check its result before changing access.',409);
    query('UPDATE users SET role=?,access_scope=?,active=?,membership_version=membership_version+1,auth_version=auth_version+1 WHERE workspace_id=? AND id=?',[$config['role'],$config['access_scope'],(int)$active,workspace(),$id]);
    storeMembershipProjects($id,$config['project_ids']);
    $after=$config+['active'=>$active,'setup_pending'=>(bool)$member['setup_pending'],'membership_version'=>(int)$member['membership_version']+1];
    membershipAudit($id,$before,$after,'membership_updated');
    query('UPDATE oauth_grants SET revoked_at=UTC_TIMESTAMP() WHERE workspace_id=? AND user_id=? AND revoked_at IS NULL',[workspace(),$id]);
    query('UPDATE agent_poll_tokens SET revoked_at=UTC_TIMESTAMP() WHERE workspace_id=? AND user_id=? AND revoked_at IS NULL',[workspace(),$id]);
    query('UPDATE agent_poll_pairings SET used_at=UTC_TIMESTAMP() WHERE workspace_id=? AND user_id=? AND used_at IS NULL',[workspace(),$id]);
    // Invite links remain usable and redeem the latest stored configuration. Reset links are invalidated.
    query("UPDATE account_tokens SET used_at=UTC_TIMESTAMP() WHERE workspace_id=? AND user_id=? AND purpose='reset' AND used_at IS NULL",[workspace(),$id]);
    db()->commit();return ['ok'=>true,'membership_version'=>$after['membership_version']];
}
function requireQueuedRequester(array $work): void {
    $body=query('SELECT body FROM activities WHERE workspace_id=? AND id=?',[workspace(),$work['source_activity_id']])->fetchColumn();
    $note=json_decode($body?:'null',true);$requester=$note['requested_by_user_id']??null;
    // Old notes without provenance may only be reviewed by humans, never executed.
    if(!$requester)fail('This legacy request needs human review and fresh authenticated instructions.',403);
    $user=query("SELECT id,role FROM users WHERE workspace_id=? AND id=? AND active=1 AND role IN ('owner','manager','salesperson')",[workspace(),$requester])->fetch();
    $lead=query('SELECT * FROM leads WHERE workspace_id=? AND id=?',[workspace(),$work['lead_id']])->fetch();
    if(!$user||!$lead||!userHasProject($requester,$lead['project_id'])||$user['role']==='salesperson'&&!in_array($requester,[$lead['owner_user_id'],$lead['collector_user_id']],true))fail('The requester no longer has authority for this lead. Review and queue fresh instructions.',403);
}
