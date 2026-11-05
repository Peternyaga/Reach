<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../backend/bootstrap.php';require __DIR__.'/../backend/projects.php';require __DIR__.'/../backend/intake.php';require __DIR__.'/../backend/mail.php';
$file=__DIR__.'/../var/workspace-sync-qa.json';$fixture=json_decode(file_get_contents($file),true);$wid=$fixture['workspaces'][0];
if(query('SELECT name FROM workspaces WHERE id=?',[$wid])->fetchColumn()!=='Workspace sync test')exit(1);
$user=query('SELECT * FROM users WHERE workspace_id=? AND email=?',[$wid,$fixture['owner_email']])->fetch();
$_SESSION=['workspace_id'=>$wid,'user_id'=>$user['id'],'user_name'=>$user['name'],'user_role'=>'owner'];$a=$fixture['project'];$b=uid();query('INSERT INTO projects (id,workspace_id,name) VALUES (?,?,?)',[$b,$wid,'Project B']);
$setting=['smtp_host'=>'smtp.example.com','smtp_port'=>587,'security'=>'starttls','username'=>'qa@example.test','password'=>'fake password never used','from_email'=>'a@example.test','from_name'=>'QA Sender A'];
setProjectContext($a);$ma=mailboxSave($setting);mailboxSelect($a,$ma['id']);setProjectContext($b);$mb=mailboxSave(array_replace($setting,['from_email'=>'b@example.test','from_name'=>'QA Sender B']));mailboxSelect($b,$mb['id']);
$lead=createLead(leadValues(['name'=>'Project B contact','organisation'=>'B Business','email'=>'recipient@example.test','offering'=>'Hosting']),uid(),$b)['id'];
query('UPDATE leads SET email=? WHERE id=?',['a-recipient@example.test',$fixture['lead']]);
foreach([[$fixture['lead'],$a],[$lead,$b]] as [$id,$project]){setProjectContext($project);$activity=uid();query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$activity,$wid,$id,'agent_request',json_encode(['instruction'=>'Synthetic research only','requested_by_user_id'=>$user['id']]),'QA Owner']);query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,kind) VALUES (?,?,?,?,?)',[uid(),$wid,$id,$activity,'user_request']);}
$fixture['second_project']=$b;file_put_contents($file,json_encode($fixture,JSON_PRETTY_PRINT));echo json_encode(['owner_email'=>$fixture['owner_email'],'password'=>$fixture['password']]);
