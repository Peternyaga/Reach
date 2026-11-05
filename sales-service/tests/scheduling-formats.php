<?php
declare(strict_types=1);
define('FIELDWORK_HOSTED_LIBRARY',true);
require dirname(__DIR__).'/backend/bootstrap.php';require dirname(__DIR__).'/backend/hosted-router.php';
$wid=uid();$user=uid();$checks=0;
function scheduleCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo "PASS $label\n";}
function scheduleReject(callable $fn,int $code,string $label): void {try{$fn();throw new LogicException('Accepted '.$label);}catch(RuntimeException $e){scheduleCheck($e->getCode()===$code,$label);}}
try{
 query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$wid,'Disposable scheduling-format test']);query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$user,$wid,'Schedule QA',$user.'@example.test',password_hash(uid(),PASSWORD_DEFAULT),'owner']);
 $_SESSION=['workspace_id'=>$wid,'user_id'=>$user,'user_name'=>'Schedule QA','user_role'=>'owner'];$project=defaultProject($wid);$lead=createLead(leadValues(['name'=>'Format test lead']),uid(),$project)['id'];$identity=['workspace_id'=>$wid,'user_id'=>$user,'name'=>'Schedule QA','role'=>'owner'];
 $base=(new DateTimeImmutable('+3 days',new DateTimeZone('UTC')))->setTime(9,0,0);$saved=[];
 foreach(['Z','fraction','offset'] as $i=>$format){
  $at=$base->modify('+'.$i.' hours');$input=match($format){'Z'=>$at->format('Y-m-d\TH:i:s\Z'),'fraction'=>$at->format('Y-m-d\TH:i:s').'.000Z','offset'=>$at->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('Y-m-d\TH:i:sP')};
  $out=hostedCall('create_task',['lead_id'=>$lead,'project_id'=>$project,'owner_user_id'=>$user,'title'=>'Format '.$format,'due_at'=>$input],$identity);$saved[]=$out;
  scheduleCheck($out['due_at']===$at->format('Y-m-d H:i:s'),'create_task accepts '.$format.' and returns UTC');
  $audit=json_decode(query("SELECT body FROM activities WHERE workspace_id=? AND lead_id=? AND kind='task_created' ORDER BY created_at DESC,id DESC",[$wid,$lead])->fetchColumn(),true);
  scheduleCheck(query('SELECT COUNT(*) FROM tasks WHERE workspace_id=? AND id=?',[$wid,$out['id']])->fetchColumn()==1,'one saved task for '.$format);
 }
 scheduleCheck(query('SELECT owner_user_id FROM leads WHERE id=?',[$lead])->fetchColumn()===null,'personal task on unassigned lead does not assign salesperson');
 $task=$saved[0];$new=$base->modify('+5 hours');$move=hostedCall('reschedule_task',['id'=>$task['id'],'project_id'=>$project,'expected_due_at'=>$task['due_at'],'due_at'=>$new->format('Y-m-d\TH:i:s\Z')],$identity);
 scheduleCheck($move['due_at']===$new->format('Y-m-d H:i:s'),'reschedule_task accepts UTC without milliseconds');
 scheduleReject(fn()=>hostedCall('reschedule_task',['id'=>$task['id'],'project_id'=>$project,'expected_due_at'=>$task['due_at'],'due_at'=>$base->modify('+6 hours')->format('Y-m-d\TH:i:s\Z')],$identity),409,'stale reschedule still rejected');
 query('UPDATE leads SET owner_user_id=?,owner=? WHERE workspace_id=? AND id=?',[$user,'Schedule QA',$wid,$lead]);
 foreach(['Z','offset'] as $i=>$format){
  $source=uid();$work=uid();query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$source,$wid,$lead,'agent_request',json_encode(['instruction'=>'Save an internal follow-up','requested_by_user_id'=>$user]),'Schedule QA']);query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,kind) VALUES (?,?,?,?,?)',[$work,$wid,$lead,$source,'user_request']);
  $at=$base->modify('+'.(8+$i).' hours');$input=$format==='Z'?$at->format('Y-m-d\TH:i:s\Z'):$at->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('Y-m-d\TH:i:sP');
  $args=['id'=>$work,'project_id'=>$project,'title'=>'Queued '.$format,'summary'=>'Verified synthetic reminder','due_at'=>$input];$out=hostedCall('schedule_agent_followup',$args,$identity);
  scheduleCheck($out['due_at']===$at->format('Y-m-d H:i:s'),'atomic agent scheduling accepts '.$format);
  $row=query('SELECT * FROM agent_work_items WHERE id=?',[$work])->fetch();$result=json_decode($row['result'],true);scheduleCheck($row['status']==='done'&&$result['task_id']===$out['task_id'],'report contains saved reminder before marking done');
  scheduleReject(fn()=>hostedCall('schedule_agent_followup',$args,$identity),409,'resolved queue retry cannot duplicate '.$format.' reminder');
 }
 $before=query('SELECT COUNT(*) FROM tasks WHERE workspace_id=?',[$wid])->fetchColumn();
 foreach(['2030-02-30T08:00:00Z','2030-01-02 08:00:00','2030-01-02T08:00:00'] as $bad)scheduleReject(fn()=>hostedCall('create_task',['lead_id'=>$lead,'project_id'=>$project,'title'=>'Invalid date','due_at'=>$bad],$identity),422,'invalid or unzoned scheduling rejected');
 scheduleCheck(query('SELECT COUNT(*) FROM tasks WHERE workspace_id=?',[$wid])->fetchColumn()===$before,'validation failures leave no partial task');
 $definitions=array_column(hostedTools(),null,'name');foreach(['create_task','reschedule_task','schedule_agent_followup'] as $name)scheduleCheck($definitions[$name]['inputSchema']['properties']['due_at']['format']==='date-time'&&str_contains($definitions[$name]['inputSchema']['properties']['due_at']['description'],'Milliseconds are optional'),'schema documents timestamp examples: '.$name);
 echo "Scheduling format integration checks passed: $checks.\n";
}finally{if(db()->inTransaction())db()->rollBack();unset($GLOBALS['fieldwork_project']);foreach(['agent_work_items','tasks','activities','requests','opportunities','contacts','organisations','leads','projects','users'] as $table)query("DELETE FROM $table WHERE workspace_id=?",[$wid]);query('DELETE FROM workspaces WHERE id=?',[$wid]);}
