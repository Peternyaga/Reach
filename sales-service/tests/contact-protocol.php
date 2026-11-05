<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';require dirname(__DIR__).'/backend/projects.php';
$wid=uid();$user=uid();$token=bin2hex(random_bytes(32));$file='contacts-protocol-'.substr($wid,0,8).'.json';$root=dirname(__DIR__);$path=$root.'/var/'.$file;
try{
 query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$wid,'Disposable contact protocol']);query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$user,$wid,'Contacts QA',uid().'@example.test',password_hash(uid(),PASSWORD_DEFAULT),'owner']);$project=defaultProject($wid);
 query('INSERT INTO plugin_tokens (id,workspace_id,user_id,token_hash,auth_version,expires_at) VALUES (?,?,?,?,?,?)',[uid(),$wid,$user,hash('sha256',$token),1,gmdate('Y-m-d H:i:s',time()+3600)]);file_put_contents($path,json_encode(['token'=>$token]));
 $env=array_replace(getenv(),['FIELDWORK_API_BASE'=>'http://127.0.0.1:8793/api','FIELDWORK_PLUGIN_ACCESS_FILE'=>$file,'FIELDWORK_SERVICE_ROOT'=>$root]);
 $process=proc_open(['node',$root.'/tests/contact-protocol.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,$root,$env);fwrite($pipes[0],json_encode(['project'=>$project]));fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Contact protocol failed');
}finally{if(is_file($path))unlink($path);foreach(['plugin_tokens','activities','requests','opportunities','contacts','organisations','leads','projects','users'] as $table)query("DELETE FROM $table WHERE workspace_id=?",[$wid]);query('DELETE FROM workspaces WHERE id=?',[$wid]);}
