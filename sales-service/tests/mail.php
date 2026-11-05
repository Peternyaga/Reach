<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/backend/bootstrap.php';
require dirname(__DIR__).'/backend/projects.php';
require dirname(__DIR__).'/backend/mail.php';
require dirname(__DIR__).'/backend/hosted-writes.php';
function mailCheck(bool $condition,string $label): void {if(!$condition)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function mailReject(callable $action,int $code,string $label): void {
    try{$action();throw new LogicException('Expected rejection');}catch(RuntimeException $e){mailCheck($e->getCode()===$code,$label);}
}
$workspace=uid();$other=uid();$owner=uid();$teammate=uid();$otherUser=uid();$lead=uid();$otherLead=uid();$ownerEmail=uid().'@local.test';$password=uid();
function mailApi(string $path,?array $body=null): array {
    static $cookie='',$csrf='';
    $curl=curl_init('http://127.0.0.1:8788/api'.$path);$headers=['Accept: application/json'];
    if($cookie)$headers[]='Cookie: '.$cookie;
    if($body!==null){curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));$headers[]='Content-Type: application/json';$headers[]='X-CSRF-Token: '.$csrf;}
    curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>10]);
    $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));
    $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$length=curl_getinfo($curl,CURLINFO_HEADER_SIZE);curl_close($curl);
    if(preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi',substr($raw,0,$length),$match))$cookie=$match[1];
    $data=json_decode(substr($raw,$length),true);
    if($path==='/session')$csrf=$data['csrf']??'';
    return [$status,$data];
}
try{
    query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$workspace,'Mail isolation test']);
    query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$other,'Other mail test']);
    foreach([[$owner,$workspace,'owner'],[$teammate,$workspace,'salesperson'],[$otherUser,$other,'owner']] as [$user,$space,$role])query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$user,$space,'Mail Tester',$user===$owner?$ownerEmail:uid().'@local.test',password_hash($password,PASSWORD_DEFAULT),$role]);
    $project=defaultProject($workspace);$otherProject=defaultProject($other);
    query('INSERT INTO leads (id,workspace_id,project_id,name,organisation,email,notes,owner,owner_user_id) VALUES (?,?,?,?,?,?,?,?,?)',[$lead,$workspace,$project,'Recipient','Example Co','recipient@example.test','','Mail Tester',$owner]);
    query('INSERT INTO leads (id,workspace_id,project_id,name,organisation,email,notes,owner,owner_user_id) VALUES (?,?,?,?,?,?,?,?,?)',[$otherLead,$other,$otherProject,'Other','Other Co','other@example.test','','Mail Tester',$otherUser]);
    $_SESSION=['workspace_id'=>$workspace,'user_id'=>$owner,'user_name'=>'Mail Tester','user_role'=>'owner'];
    $setting=['smtp_host'=>'smtp.example.com','smtp_port'=>587,'security'=>'starttls','username'=>'sender@example.com','password'=>' leading and trailing ','from_email'=>'sender@example.com','from_name'=>'Mail Tester'];
    mailReject(fn()=>mailboxSave(array_replace($setting,['smtp_host'=>'127.0.0.1'])),422,'loopback host is rejected');
    mailReject(fn()=>mailboxSave(array_replace($setting,['security'=>'tls'])),422,'TLS mode must match the port');
    $public=mailboxSave($setting);mailboxSelect($project,$public['id']);$row=mailboxFor($owner);
    mailCheck($public['configured']===true&&!array_key_exists('password',$public)&&!str_contains(json_encode($public),'leading'),'mailbox response contains no secret');
    mailCheck(!str_contains($row['password_cipher'],$setting['password'])&&mailDecrypt($row['password_cipher'])===$setting['password'],'password is encrypted at rest and keeps significant whitespace');
    $_SESSION['user_id']=$teammate;mailCheck(mailboxFor($teammate)===null,'teammate has no access to owner mailbox');
    $_SESSION['user_id']=$owner;
    $calls=0;$transport=static function(array $config,string $to,string $subject,string $body)use(&$calls): void {$calls++;mailCheck($to==='recipient@example.test'&&$subject==='Introduction'&&$body==='Hello','transport receives scoped lead and content');};
    $key=uid();$sent=outboundSend($lead,$key,'Introduction','Hello',null,null,$transport);
    mailCheck($sent['status']==='sent'&&$calls===1,'confirmed send is recorded once');
    $retry=outboundSend($lead,$key,'Introduction','Hello',null,null,$transport);
    mailCheck(($retry['replayed']??false)&&$calls===1,'retry does not send twice');
    mailReject(fn()=>outboundSend($lead,$key,'Changed','Hello',null,null,$transport),409,'request key cannot send changed content');
    mailReject(fn()=>outboundSend($otherLead,uid(),'Wrong workspace','Hello',null,null,$transport),404,'other workspace lead cannot be emailed');
    $failedKey=uid();mailReject(fn()=>outboundSend($lead,$failedKey,'Follow-up','Hello',null,null,static function(): void {throw new RuntimeException('socket timeout');}),503,'uncertain delivery is reported');
    $uncertain=query('SELECT status FROM outbound_emails WHERE workspace_id=? AND user_id=? AND request_key=?',[$workspace,$owner,$failedKey])->fetchColumn();
    $again=outboundSend($lead,$failedKey,'Follow-up','Hello',null,null,$transport);
    mailCheck($uncertain==='needs_check'&&$again['status']==='needs_check'&&$calls===1,'uncertain delivery cannot silently retry');
    mailCheck(query("SELECT COUNT(*) FROM activities WHERE workspace_id=? AND lead_id=? AND kind='email_sent'",[$workspace,$lead])->fetchColumn()==1,'confirmed send has lead activity');
    mailCheck(str_contains(smtpHeader(str_repeat('é',100)),"\r\n "),'long UTF-8 headers fold safely');
    [, $session]=mailApi('/session');[$status]=mailApi('/login',['email'=>$ownerEmail,'password'=>$password]);mailCheck($status===200&&strlen($session['csrf']??'')>20,'owner can access email settings through authenticated API');
    [$status,$settings]=mailApi('/mailbox');mailCheck($status===200&&$settings['configured']===true&&!isset($settings['password_cipher'],$settings['password']),'mailbox API omits stored secret');
    $requestKey=uid();[$status,$queued]=mailApi('/leads/'.$lead.'/agent-requests',['instruction'=>'Email this lead about our website service.','request_key'=>$requestKey,'allow_email_send'=>true,'sender_id'=>$public['id'],'sender_revision'=>$public['revision'],'email_recipient'=>'recipient@example.test']);
    $source=query('SELECT a.body FROM agent_work_items w JOIN activities a ON a.id=w.source_activity_id WHERE w.id=?',[$queued['id']??''])->fetchColumn();$grant=$source?json_decode($source,true):[];
    mailCheck($status===200&&($grant['allow_email_send']??false)===true&&$grant['email_recipient']==='recipient@example.test'&&$grant['requested_by_user_id']===$owner,'agent email authorization captures user and exact recipient');
    $_SESSION=['workspace_id'=>$workspace,'user_id'=>$owner,'user_name'=>'Mail Tester','user_role'=>'owner'];
    $agentSent=outboundSend($lead,'agent-email-'.$queued['id'],'Introduction','Hello',$queued['id'],$owner,$transport);
    $report=hostedWrite('resolve_agent_work',['id'=>$queued['id'],'decision'=>'done','reason'=>'Sent the requested test email.']);
    mailCheck($agentSent['status']==='sent'&&in_array($agentSent['id'],array_column($report['actions'],'email_id'),true),'confirmed agent email appears as a structured action in its report');
    [$status]=mailApi('/leads/'.$lead.'/agent-requests',['instruction'=>'Different email','request_key'=>$requestKey,'allow_email_send'=>false]);mailCheck($status===409,'replayed agent task cannot change email permission');
    [$status]=mailApi('/leads/'.$lead.'/emails',['request_key'=>uid(),'subject'=>'','body'=>'Text','recipient'=>'recipient@example.test','version'=>1]);mailCheck($status===422,'direct email requires subject without contacting SMTP');
}finally{
    query('DELETE FROM project_senders WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM mail_accounts WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM outbound_emails WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM agent_work_items WHERE workspace_id=?',[$workspace]);
    foreach([[$workspace,$owner],[$workspace,$teammate],[$other,$otherUser]] as [$space,$user])query('DELETE FROM mailboxes WHERE workspace_id=? AND user_id=?',[$space,$user]);
    foreach([$workspace,$other] as $space){
        query('DELETE FROM outbound_emails WHERE workspace_id=?',[$space]);
        query('DELETE FROM activities WHERE workspace_id=?',[$space]);
        query('DELETE FROM opportunities WHERE workspace_id=?',[$space]);
        query('DELETE FROM contacts WHERE workspace_id=?',[$space]);
        query('DELETE FROM organisations WHERE workspace_id=?',[$space]);
        query('DELETE FROM leads WHERE workspace_id=?',[$space]);
        query('DELETE FROM projects WHERE workspace_id=?',[$space]);
        query('DELETE FROM users WHERE workspace_id=?',[$space]);
        query('DELETE FROM workspaces WHERE id=?',[$space]);
    }
}
