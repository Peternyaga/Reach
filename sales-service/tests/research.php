<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/backend/bootstrap.php';
require dirname(__DIR__).'/backend/projects.php';
require dirname(__DIR__).'/backend/migrations.php';
require dirname(__DIR__).'/backend/lead-research.php';
require dirname(__DIR__).'/backend/workspace-sync.php';
define('FIELDWORK_HOSTED_LIBRARY',true);require dirname(__DIR__).'/backend/hosted-router.php';
$checks=0;$wid=uid();$foreign=uid();$owner=uid();$seller=uid();$collector=uid();$lead=uid();$otherLead=uid();$cookie='';$csrf='';$email=uid().'@local.test';$password=uid();
function ok(bool $value,string $label): void {global $checks;$checks++;if(!$value)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
function rejects(callable $fn,int $code,string $label): void {try{$fn();}catch(RuntimeException $e){ok($e->getCode()===$code,$label.' ('.$e->getCode().')');return;}throw new RuntimeException('FAIL '.$label);}
function httpResearch(string $path,?array $body=null,?string $project=null): array {
 global $cookie,$csrf;$h=curl_init('http://127.0.0.1:8793/api'.$path);$headers=['Accept: application/json'];if($cookie)$headers[]='Cookie: '.$cookie;if($project)$headers[]='X-Fieldwork-Project: '.$project;
 if($body!==null){curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body));$headers[]='Content-Type: application/json';$headers[]='X-CSRF-Token: '.$csrf;}
 curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>$headers]);$raw=curl_exec($h);if($raw===false)throw new RuntimeException(curl_error($h));$length=curl_getinfo($h,CURLINFO_HEADER_SIZE);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);if(preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi',substr($raw,0,$length),$m))$cookie=$m[1];$data=json_decode(substr($raw,$length),true);if($path==='/session')$csrf=$data['csrf'];return [$status,$data];
}
try{
 applySchema();applySchema();ok(query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='activities' AND COLUMN_NAME='body'")->fetchColumn()==='mediumtext','repeatable audit capacity migration');
 foreach([$wid,$foreign] as $w)query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$w,'Synthetic research QA']);
 foreach([[$owner,'owner'],[$seller,'salesperson'],[$collector,'collector']] as [$u,$role])query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$u,$wid,'Research QA',$u===$owner?$email:$u.'@local.test',password_hash($password,PASSWORD_DEFAULT),$role]);
 $a=defaultProject($wid);$b=uid();query('INSERT INTO projects (id,workspace_id,name) VALUES (?,?,?)',[$b,$wid,'Other project']);$foreignProject=defaultProject($foreign);
 foreach([[$lead,$a],[$otherLead,$b]] as [$id,$project])query('INSERT INTO leads (id,workspace_id,project_id,name,notes,collector_user_id) VALUES (?,?,?,?,?,?)',[$id,$wid,$project,'Synthetic business','Original context is never rewritten',$collector]);
 $_SESSION=['workspace_id'=>$wid,'user_id'=>$owner,'user_role'=>'owner','user_name'=>'Research QA'];setProjectContext($a);
 $doc=['business_summary'=>'A synthetic service business.','contact_rationale'=>'Test a plausible enquiry-flow hypothesis; no purchase intent.','missing_contact_reason'=>'Business route not confirmed.','uncertainties'=>['Authority, budget and timing unknown.'],'next_action'=>'Identify the correct business contact.','research_status'=>'ready_for_review','evidence'=>[['id'=>'source-1','claim'=>'The business describes a service.','source_url'=>'https://example.test/services','source_title'=>'Synthetic source','source_type'=>'public_source','observed_at'=>'2026-10-14','confidence'=>'medium','status'=>'observed']]];
 ok(getLeadResearch($lead)['revision']===0,'absent research is revision zero');
 foreach(['2026-02-30','2026-99-99','no date','0000-00-00'] as $bad)rejects(fn()=>researchDocument(array_replace($doc,['researched_at'=>$bad])),422,'invalid date rejected '.$bad);
 $bad=$doc;$bad['evidence'][0]['observed_at']='2026-02-30';rejects(fn()=>researchDocument($bad),422,'invalid evidence date');
 foreach(['javascript:alert(1)','file:///secret','https://user:pass@example.test','https://example.test/@ok'] as $i=>$url){$bad=$doc;$bad['evidence'][0]['source_url']=$url;if($i===3)ok(researchDocument($bad)['evidence'][0]['source_url']===$url,'safe path containing @ accepted');else rejects(fn()=>researchDocument($bad),422,'unsafe source rejected');}
 foreach([['workspace_id'=>$foreign],['reviewed_by'=>$owner],['research_status'=>'verified'],['business_summary'=>str_repeat('x',3001)],['uncertainties'=>array_fill(0,21,'Unknown')],['evidence'=>'not array']] as $bad)rejects(fn()=>researchDocument(array_replace($doc,$bad)),422,'bounded contract rejects malformed/forged fields');
 rejects(fn()=>updateLeadResearch($lead,['revision'=>0,'document'=>['research_status'=>'on_hold'],'request_key'=>uid()]),422,'hold requires reason');
 rejects(fn()=>updateLeadResearch($lead,['revision'=>0,'document'=>['research_status'=>'ready_for_review'],'request_key'=>uid()]),422,'ready requires supported context');
 $payload=['revision'=>0,'document'=>$doc,'request_key'=>uid()];$before=workspaceSyncStamp();$saved=updateLeadResearch($lead,$payload,true);ok($saved['revision']===1,'agent saves ready for human review');ok(workspaceSyncStamp()!==$before,'research audit changes sync fingerprint');
 ok(updateLeadResearch($lead,$payload,true)['replayed']===true,'retry deduplicated');ok(query("SELECT COUNT(*) FROM activities WHERE workspace_id=? AND lead_id=? AND kind='research_updated'",[$wid,$lead])->fetchColumn()==1,'retry creates no duplicate audit');
 rejects(fn()=>updateLeadResearch($lead,array_replace($payload,['document'=>array_replace($doc,['business_summary'=>'Changed'])]),true),409,'changed retry payload rejected');
 rejects(fn()=>updateLeadResearch($lead,array_replace($payload,['request_key'=>uid()]),true),409,'stale parallel revision rejected');
 rejects(fn()=>updateLeadResearch($lead,['revision'=>1,'document'=>array_replace($doc,['research_status'=>'reviewed']),'request_key'=>uid()],true),403,'agent cannot certify review');
 $s=updateLeadResearch($lead,['revision'=>1,'document'=>array_replace($doc,['research_status'=>'reviewed']),'request_key'=>uid()]);$read=getLeadResearch($lead);ok($read['reviewed_by']===$owner&&$read['reviewed_at']!==null,'human reviewer assigned by server');
 $large=researchDocument(array_replace($doc,['research_status'=>'draft']));$large['evidence']=[];for($i=0;$i<20;$i++)$large['evidence'][]=['id'=>'large-'.$i,'claim'=>str_repeat('x',1900),'source_url'=>'https://example.test','source_title'=>'Synthetic evidence','source_type'=>'public_source','observed_at'=>'2026-10-14','confidence'=>'low','status'=>'unconfirmed'];
 updateLeadResearch($lead,['revision'=>2,'document'=>$large,'request_key'=>uid()],true);$large['business_summary']='Second large revision';updateLeadResearch($lead,['revision'=>3,'document'=>$large,'request_key'=>uid()],true);
 $body=query("SELECT body FROM activities WHERE workspace_id=? AND lead_id=? AND kind='research_updated' ORDER BY created_at DESC,id DESC",[$wid,$lead])->fetchAll();$largest='';foreach($body as $row)if(strlen($row['body'])>strlen($largest))$largest=$row['body'];ok(strlen($largest)>65535&&is_array(json_decode($largest,true)['previous_document']),'large old/new audit documents retained without truncation');ok(getLeadResearch($lead)['reviewed_by']===null,'agent revision invalidates prior human-review status');
 ok(query('SELECT notes FROM leads WHERE id=?',[$lead])->fetchColumn()==='Original context is never rewritten','legacy notes unchanged');
 $list=withResearchSummaries([query('SELECT * FROM leads WHERE id=?',[$lead])->fetch()]);ok(isset($list[0]['research']['contact_rationale'])&&!isset($list[0]['research']['evidence']),'list includes bounded research summary without evidence');
 $identity=['workspace_id'=>$wid,'user_id'=>$owner,'role'=>'owner','name'=>'Research QA'];
 rejects(fn()=>hostedCall('get_lead_research',['project_id'=>$b,'id'=>$lead],$identity),404,'MCP wrong-project research hidden');
 rejects(fn()=>hostedCall('get_lead_research',['project_id'=>$foreignProject,'id'=>$lead],$identity),404,'MCP foreign workspace hidden');
 $sellerIdentity=array_replace($identity,['user_id'=>$seller,'role'=>'salesperson']);rejects(fn()=>hostedCall('get_lead_research',['project_id'=>$a,'id'=>$lead],$sellerIdentity),404,'unassigned salesperson cannot read research');
 $collectorIdentity=array_replace($identity,['user_id'=>$collector,'role'=>'collector']);rejects(fn()=>hostedCall('get_lead_research',['project_id'=>$a,'id'=>$lead],$collectorIdentity),403,'collector research denied');rejects(fn()=>hostedCall('update_lead_research',['project_id'=>$a,'id'=>$lead,'revision'=>4,'document'=>$doc,'request_key'=>uid()],$collectorIdentity),403,'collector research mutation denied');
 $collectorRows=hostedCall('list_leads',['project_id'=>$a],$collectorIdentity);ok(!isset($collectorRows[0]['research'])&&!isset($collectorRows[0]['notes']),'collector list has no sales research or notes');
 httpResearch('/session');[$status]=httpResearch('/login',['email'=>$email,'password'=>$password]);ok($status===200,'API sign-in');
 [$status,$preview]=httpResearch('/leads/'.$lead.'/research-preview',['document'=>$doc],$a);ok($status===200&&$preview['missing_fields']===[],'JSON preview shares backend readiness validator');
 [$status]=httpResearch('/leads/'.$lead.'/research',null,$b);ok($status===404,'API wrong-project research read');
 [$status]=httpResearch('/leads/'.$lead.'/research',['revision'=>4,'document'=>$doc,'request_key'=>uid()],$b);ok($status===404,'API wrong-project research write');
 [$status]=httpResearch('/leads/'.$lead.'/research',['revision'=>4,'document'=>array_replace($doc,['researched_at'=>'2026-99-99']),'request_key'=>uid()],$a);ok($status===422,'API invalid date returns 422');
 $hold=array_replace($doc,['research_status'=>'on_hold','hold_reason'=>'Identity conflict']);[$status]=httpResearch('/leads/'.$lead.'/research',['revision'=>4,'document'=>$hold,'request_key'=>uid()],$a);ok($status===200,'API hold saved');
 [$status]=httpResearch('/leads/'.$lead.'/assign',['version'=>1,'user_id'=>$owner,'first_contact_at'=>'2030-01-01T10:00:00.000Z'],$a);ok($status===422,'hold blocks first-contact scheduling');
 [$status]=httpResearch('/leads/'.$lead.'/assign',['version'=>1,'user_id'=>$owner],$a);ok($status===200,'hold permits research owner assignment without contact task');ok(query('SELECT COUNT(*) FROM tasks WHERE workspace_id=?',[$wid])->fetchColumn()==0,'no contact task silently created');
 [$status,$detail]=httpResearch('/leads/'.$lead,null,$a);ok($status===200&&$detail['research']['document']['hold_reason']==='Identity conflict','detail includes durable research');
 [$status,$rows]=httpResearch('/leads',null,$a);ok($status===200&&$rows[0]['research']['research_status']==='on_hold','API list includes visible hold');
 [$status]=httpResearch('/leads/'.$lead.'/research',['revision'=>4,'document'=>$doc,'request_key'=>uid()],$a);ok($status===409,'API stale writer receives 409');
 [$status]=httpResearch('/leads/'.$lead.'/research-agent',['revision'=>5,'document'=>array_replace($doc,['research_status'=>'reviewed']),'request_key'=>uid()],$a);ok($status===403,'local agent endpoint cannot mark human reviewed');
 $capture=['name'=>'Synthetic intake with research','request_key'=>uid(),'project_id'=>$a,'research'=>$doc];[$status,$created]=httpResearch('/leads',$capture,$a);ok($status===200,'structured intake saves atomically');
 [$status,$intake]=httpResearch('/leads/'.$created['id'].'/research',null,$a);ok($status===200&&$intake['revision']===1&&$intake['document']['business_summary']===$doc['business_summary'],'intake research durable');
 [$status,$retry]=httpResearch('/leads',$capture,$a);ok($status===200&&$retry['replayed']&&$retry['id']===$created['id'],'structured intake retry reuses one lead');
 [$status]=httpResearch('/leads',array_replace($capture,['request_key'=>uid(),'name'=>'Incomplete research intake','research'=>['research_status'=>'ready_for_review']]),$a);ok($status===422,'incomplete ready intake rejected');ok(query('SELECT COUNT(*) FROM leads WHERE workspace_id=? AND name=?',[$wid,'Incomplete research intake'])->fetchColumn()==0,'invalid ready intake rolls back whole lead graph');
 echo "Research checks passed: $checks. Synthetic local data only; no outreach.\n";
}finally{
 if(db()->inTransaction())db()->rollBack();unset($GLOBALS['fieldwork_project']);
 foreach([$wid,$foreign] as $w){foreach(['activities','assignment_events','tasks','opportunities','contacts','organisations','requests','leads','projects','users'] as $table)query("DELETE FROM $table WHERE workspace_id=?",[$w]);query('DELETE FROM workspaces WHERE id=?',[$w]);}
}
