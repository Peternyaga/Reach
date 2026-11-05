<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/backend/bootstrap.php';
require dirname(__DIR__).'/backend/migrations.php';
require dirname(__DIR__).'/backend/hosted-writes.php';

function stageCheck(bool $condition,string $label): void {
    if(!$condition)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
}
function stageReject(callable $action,int $code,string $label): void {
    try{$action();throw new LogicException('Expected rejection: '.$label);}
    catch(RuntimeException $error){stageCheck($error->getCode()===$code,$label);}
    finally{if(db()->inTransaction())db()->rollBack();}
}

$workspace=uid();$user=uid();$leadId=uid();$source=uid();$workId=uid();
try {
    applySchema();
    query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$workspace,'Stage action test']);
    query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$user,$workspace,'Stage Tester','stage-'.uid().'@local.test',password_hash(uid(),PASSWORD_DEFAULT),'owner']);
    $_SESSION=['workspace_id'=>$workspace,'user_id'=>$user,'user_name'=>'Stage Tester','user_role'=>'owner'];
    $project=defaultProject($workspace);
    query('INSERT INTO leads (id,workspace_id,project_id,name,organisation,offering,notes,owner,owner_user_id) VALUES (?,?,?,?,?,?,?,?,?)',[$leadId,$workspace,$project,'Prospect','Example Business','Website','','Stage Tester',$user]);
    materialiseLeadRecords(query('SELECT * FROM leads WHERE workspace_id=? AND id=?',[$workspace,$leadId])->fetch());
    query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$source,$workspace,$leadId,'note',json_encode(['text'=>'I tried calling and nobody answered. Update progress as appropriate.','outcome'=>'no_answer','requested_by_user_id'=>$user],JSON_THROW_ON_ERROR),'Stage Tester']);
    query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,kind) VALUES (?,?,?,?,?)',[$workId,$workspace,$leadId,$source,'review_note']);
    $args=['id'=>$leadId,'stage'=>'Contacted','version'=>1,'source_activity_id'=>$source,'reason'=>'A reported call attempt changed progress; no conversation is claimed.'];
    stageReject(fn()=>hostedWrite('update_lead_stage',array_replace($args,['source_activity_id'=>uid()])),403,'unrelated work source rejected');
    stageReject(fn()=>hostedWrite('update_lead_stage',array_replace($args,['reason'=>''])),422,'sourced change needs a rationale');
    $change=hostedWrite('update_lead_stage',$args);
    stageCheck(($change['changed']??false)&&$change['version']===2&&query('SELECT stage FROM leads WHERE id=?',[$leadId])->fetchColumn()==='Contacted','sourced stage change saved');
    stageCheck(query('SELECT stage FROM opportunities WHERE workspace_id=? AND source_lead_id=?',[$workspace,$leadId])->fetchColumn()==='Contacted','matching opportunity progress follows the lead');
    $report=hostedWrite('resolve_agent_work',['id'=>$workId,'decision'=>'done','reason'=>'Recorded the reported call attempt as Contacted without claiming a conversation.']);
    stageCheck(($report['actions'][0]['type']??'')==='update_lead_stage'&&$report['actions'][0]['reason']===$args['reason'],'agent report retains the action and rationale');
    stageReject(fn()=>hostedWrite('update_lead_stage',array_replace($args,['stage'=>'Engaged','version'=>2])),403,'resolved work cannot authorize a second linked change');
    query("UPDATE opportunities SET stage='Proposal' WHERE workspace_id=? AND source_lead_id=?",[$workspace,$leadId]);
    $later=hostedWrite('update_lead_stage',['id'=>$leadId,'stage'=>'Engaged','version'=>2]);
    stageCheck(($later['changed']??false)&&query('SELECT stage FROM opportunities WHERE workspace_id=? AND source_lead_id=?',[$workspace,$leadId])->fetchColumn()==='Proposal','lead stage edit preserves a more advanced opportunity');
} finally {
    if(db()->inTransaction())db()->rollBack();
    query('DELETE FROM agent_work_items WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM activities WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM opportunities WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM contacts WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM organisations WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM leads WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM projects WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM users WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM workspaces WHERE id=?',[$workspace]);
}
