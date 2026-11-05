<?php
declare(strict_types=1);
define('FIELDWORK_HOSTED_LIBRARY',true);
require dirname(__DIR__).'/backend/bootstrap.php';require dirname(__DIR__).'/backend/migrations.php';require dirname(__DIR__).'/backend/hosted-router.php';
$wid=uid();$user=uid();$other=uid();$checks=0;
function cc(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo "PASS $label\n";}
function cr(callable $fn,int $status,string $label): void {try{$fn();}catch(RuntimeException $e){cc($e->getCode()===$status,$label);return;}throw new RuntimeException('FAIL '.$label);}
function cl(string $id): array {return query('SELECT * FROM leads WHERE workspace_id=? AND id=?',[workspace(),$id])->fetch();}
function ce(string $id,array $ops): array {return editLeadContacts($id,['version'=>(int)cl($id)['version'],'operations'=>$ops,'provenance'=>'Disposable synthetic contact test']);}
try{
 applySchema();query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$wid,'Disposable contact test']);
 foreach([$user,$other] as $u)query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$u,$wid,'Contact tester',$u.'@example.test',password_hash(uid(),PASSWORD_DEFAULT),'owner']);
 $_SESSION=['workspace_id'=>$wid,'user_id'=>$user,'user_name'=>'Contact tester','user_role'=>'owner'];$project=defaultProject($wid);
 $values=leadValues(['name'=>'Synthetic business','organisation'=>'Synthetic business','offering'=>'Website']);$contacts=[['name'=>'Amina','phone'=>'+254700000001','role'=>'Owner','ownership'=>'direct','is_primary'=>true],['department'=>'Front desk','phone'=>'+254700000002','role'=>'Reception','ownership'=>'shared'],['email'=>'sales@example.test','role'=>'Sales','ownership'=>'unknown']];$key=uid();
 $identity=['workspace_id'=>$wid,'user_id'=>$user,'name'=>'Contact tester','role'=>'owner'];
 $created=hostedCall('create_lead',['name'=>$values['name'],'organisation'=>$values['organisation'],'offering'=>$values['offering'],'request_key'=>$key,'project_id'=>$project,'contacts'=>$contacts],$identity);$id=$created['id'];$rows=leadContactRows($wid,$id);
 cc(count($rows)===3,'create retains all three labelled contacts');cc($rows[0]['is_primary']&&cl($id)['phone']==='+254700000001','primary mirrors legacy phone');
 $by=array_column($rows,null,'role');$reception=$by['Reception']['id'];$sales=$by['Sales']['id'];$owner=$by['Owner']['id'];
 cc($by['Reception']['name']===''&&$by['Reception']['department']==='Front desk'&&$by['Reception']['ownership']==='shared','shared department does not invent a person');
 cc($by['Sales']['name']===''&&$by['Sales']['ownership']==='unknown','unknown ownership and name retained');cc(cl($id)['owner_user_id']===null&&cl($id)['name']==='Synthetic business','route owner never becomes lead assignee or title');
 cc(createLead($values,$key,$project,null,false,$contacts)['replayed']===true,'multi-contact create retry is idempotent');
 cr(fn()=>createLead($values,$key,$project,null,false,array_reverse($contacts)),409,'changed retry cannot replace contacts');
 $identity=['workspace_id'=>$wid,'user_id'=>$user,'name'=>'Contact tester','role'=>'owner'];$detail=hostedCall('get_lead',['id'=>$id,'project_id'=>$project],$identity);cc(count($detail['contacts'])===3,'hosted get_lead exposes every contact');
 cc(isset(array_column(hostedTools(),null,'name')['update_lead_contacts']),'hosted discovery advertises contact operations');
 $version=(int)cl($id)['version'];hostedCall('update_lead_contacts',['project_id'=>$project,'id'=>$id,'version'=>$version,'operations'=>[['action'=>'update','contact_id'=>$reception,'contact'=>['role'=>'Reception desk']]],'provenance'=>'Synthetic hosted edit'],$identity);
 cc(count(leadContactRows($wid,$id))===3&&cl($id)['phone']==='+254700000001','individual edit retains other routes and primary');
 cr(fn()=>editLeadContacts($id,['version'=>$version,'operations'=>[['action'=>'remove','contact_id'=>$owner]],'provenance'=>'Stale test']),409,'stale edit cannot remove another contact');
 ce($id,[['action'=>'set_primary','contact_id'=>$sales]]);cc(cl($id)['email']==='sales@example.test'&&cl($id)['phone']==='','email-only primary does not borrow another person phone');
 editLeadProfile($id,['version'=>(int)cl($id)['version'],'changes'=>['email'=>'new-sales@example.test','name'=>'Renamed business'],'provenance'=>'Legacy single-contact edit']);$rows=leadContactRows($wid,$id);$by=array_column($rows,null,'id');
 cc($by[$owner]['phone']==='+254700000001'&&$by[$reception]['phone']==='+254700000002'&&$by[$sales]['email']==='new-sales@example.test','legacy edit changes primary only');cc($by[$sales]['name']==='','lead title edit never invents contact name');
 $before=leadContactRows($wid,$id);$v=(int)cl($id)['version'];cr(fn()=>ce($id,[['action'=>'update','contact_id'=>$reception,'contact'=>['role'=>'Should roll back']],['action'=>'add','contact'=>['role'=>'No route']]]),422,'invalid operation rolls back entire batch');cc(leadContactRows($wid,$id)===$before&&(int)cl($id)['version']===$v,'failed batch preserves data and version');
 cr(fn()=>ce($id,[['action'=>'add','contact'=>['phone'=>'1234567','ownership'=>'assigned']]]),422,'invalid ownership rejected');
 cr(fn()=>ce($id,[['action'=>'update','contact_id'=>$owner,'contact'=>['owner_user_id'=>$user]]]),422,'contact edit cannot change assignment');
 ce($id,[['action'=>'remove','contact_id'=>$sales],['action'=>'set_primary','contact_id'=>$owner]]);cc(count(leadContactRows($wid,$id))===2&&cl($id)['phone']==='+254700000001','remove primary and explicitly choose replacement atomically');
 ce($id,[['action'=>'remove','contact_id'=>$owner]]);cc(cl($id)['phone']===''&&cl($id)['email']===''&&count(leadContactRows($wid,$id))===1,'primary removal clears mirrors without promoting another owner');
 ce($id,[['action'=>'remove','contact_id'=>$reception]]);materialiseLeadRecords(cl($id));cc(leadContactRows($wid,$id)===[],'deleting last contact cannot resurrect legacy values');
 $legacy=createLead(leadValues(['name'=>'Legacy lead','phone'=>'+254700000003','email'=>'legacy@example.test']),uid(),$project)['id'];$old=leadContactRows($wid,$legacy);cc(count($old)===1&&$old[0]['ownership']==='unknown'&&$old[0]['is_primary'],'single-contact create retained with unknown ownership');
 editLeadProfile($legacy,['version'=>1,'changes'=>['phone'=>'+254700000004'],'provenance'=>'Old client']);cc(leadContactRows($wid,$legacy)[0]['phone']==='+254700000004','old profile workflow updates its one route');
 $raw=uid();query('INSERT INTO leads (id,workspace_id,project_id,name,phone,notes) VALUES (?,?,?,?,?,?)',[$raw,$wid,$project,'Old row','+254700000005','']);applySchema();applySchema();cc(count(leadContactRows($wid,$raw))===1&&leadContactRows($wid,$raw)[0]['name']==='','repeat migration preserves legacy route without invented name');
 ce($legacy,[['action'=>'add','contact'=>['email'=>'extra@example.test','role'=>'Sales']]]);$extra=array_values(array_filter(leadContactRows($wid,$legacy),fn($c)=>$c['role']==='Sales'))[0];
 cr(fn()=>ce($raw,[['action'=>'remove','contact_id'=>$extra['id']]]),404,'cannot use a contact ID from another lead');
 query("UPDATE users SET role='salesperson' WHERE id=?",[$other]);$_SESSION['user_id']=$other;$_SESSION['user_role']='salesperson';cr(fn()=>ce($legacy,[['action'=>'remove','contact_id'=>$extra['id']]]),404,'unassigned salesperson cannot edit contacts');
 $_SESSION['user_id']=$user;$_SESSION['user_role']='collector';cr(fn()=>ce($legacy,[['action'=>'remove','contact_id'=>$extra['id']]]),403,'collector cannot change sales contact routes');
 $_SESSION['user_role']='owner';$source=uid();$work=uid();query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$source,$wid,$legacy,'note',json_encode(['text'=>'User supplied Sales label correction','requested_by_user_id'=>$user]),'Contact tester']);query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,kind) VALUES (?,?,?,?,?)',[$work,$wid,$legacy,$source,'review_note']);
 $args=['id'=>$legacy,'project_id'=>$project,'version'=>(int)cl($legacy)['version'],'source_activity_id'=>$source,'provenance'=>'User supplied label','operations'=>[['action'=>'update','contact_id'=>$extra['id'],'contact'=>['role'=>'Sales desk']]]];hostedCall('update_lead_contacts',$args,$identity);$report=hostedWrite('resolve_agent_work',['id'=>$work,'decision'=>'done','reason'=>'Saved supplied label']);cc(($report['actions'][0]['type']??'')==='update_lead_contacts','queued agent report retains sourced contact changes');
 cr(fn()=>hostedCall('update_lead_contacts',array_replace($args,['version'=>(int)cl($legacy)['version']]),$identity),403,'resolved request cannot authorize another contact change');
 $_SESSION['user_role']='owner';$restricted=uid();query('INSERT INTO projects (id,workspace_id,name) VALUES (?,?,?)',[$restricted,$wid,'Restricted']);query("UPDATE users SET role='manager',access_scope='selected' WHERE id=?",[$user]);query('INSERT INTO project_memberships VALUES (?,?,?)',[$wid,$user,$restricted]);cr(fn()=>ce($legacy,[['action'=>'remove','contact_id'=>$extra['id']]]),404,'project restriction applies to contacts');
 echo "Contact checks passed: $checks.\n";
}finally{if(db()->inTransaction())db()->rollBack();unset($GLOBALS['fieldwork_project']);foreach(['agent_work_items','activities','requests','opportunities','contacts','organisations','leads','project_memberships','projects','users'] as $table)query("DELETE FROM $table WHERE workspace_id=?",[$wid]);query('DELETE FROM workspaces WHERE id=?',[$wid]);}
