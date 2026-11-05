<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/backend/bootstrap.php';require dirname(__DIR__).'/backend/projects.php';
$wid=uid();$user=uid();$lead=uid();$token=bin2hex(random_bytes(32));$file='reach-protocol-'.substr($wid,0,8).'.json';$root=dirname(__DIR__);$path=$root.'/var/'.$file;
function nodeProtocol(string $script,array $env,string $input=''): void {
 global $root;$process=proc_open(['node',$root.'/tests/'.$script],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,$root,$env);if(!is_resource($process))throw new RuntimeException('Protocol worker failed');fwrite($pipes[0],$input);fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Protocol suite failed: '.$script);
}
try{
 query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$wid,'Local protocol QA']);query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$user,$wid,'Protocol QA',uid().'@local.test',password_hash(uid(),PASSWORD_DEFAULT),'owner']);$project=defaultProject($wid);$other=uid();query('INSERT INTO projects (id,workspace_id,name) VALUES (?,?,?)',[$other,$wid,'Other protocol project']);query('INSERT INTO leads (id,workspace_id,project_id,name,notes) VALUES (?,?,?,?,?)',[$lead,$wid,$project,'Synthetic protocol business','Synthetic context']);
 query('INSERT INTO plugin_tokens (id,workspace_id,user_id,token_hash,auth_version,expires_at) VALUES (?,?,?,?,?,?)',[uid(),$wid,$user,hash('sha256',$token),1,gmdate('Y-m-d H:i:s',time()+3600)]);file_put_contents($path,json_encode(['token'=>$token]));
 $env=array_replace(getenv(),['FIELDWORK_API_BASE'=>'http://127.0.0.1:8793/api','FIELDWORK_PLUGIN_ACCESS_FILE'=>$file,'FIELDWORK_SERVICE_ROOT'=>$root]);nodeProtocol('plugin-protocol.mjs',$env);nodeProtocol('research-protocol.mjs',$env,json_encode(['project'=>$project,'other_project'=>$other,'lead'=>$lead]));
 echo "Local protocol suites passed using disposable scoped credentials.\n";
}finally{if(is_file($path))unlink($path);foreach(['plugin_tokens','activities','leads','projects','users'] as $table)query("DELETE FROM $table WHERE workspace_id=?",[$wid]);query('DELETE FROM workspaces WHERE id=?',[$wid]);}
