<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/backend/bootstrap.php';
require dirname(__DIR__).'/backend/projects.php';
require dirname(__DIR__).'/backend/lead-research.php';
$wid=uid();$user=uid();$lead=uid();$workers=[];$checks=0;
function raceCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo "PASS $label\n";}
function startWriter(array $payload): array {
 global $wid,$user,$lead,$project;
 $process=proc_open([PHP_BINARY,__DIR__.'/research-race-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('Worker start failed');
 fwrite($pipes[0],json_encode(['session'=>['workspace_id'=>$wid,'user_id'=>$user,'user_role'=>'owner','user_name'=>'Race QA'],'project'=>$project,'lead'=>$lead,'payload'=>$payload],JSON_THROW_ON_ERROR)."\n");fflush($pipes[0]);
 stream_set_timeout($pipes[1],15);
 if(trim((string)fgets($pipes[1]))!=='READY')throw new RuntimeException('Worker not ready: '.stream_get_contents($pipes[2]));
 return [$process,$pipes];
}
function finishWriters(array $workers): array {
 foreach($workers as [$process,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]);}
 $results=[];foreach($workers as [$process,$pipes]){$raw=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException('Worker exit '.$exit.': '.$error);$results[]=json_decode(trim($raw),true,32,JSON_THROW_ON_ERROR);}
 return $results;
}
try{
 query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$wid,'Research concurrency QA']);query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$user,$wid,'Race QA',uid().'@local.test',password_hash(uid(),PASSWORD_DEFAULT),'owner']);$project=defaultProject($wid);
 query('INSERT INTO leads (id,workspace_id,project_id,name,notes) VALUES (?,?,?,?,?)',[$lead,$wid,$project,'Synthetic concurrent lead','Do not alter legacy context']);
 $doc=['research_status'=>'draft','business_summary'=>'Writer A'];$one=['revision'=>0,'document'=>$doc,'request_key'=>uid()];$two=['revision'=>0,'document'=>array_replace($doc,['business_summary'=>'Writer B']),'request_key'=>uid()];
 $workers=[startWriter($one),startWriter($two)];$results=finishWriters($workers);$workers=[];$codes=array_column($results,'status');sort($codes);raceCheck($codes===[200,409],'simultaneous different writers yield one save and one409');
 raceCheck((int)query('SELECT revision FROM lead_research WHERE workspace_id=? AND lead_id=?',[$wid,$lead])->fetchColumn()===1,'competing writers advance exactly one revision');
 raceCheck((int)query('SELECT COUNT(*) FROM activities WHERE workspace_id=?',[$wid])->fetchColumn()===1,'losing writer leaves no partial audit');
 $same=['revision'=>1,'document'=>array_replace($doc,['business_summary'=>'Retry-safe simultaneous revision']),'request_key'=>uid()];$workers=[startWriter($same),startWriter($same)];$results=finishWriters($workers);$workers=[];
 raceCheck(array_column($results,'status')===[200,200],'simultaneous identical request retries both succeed');raceCheck(count(array_filter($results,fn($r)=>!empty($r['result']['replayed'])))===1,'one identical writer is recognized as replay');
 raceCheck((int)query('SELECT revision FROM lead_research WHERE workspace_id=? AND lead_id=?',[$wid,$lead])->fetchColumn()===2,'simultaneous retries advance one revision');raceCheck((int)query('SELECT COUNT(*) FROM activities WHERE workspace_id=?',[$wid])->fetchColumn()===2,'simultaneous retries create one audit event');
 echo "Research concurrency checks passed: $checks. Two independent PHP processes; no outreach.\n";
}finally{
 foreach($workers as [$process,$pipes]){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($process);proc_close($process);}
 if(db()->inTransaction())db()->rollBack();foreach(['activities','leads','projects','users'] as $table)query("DELETE FROM $table WHERE workspace_id=?",[$wid]);query('DELETE FROM workspaces WHERE id=?',[$wid]);
}
