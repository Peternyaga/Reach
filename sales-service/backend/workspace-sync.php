<?php
declare(strict_types=1);

// A scoped fingerprint, not a timestamp: catches same-second edits, removals,
// task status changes and MCP writes without triggers or a persistent PHP worker.
function workspaceSyncStamp(): string {
    $digest=hash_init('sha256');
    hash_update($digest,json_encode([workspace(),$_SESSION['user_id'],role(),$_SESSION['user_name']],JSON_THROW_ON_ERROR));
    $manager=in_array(role(),['owner','manager'],true);$collector=role()==='collector';
    $scope=$manager?'':($collector?' AND l.collector_user_id=?':' AND (l.owner_user_id=? OR l.collector_user_id=?)');
    $args=$manager?[workspace()]:($collector?[workspace(),$_SESSION['user_id']]:[workspace(),$_SESSION['user_id'],$_SESSION['user_id']]);
    $scope.=' AND '.projectAccessSql('l.project_id');
            if($project=requestedProject()){$scope.=' AND l.project_id=?';$args[]=$project;}
    $scan=static function(string $label,string $sql,array $values)use($digest): void {
        hash_update($digest,$label);
        $rows=query($sql,$values);
        while($row=$rows->fetch())hash_update($digest,json_encode($row,JSON_THROW_ON_ERROR));
    };
    if(!db()->inTransaction())db()->beginTransaction();
    try {
        $scan('workspace','SELECT name,timezone FROM workspaces WHERE id=?',[workspace()]);
        $scan('projects','SELECT p.id,p.name FROM projects p WHERE p.workspace_id=? AND '.projectAccessSql('p.id').' ORDER BY p.id',[workspace()]);
        $scan('membership','SELECT role,access_scope,membership_version,auth_version FROM users WHERE workspace_id=? AND id=?',[workspace(),$_SESSION['user_id']]);
        // Hash only non-secret columns. All current lead edits increment version.
        $scan('leads',"SELECT l.id,l.version,l.project_id,l.owner_user_id,l.collector_user_id FROM leads l WHERE l.workspace_id=?$scope ORDER BY l.id",$args);
        if(!$collector){
            $join=' JOIN leads l ON l.id=x.lead_id AND l.workspace_id=x.workspace_id';
            $scan('tasks',"SELECT x.id,x.title,x.owner,x.due_at,x.status FROM tasks x$join WHERE x.workspace_id=?$scope ORDER BY x.id",$args);
            $scan('activities',"SELECT x.id,SHA2(x.body,256) AS body_hash,x.kind,x.actor FROM activities x$join WHERE x.workspace_id=?$scope ORDER BY x.id",$args);
            $scan('opportunities',"SELECT x.id,x.version,x.offering,x.stage FROM opportunities x JOIN contacts c ON c.id=x.contact_id AND c.workspace_id=x.workspace_id JOIN leads l ON l.id=c.source_lead_id AND l.workspace_id=c.workspace_id WHERE x.workspace_id=?$scope ORDER BY x.id",$args);
            $scan('agent_work',"SELECT x.id,x.kind,x.status,SHA2(COALESCE(x.result,''),256) AS result_hash FROM agent_work_items x$join WHERE x.workspace_id=?$scope ORDER BY x.id",$args);
        }
        if($manager)hash_update($digest,json_encode(projectTeam(requestedProject(),role()==='owner'),JSON_THROW_ON_ERROR));
        $scan('connections','SELECT id,scope,revoked_at,access_expires_at,refresh_expires_at,refresh_expires_at>UTC_TIMESTAMP() AS unexpired FROM oauth_grants WHERE workspace_id=? AND user_id=? ORDER BY id',[workspace(),$_SESSION['user_id']]);
        $scan('local_plugin','SELECT id,auth_version,expires_at,last_used_at,expires_at>UTC_TIMESTAMP() AS unexpired FROM plugin_tokens WHERE workspace_id=? AND user_id=? ORDER BY id',[workspace(),$_SESSION['user_id']]);
        if(!$collector){
            $scan('mailbox','SELECT id,revision,active,user_id,smtp_host,smtp_port,security,username,from_email,from_name,updated_at FROM mail_accounts WHERE workspace_id=? AND user_id=?',[workspace(),$_SESSION['user_id']]);
            $scan('project_senders','SELECT s.project_id,s.account_id FROM project_senders s WHERE s.workspace_id=? AND s.user_id=? AND '.projectAccessSql('s.project_id').' ORDER BY s.project_id',[workspace(),$_SESSION['user_id']]);
            $scan('outbound_emails',"SELECT x.id,x.status,x.recipient,x.subject,x.detail FROM outbound_emails x JOIN leads l ON l.id=x.lead_id AND l.workspace_id=x.workspace_id WHERE x.workspace_id=?$scope ORDER BY x.id",$args);
        }
        if(role()==='owner')$scan('pollers','SELECT id,last_used_at,revoked_at FROM agent_poll_tokens WHERE workspace_id=? ORDER BY id',[workspace()]);
        db()->commit();
        return '"'.hash_final($digest).'"';
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
