<?php
declare(strict_types=1);
require_once __DIR__.'/intake.php';
require_once __DIR__.'/projects.php';
require_once __DIR__.'/lead-profile.php';
require_once __DIR__.'/lead-research.php';
require_once __DIR__.'/followup-time.php';

function hostedWriteId(array $args,string $key): string {
    $id=textField($args,$key,32,true);
    if(!preg_match('/^[a-f0-9]{32}$/',$id))fail('Invalid '.$key,422);
    return $id;
}
function hostedWriteArgs(array $args,array $allowed): void {
    foreach(array_keys($args) as $key)if(!in_array($key,$allowed,true))fail('Unexpected argument',422);
}
function hostedWriteVersion(array $args): int {
    $version=$args['version']??null;
    if(!is_int($version)||$version<1)fail('Invalid lead version',422);
    return $version;
}
function hostedWriteDue(array $args,string $key='due_at'): array {
    $raw=textField($args,$key,40,true);
    return normaliseFollowupTime($raw);
}
function hostedWriteLead(string $id,bool $lock=false): array {
    $lead=query('SELECT * FROM leads WHERE workspace_id=? AND id=?'.($lock?' FOR UPDATE':''),[workspace(),$id])->fetch();
    if(!$lead||!canAccessLead($lead))fail('Lead not found',404);
    return $lead;
}
function hostedWriteTask(string $id): array {
    $task=query('SELECT t.*,l.project_id,l.owner_user_id,l.collector_user_id,l.stage FROM tasks t JOIN leads l ON l.id=t.lead_id AND l.workspace_id=t.workspace_id WHERE t.workspace_id=? AND t.id=? FOR UPDATE',[workspace(),$id])->fetch();
    if(!$task||!canAccessLead($task))fail('Follow-up not found',404);
    return $task;
}
function hostedWriteConflict(string $owner,string $due,string $exclude=''): void {
    $sql="SELECT id FROM tasks WHERE workspace_id=? AND owner=? AND status='pending' AND due_at>=DATE_SUB(?,INTERVAL 20 MINUTE) AND due_at<DATE_ADD(?,INTERVAL 20 MINUTE)";
    $args=[workspace(),$owner,$due,$due];
    if($exclude!==''){$sql.=' AND id<>?';$args[]=$exclude;}
    if(query($sql.' LIMIT 1',$args)->fetch())fail('This person already has a follow-up near that time',409);
}
function hostedWriteAgentItem(string $id): array {
    $work=query('SELECT w.*,l.project_id,l.owner_user_id,l.collector_user_id,l.owner,l.stage FROM agent_work_items w JOIN leads l ON l.id=w.lead_id AND l.workspace_id=w.workspace_id WHERE w.workspace_id=? AND w.id=? FOR UPDATE',[workspace(),$id])->fetch();
    if(!$work||!canAccessLead($work))fail('Agent work item not found',404);
    if($work['status']!=='pending')fail('This work item was already handled',409);
    requireQueuedRequester($work);
    return $work;
}
function agentOpportunityActions(array $work): array {
    $actions=[];
    $history=query("SELECT body FROM activities WHERE workspace_id=? AND lead_id=? AND kind='opportunity_created' AND created_at>=? ORDER BY created_at,id",[workspace(),$work['lead_id'],$work['created_at']])->fetchAll();
    foreach($history as $entry){
        $saved=json_decode($entry['body'],true);
        if(($saved['source_work_item']??null)===$work['id'])$actions[]=['type'=>'create_opportunity','opportunity_id'=>$saved['opportunity_id'],'offering'=>$saved['offering']];
    }
    return $actions;
}
function agentEmailActions(array $work): array {
    $actions=[];
    $sent=query('SELECT id,recipient,subject,status FROM outbound_emails WHERE workspace_id=? AND work_id=? ORDER BY created_at',[workspace(),$work['id']])->fetchAll();
    foreach($sent as $email)$actions[]=['type'=>'send_email','email_id'=>$email['id'],'recipient'=>$email['recipient'],'subject'=>$email['subject'],'status'=>$email['status']];
    return $actions;
}
function agentStageActions(array $work): array {
    $actions=[];
    $history=query("SELECT body FROM activities WHERE workspace_id=? AND lead_id=? AND kind='updated' ORDER BY created_at,id",[workspace(),$work['lead_id']])->fetchAll();
    foreach($history as $entry){
        $change=json_decode($entry['body'],true);
        if(($change['source_activity_id']??null)!==$work['source_activity_id']||!isset($change['from'],$change['to']))continue;
        $actions[]=['type'=>'update_lead_stage','from'=>$change['from'],'to'=>$change['to'],'reason'=>$change['reason']??''];
    }
    return $actions;
}
function hostedWrite(string $name,array $args): array {
    if(str_starts_with($name,'update_lead_')&&isset(profileFields()[substr($name,12)])){
        hostedWriteArgs($args,['id','version','value','provenance','source_activity_id','evidence_type','source_url']);
        $id=hostedWriteId($args,'id');$field=substr($name,12);unset($args['id']);
        $args['changes']=[$field=>$args['value']??null];unset($args['value']);
        return editLeadProfile($id,$args);
    }
    if($name==='update_lead_research'){
        hostedWriteArgs($args,['id','revision','document','request_key']);$id=hostedWriteId($args,'id');unset($args['id']);return updateLeadResearch($id,$args,true);
    }
    if($name==='create_lead'){
        hostedWriteArgs($args,['name','organisation','email','phone','source','offering','notes','project_id','request_key','research','contacts']);
        $project=resolveProject(isset($args['project_id'])?hostedWriteId($args,'project_id'):null,workspace());
        if(array_key_exists('research',$args)&&!is_array($args['research']))fail('Research must be an object',422);
        $result=createLead(leadValues($args),textField($args,'request_key',80,true),$project,$args['research']??null,true,$args['contacts']??null);
        if(isset($result['matches']))fail('A matching contact exists in this project. Review it before adding another.',409);
        return $result;
    }
    if(!in_array(role(),['owner','manager','salesperson'],true))fail('Your role cannot change leads or follow-ups',403);
    if($name==='create_agent_opportunity'){
        hostedWriteArgs($args,['id','offering','version']);
        $id=hostedWriteId($args,'id');$offering=textField($args,'offering',150,true);$version=hostedWriteVersion($args);
        beginWorkTransaction();$work=hostedWriteAgentItem($id);
        if(!in_array($work['kind'],['user_request','review_note'],true))fail('An explicit user request or note is required to add an opportunity',403);
        $source=query("SELECT kind,body FROM activities WHERE workspace_id=? AND lead_id=? AND id=? AND kind IN ('agent_request','agent_correction_requested','note')",[workspace(),$work['lead_id'],$work['source_activity_id']])->fetch();
        $requestBody=$source?json_decode($source['body'],true):null;
        if(empty($requestBody['requested_by_user_id'])||!empty($requestBody['agent_generated']))fail('Authenticated request provenance is unavailable',403);
        $lead=hostedWriteLead($work['lead_id'],true);
        // Stable per request and offering, so an interrupted agent cannot duplicate a deal.
        $request='agent-op:'.$id.':'.substr(hash('sha256',$offering),0,32);
        $prior=query('SELECT id FROM opportunities WHERE workspace_id=? AND request_key=?',[workspace(),$request])->fetch();
        if($prior){db()->commit();return ['id'=>$prior['id'],'replayed'=>true];}
        if((int)$lead['version']!==$version)fail('This lead changed. Reload before adding an opportunity.',409);
        if($lead['stage']==='Lost')fail('Reopen this lead before adding an opportunity',409);
        $contact=query('SELECT id FROM contacts WHERE workspace_id=? AND source_lead_id=?',[workspace(),$lead['id']])->fetch();
        if(!$contact)fail('Contact record is unavailable',409);
        $opportunity=uid();
        query("INSERT INTO opportunities (id,workspace_id,contact_id,offering,stage,request_key) VALUES (?,?,?,?,'New',?)",[$opportunity,workspace(),$contact['id'],$offering,$request]);
        audit($lead['id'],'opportunity_created',['opportunity_id'=>$opportunity,'offering'=>$offering,'source_work_item'=>$id]);
        db()->commit();return ['id'=>$opportunity,'offering'=>$offering,'stage'=>'New'];
    }
    if($name==='update_lead_stage'){
        hostedWriteArgs($args,['id','stage','version','source_activity_id','reason']);
        $id=hostedWriteId($args,'id');$stage=textField($args,'stage',30,true);$version=hostedWriteVersion($args);
        $source=isset($args['source_activity_id'])?hostedWriteId($args,'source_activity_id'):null;
        $reason=textField($args,'reason',1000);
        if($source!==null&&$reason==='')fail('Explain the user evidence for this stage change',422);
        if(!in_array($stage,['New','Contacted','Engaged','Qualified','Proposal','Negotiation','Won'],true))fail('Use the Fieldwork website to close a lead',422);
        beginWorkTransaction();$lead=hostedWriteLead($id,true);
        if((int)$lead['version']!==$version)fail('This lead changed. Reload before editing.',409);
        if($source!==null){
            $activity=query('SELECT a.body FROM activities a JOIN agent_work_items w ON w.workspace_id=a.workspace_id AND w.lead_id=a.lead_id AND w.source_activity_id=a.id WHERE a.workspace_id=? AND a.lead_id=? AND a.id=? AND w.status=?',[workspace(),$id,$source,'pending'])->fetch();
            $body=$activity?json_decode($activity['body'],true):null;
            if(!$activity||empty($body['requested_by_user_id'])||!empty($body['agent_generated']))fail('Use a pending authenticated task as the stage source',403);
            requireQueuedRequester(['source_activity_id'=>$source,'lead_id'=>$id]);
        }
        if($lead['stage']===$stage){db()->commit();return ['ok'=>true,'stage'=>$stage,'version'=>$version,'changed'=>false];}
        $changed=query('UPDATE leads SET stage=?,version=version+1 WHERE workspace_id=? AND id=? AND version=?',[$stage,workspace(),$id,$version]);
        if(!$changed->rowCount())fail('This lead changed. Reload before editing.',409);
        query('UPDATE opportunities SET stage=?,version=version+1 WHERE workspace_id=? AND source_lead_id=? AND stage=?',[$stage,workspace(),$id,$lead['stage']]);
        audit($id,'updated',['stage'=>$stage,'from'=>$lead['stage'],'to'=>$stage,'source_activity_id'=>$source,'reason'=>$reason,'text'=>'Lead progress changed from '.$lead['stage'].' to '.$stage.'.']);db()->commit();return ['ok'=>true,'stage'=>$stage,'version'=>$version+1,'changed'=>true];
    }
    if($name==='assign_lead'){
        hostedWriteArgs($args,['id','user_id','version','reassign_pending_tasks','review_pending_work']);
        requireRole(['owner','manager']);$id=hostedWriteId($args,'id');$target=hostedWriteId($args,'user_id');$version=hostedWriteVersion($args);
        beginWorkTransaction();$lead=query('SELECT * FROM leads WHERE workspace_id=? AND id=? FOR UPDATE',[workspace(),$id])->fetch();
        if(!$lead)fail('Lead not found',404);requireProjectLead($lead);
        $member=eligibleAssignee($target,$lead['project_id']);
        if((int)$lead['version']!==$version)fail('This lead changed. Reload before assigning.',409);
        prepareLeadAssignment($lead,$member,$args);
        $changed=query('UPDATE leads SET owner_user_id=?,owner=?,version=version+1 WHERE workspace_id=? AND id=? AND version=?',[$target,$member['name'],workspace(),$id,$version]);
        if(!$changed->rowCount())fail('This lead changed. Reload before assigning.',409);
        query('INSERT INTO assignment_events (id,workspace_id,lead_id,from_user_id,to_user_id,assigned_by) VALUES (?,?,?,?,?,?)',[uid(),workspace(),$id,$lead['owner_user_id'],$target,$_SESSION['user_id']]);
        audit($id,'assigned',['from'=>$lead['owner_user_id'],'to'=>$target,'name'=>$member['name']]);db()->commit();
        return ['ok'=>true,'owner'=>$member['name'],'version'=>$version+1];
    }
    if($name==='add_note'){
        hostedWriteArgs($args,['id','note','outcome']);
        $id=hostedWriteId($args,'id');$note=textField($args,'note',10000,true);$outcome=textField($args,'outcome',30);
        if(!in_array($outcome,['','busy','no_answer','connected','other'],true))fail('Invalid call outcome',422);
        beginWorkTransaction();hostedWriteLead($id,true);$activity=uid();
        query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$activity,workspace(),$id,'note',json_encode(['text'=>$note,'outcome'=>$outcome,'requested_by_user_id'=>$_SESSION['user_id'],'agent_generated'=>true],JSON_THROW_ON_ERROR),$_SESSION['user_name']]);
        // An agent-authored note is part of the current work, not a new instruction.
        db()->commit();return ['ok'=>true,'queued_for_agent'=>false];
    }
    if($name==='create_task'){
        hostedWriteArgs($args,['lead_id','title','owner_user_id','due_at']);
        $leadId=hostedWriteId($args,'lead_id');$title=textField($args,'title',250,true);[$raw,$due]=hostedWriteDue($args);
        $ownerId=isset($args['owner_user_id'])?hostedWriteId($args,'owner_user_id'):$_SESSION['user_id'];
        if(role()==='salesperson'&&$ownerId!==$_SESSION['user_id'])fail('Only managers can schedule another teammate',403);
        beginWorkTransaction();$lead=hostedWriteLead($leadId,true);
        if($lead['stage']==='Lost')fail('Reopen this lead before adding a follow-up',409);
        $member=eligibleAssignee($ownerId,$lead['project_id']);
        hostedWriteConflict($member['name'],$due);$id=uid();
        query('INSERT INTO tasks (id,workspace_id,lead_id,title,owner,due_at) VALUES (?,?,?,?,?,?)',[$id,workspace(),$leadId,$title,$member['name'],$due]);
        audit($leadId,'task_created',['title'=>$title,'due_at'=>$raw,'owner'=>$member['name']]);db()->commit();
        return ['id'=>$id,'due_at'=>$due,'owner'=>$member['name']];
    }
    if($name==='reschedule_task'){
        hostedWriteArgs($args,['id','expected_due_at','due_at']);
        $id=hostedWriteId($args,'id');$expected=textField($args,'expected_due_at',19,true);[$raw,$due]=hostedWriteDue($args);
        if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',$expected))fail('Use the current UTC due_at from list_tasks',422);
        beginWorkTransaction();$task=hostedWriteTask($id);
        if($task['status']!=='pending')fail('Only pending follow-ups can be moved',409);
        eligibleTaskOwner($task['owner'],$task['project_id']);
        if($task['stage']==='Lost')fail('Reopen this lead before moving a follow-up',409);
        if($task['due_at']===$due){db()->commit();return ['ok'=>true,'replayed'=>true,'due_at'=>$due];}
        if($task['due_at']!==$expected)fail('This follow-up changed. Reload the schedule.',409);
        hostedWriteConflict($task['owner'],$due,$id);
        query('UPDATE tasks SET due_at=? WHERE workspace_id=? AND id=?',[$due,workspace(),$id]);
        audit($task['lead_id'],'task_rescheduled',['title'=>$task['title'],'from'=>$task['due_at'],'to'=>$raw]);db()->commit();
        return ['ok'=>true,'due_at'=>$due];
    }
    if($name==='complete_task'){
        hostedWriteArgs($args,['id']);$id=hostedWriteId($args,'id');
        beginWorkTransaction();$task=hostedWriteTask($id);
        if($task['status']==='cancelled')fail('A cancelled follow-up cannot be completed',409);
        if($task['status']==='completed'){db()->commit();return ['ok'=>true,'replayed'=>true];}
        query("UPDATE tasks SET status='completed' WHERE workspace_id=? AND id=? AND status='pending'",[workspace(),$id]);
        audit($task['lead_id'],'task_completed',['title'=>$task['title']]);db()->commit();return ['ok'=>true];
    }
    if($name==='schedule_agent_followup'){
        hostedWriteArgs($args,['id','title','due_at','summary']);
        $id=hostedWriteId($args,'id');$title=textField($args,'title',250,true);$summary=textField($args,'summary',2000,true);[$raw,$due]=hostedWriteDue($args);
        beginWorkTransaction();$work=hostedWriteAgentItem($id);
        if($work['kind']==='reverse_work')fail('Use reverse_agent_work for reversal requests',422);
        if($work['stage']==='Lost')fail('Reopen this lead before adding a follow-up',409);
        if(!$work['owner_user_id']||$work['owner']==='')fail('Assign this lead before scheduling a follow-up',422);
        $member=query("SELECT id FROM users WHERE id=? AND workspace_id=? AND active=1 AND role IN ('owner','manager','salesperson')",[$work['owner_user_id'],workspace()])->fetch();
        eligibleAssignee($work['owner_user_id'],$work['project_id']);
        hostedWriteConflict($work['owner'],$due);$taskId=uid();
        query('INSERT INTO tasks (id,workspace_id,lead_id,title,owner,due_at) VALUES (?,?,?,?,?,?)',[$taskId,workspace(),$work['lead_id'],$title,$work['owner'],$due]);
        audit($work['lead_id'],'task_created',['title'=>$title,'due_at'=>$raw,'source_work_item'=>$id]);
        $result=['summary'=>$summary,'actions'=>[['type'=>'create_follow_up','task_id'=>$taskId,'title'=>$title,'due_at'=>$due,'owner'=>$work['owner']]],'task_id'=>$taskId,'due_at'=>$due,'owner'=>$work['owner']];
        $result['actions']=array_merge(agentStageActions($work),agentOpportunityActions($work),agentEmailActions($work),$result['actions']);
        query("UPDATE agent_work_items SET status='done',completed_at=UTC_TIMESTAMP(),result=? WHERE workspace_id=? AND id=?",[json_encode($result,JSON_THROW_ON_ERROR),workspace(),$id]);
        db()->commit();return ['ok'=>true]+$result;
    }
    if($name==='resolve_agent_work'){
        hostedWriteArgs($args,['id','decision','reason','sources']);
        $id=hostedWriteId($args,'id');$decision=textField($args,'decision',30,true);$reason=textField($args,'reason',1000,true);
        if(!in_array($decision,['done','needs_review'],true))fail('Invalid decision',422);
        $sources=$args['sources']??[];
        if(!is_array($sources)||!array_is_list($sources)||count($sources)>10)fail('Invalid sources',422);
        foreach($sources as $url){if(!is_string($url)||strlen($url)>1000||!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['http','https'],true)||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null)fail('Use http(s) source URLs without credentials',422);}
        beginWorkTransaction();$work=hostedWriteAgentItem($id);
        if($work['kind']==='reverse_work')fail('Use reverse_agent_work for reversal requests',422);
        $actions=array_merge(agentStageActions($work),agentOpportunityActions($work),agentEmailActions($work));
        foreach(query("SELECT body FROM activities WHERE workspace_id=? AND lead_id=? AND kind='contacts_updated' AND created_at>=? ORDER BY created_at,id",[workspace(),$work['lead_id'],$work['created_at']])->fetchAll() as $entry){
            $saved=json_decode($entry['body'],true);if(($saved['source_activity_id']??null)===$work['source_activity_id'])$actions[]=['type'=>'update_lead_contacts','before'=>$saved['before'],'after'=>$saved['after']];
        }
        $history=query("SELECT body FROM activities WHERE workspace_id=? AND lead_id=? AND kind='profile_updated' AND created_at>=? ORDER BY created_at,id",[workspace(),$work['lead_id'],$work['created_at']])->fetchAll();
        foreach($history as $entry){
            $audit=json_decode($entry['body'],true);
            if(($audit['source_activity_id']??null)!==$work['source_activity_id'])continue;
            foreach(($audit['changes']??[]) as $field=>$change)$actions[]=['type'=>'update_lead_profile','field'=>$field,'from'=>$change['from']??'','to'=>$change['to']??''];
            if(!empty($audit['source_url'])&&!in_array($audit['source_url'],$sources,true))$sources[]=$audit['source_url'];
        }
        $sources=array_slice($sources,0,10);
        $result=['decision'=>$decision,'summary'=>$reason,'reason'=>$reason,'actions'=>$actions,'sources'=>$sources];
        query('UPDATE agent_work_items SET status=?,completed_at=UTC_TIMESTAMP(),result=? WHERE workspace_id=? AND id=?',[$decision,json_encode($result,JSON_THROW_ON_ERROR),workspace(),$id]);
        db()->commit();return ['ok'=>true]+$result;
    }
    if($name==='reverse_agent_work'){
        hostedWriteArgs($args,['id']);$id=hostedWriteId($args,'id');
        beginWorkTransaction();$work=hostedWriteAgentItem($id);
        if($work['kind']!=='reverse_work'||!$work['parent_work_id'])fail('This is not a reversal request',422);
        $original=query('SELECT * FROM agent_work_items WHERE workspace_id=? AND id=? FOR UPDATE',[workspace(),$work['parent_work_id']])->fetch();
        if(!$original||$original['status']!=='done')fail('Original report is unavailable',409);
        $previous=json_decode($original['result']??'null',true);
        $action=$previous['actions'][0]??null;
        $status='done';$changes=[];
        if(is_array($action)&&($action['type']??'')==='create_follow_up'){
            $task=query('SELECT * FROM tasks WHERE workspace_id=? AND id=? FOR UPDATE',[workspace(),$action['task_id']])->fetch();
            if(!$task||$task['lead_id']!==$work['lead_id']||$task['status']!=='pending'||$task['title']!==$action['title']||$task['due_at']!==$action['due_at']||$task['owner']!==$action['owner']){
                $status='needs_review';$summary='The original follow-up was changed or completed. No automatic reversal was made.';
            }else{
                query("UPDATE tasks SET status='cancelled' WHERE workspace_id=? AND id=? AND status='pending'",[workspace(),$task['id']]);
                audit($work['lead_id'],'task_cancelled',['title'=>$task['title'],'reason'=>'Requested reversal of agent work '.$original['id']]);
                $changes[]=['type'=>'cancel_follow_up','task_id'=>$task['id']];
                $summary='Cancelled the unchanged follow-up created by the earlier agent run.';
            }
        }else{if(!empty($previous['actions'])){$status='needs_review';$summary='The earlier changes require manual review before reversal. No automatic reversal was made.';}else{$summary='The earlier report recorded no reversible changes.';}}
        $result=['summary'=>$summary,'actions'=>$changes,'original_work_id'=>$original['id']];
        query('UPDATE agent_work_items SET status=?,completed_at=UTC_TIMESTAMP(),result=? WHERE workspace_id=? AND id=?',[$status,json_encode($result,JSON_THROW_ON_ERROR),workspace(),$id]);
        db()->commit();return ['ok'=>true,'status'=>$status]+$result;
    }
    fail('Unknown write tool',404);
}
