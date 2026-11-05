<?php
declare(strict_types=1);

function mailKey(): string {
    $file=dirname(__DIR__).'/var/mail.key';
    if(!is_file($file)) fail('Email encryption is not configured. Run the installer or create fieldwork/var/mail.key.',503);
    $hex=trim((string)file_get_contents($file));
    if(!preg_match('/^[a-f0-9]{64}$/i',$hex)) fail('Email encryption key is invalid',503);
    return hex2bin($hex);
}
function mailEncrypt(string $secret): string {
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($secret,'aes-256-gcm',mailKey(),OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false)fail('Cannot protect email credentials',503);
    return base64_encode($iv.$tag.$cipher);
}
function mailDecrypt(string $saved): string {
    $bytes=base64_decode($saved,true);
    if($bytes===false||strlen($bytes)<29)fail('Email credentials are unreadable',503);
    $plain=openssl_decrypt(substr($bytes,28),'aes-256-gcm',mailKey(),OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16));
    if($plain===false)fail('Email credentials cannot be decrypted',503);
    return $plain;
}
function mailboxPublic(array $row): array {
    return ['configured'=>true,'id'=>$row['id'],'revision'=>(int)$row['revision'],'smtp_host'=>$row['smtp_host'],'smtp_port'=>(int)$row['smtp_port'],'security'=>$row['security'],'username'=>$row['username'],'from_email'=>$row['from_email'],'from_name'=>$row['from_name'],'updated_at'=>$row['updated_at']];
}
function mailboxAccount(string $user,string $id): ?array {
    return query('SELECT * FROM mail_accounts WHERE workspace_id=? AND user_id=? AND id=? AND active=1',[workspace(),$user,$id])->fetch()?:null;
}
function mailboxFor(string $user,?string $project=null): ?array {
    $project=$project??requestedProject();
    if(!$project)fail('Choose one project for email',422);
    return query('SELECT a.* FROM project_senders s JOIN mail_accounts a ON a.workspace_id=s.workspace_id AND a.user_id=s.user_id AND a.id=s.account_id AND a.active=1 WHERE s.workspace_id=? AND s.project_id=? AND s.user_id=?'.(db()->inTransaction()?' FOR UPDATE':''),[workspace(),$project,$user])->fetch()?:null;
}
function mailboxSelect(string $project,?string $id): array {
    resolveProject($project,workspace());
    if($id===null){query('DELETE FROM project_senders WHERE workspace_id=? AND project_id=? AND user_id=?',[workspace(),$project,$_SESSION['user_id']]);return ['configured'=>false];}
    $account=mailboxAccount($_SESSION['user_id'],$id);if(!$account)fail('Email account not found',404);
    query('INSERT INTO project_senders (workspace_id,project_id,user_id,account_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE account_id=VALUES(account_id)',[workspace(),$project,$_SESSION['user_id'],$id]);
    return mailboxPublic($account);
}
function mailAddress(string $value): string {
    if(strlen($value)>254||preg_match('/[\r\n\x00-\x1f\x7f]/',$value)||!filter_var($value,FILTER_VALIDATE_EMAIL))fail('Enter a valid email address',422);
    return $value;
}
function mailHost(string $host): string {
    $host=strtolower(rtrim($host,'.'));
    if(strlen($host)>253||!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])$/',$host)||!str_contains($host,'.')||filter_var($host,FILTER_VALIDATE_IP))fail('Use a public SMTP hostname',422);
    return $host;
}
function mailPublicIp(string $host): string {
    $records=dns_get_record($host,DNS_A)?:[];
    foreach($records as $record){
        $ip=$record['ip']??'';
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))fail('SMTP hostname must resolve only to public addresses',422);
    }
    if(!$records)fail('SMTP hostname has no public IPv4 address',422);
    return $records[0]['ip'];
}
function mailboxSave(array $data): array {
    foreach(array_keys($data) as $key)if(!in_array($key,['id','smtp_host','smtp_port','security','username','password','from_email','from_name'],true))fail('Unexpected email setting',422);
    $host=mailHost(textField($data,'smtp_host',253,true));
    $port=$data['smtp_port']??null;
    if(!is_int($port)||!in_array($port,[465,587],true))fail('Use SMTP port 465 or 587',422);
    $security=textField($data,'security',20,true);
    if(($port===465&&$security!=='tls')||($port===587&&$security!=='starttls'))fail('Match the TLS mode to the SMTP port',422);
    $username=textField($data,'username',254,true);
    $from=mailAddress(textField($data,'from_email',254,true));
    $fromName=textField($data,'from_name',150,true);
    if(preg_match('/[\r\n\x00-\x1f\x7f]/',$fromName))fail('Invalid sender name',422);
    $password=$data['password']??'';
    if(!is_string($password)||strlen($password)>1024||str_contains($password,"\0"))fail('Invalid SMTP password',422);
    $id=isset($data['id'])?textField($data,'id',32,true):uid();
    $prior=isset($data['id'])?mailboxAccount($_SESSION['user_id'],$id):null;
    if(isset($data['id'])&&!$prior)fail('Email account not found',404);
    if($password===''&&!$prior)fail('SMTP password is required',422);
    mailKey(); // fail before storing unusable configuration
    $cipher=$password!==''?mailEncrypt($password):$prior['password_cipher'];
    if($prior)query('UPDATE mail_accounts SET smtp_host=?,smtp_port=?,security=?,username=?,password_cipher=?,from_email=?,from_name=?,revision=revision+1 WHERE workspace_id=? AND user_id=? AND id=?',[$host,$port,$security,$username,$cipher,$from,$fromName,workspace(),$_SESSION['user_id'],$id]);
    else query('INSERT INTO mail_accounts (id,workspace_id,user_id,smtp_host,smtp_port,security,username,password_cipher,from_email,from_name) VALUES (?,?,?,?,?,?,?,?,?,?)',[$id,workspace(),$_SESSION['user_id'],$host,$port,$security,$username,$cipher,$from,$fromName]);
    return mailboxPublic(mailboxAccount($_SESSION['user_id'],$id));
}
function smtpReply($stream,array $expected): string {
    $lines=[];
    for($n=0;$n<30;$n++){
        $line=fgets($stream,1024);
        if($line===false||strlen($line)<4||!preg_match('/^[0-9]{3}[ -]/',$line))throw new RuntimeException('SMTP server response failed');
        $lines[]=$line;
        if($line[3]===' ')break;
    }
    $last=end($lines);
    if($last===false||$last[3]!==' ')throw new RuntimeException('SMTP response was incomplete');
    if(!in_array((int)substr($last,0,3),$expected,true))throw new RuntimeException('SMTP server rejected a command ('.substr($last,0,3).')');
    return implode('',$lines);
}
function smtpWrite($stream,string $value): void {
    $length=strlen($value);$offset=0;
    while($offset<$length){$written=fwrite($stream,substr($value,$offset));if($written===false||$written===0)throw new RuntimeException('SMTP write failed');$offset+=$written;}
}
function smtpCommand($stream,string $command,array $expected): string {
    smtpWrite($stream,$command."\r\n");
    return smtpReply($stream,$expected);
}
function smtpHeader(string $value): string {
    return implode("\r\n ",array_map(static fn(string $part): string=>'=?UTF-8?B?'.base64_encode($part).'?=',mb_str_split($value,10,'UTF-8')));
}
function smtpSend(array $mailbox,string $recipient,string $subject,string $body): void {
    $host=$mailbox['smtp_host'];$ip=mailPublicIp($host);$port=(int)$mailbox['smtp_port'];
    $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'SNI_enabled'=>true,'SNI_server_name'=>$host,'allow_self_signed'=>false]]);
    $uri=($port===465?'tls':'tcp').'://'.$ip.':'.$port;
    $stream=@stream_socket_client($uri,$errno,$errstr,10,STREAM_CLIENT_CONNECT,$context);
    if(!$stream)throw new RuntimeException('Cannot connect to the SMTP server');
    stream_set_timeout($stream,12);
    try{
        smtpReply($stream,[220]);
        $hello=smtpCommand($stream,'EHLO fieldwork.local',[250]);
        if($port===587){
            if(!preg_match('/^250[ -]STARTTLS\b/im',$hello))throw new RuntimeException('SMTP server does not offer STARTTLS');
            smtpCommand($stream,'STARTTLS',[220]);
            if(!stream_socket_enable_crypto($stream,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT))throw new RuntimeException('SMTP TLS negotiation failed');
            $hello=smtpCommand($stream,'EHLO fieldwork.local',[250]);
        }
        if(preg_match('/^250[ -]AUTH\b[^\r\n]*\bPLAIN\b/im',$hello))smtpCommand($stream,'AUTH PLAIN '.base64_encode("\0".$mailbox['username']."\0".mailDecrypt($mailbox['password_cipher'])),[235]);
        elseif(preg_match('/^250[ -]AUTH\b[^\r\n]*\bLOGIN\b/im',$hello)){
            smtpCommand($stream,'AUTH LOGIN',[334]);
            smtpCommand($stream,base64_encode($mailbox['username']),[334]);
            smtpCommand($stream,base64_encode(mailDecrypt($mailbox['password_cipher'])),[235]);
        }else throw new RuntimeException('SMTP server does not support AUTH PLAIN or LOGIN over TLS');
        smtpCommand($stream,'MAIL FROM:<'.$mailbox['from_email'].'>',[250]);
        smtpCommand($stream,'RCPT TO:<'.$recipient.'>',[250,251]);
        smtpCommand($stream,'DATA',[354]);
        $fromName=smtpHeader($mailbox['from_name']);
        $encodedSubject=smtpHeader($subject);
        $headers="From: $fromName <{$mailbox['from_email']}>\r\nTo: <$recipient>\r\nSubject: $encodedSubject\r\nDate: ".gmdate(DATE_RFC2822)."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        $payload=$headers."\r\n".chunk_split(base64_encode(str_replace(["\r\n","\r"],"\n",$body)),76,"\r\n");
        smtpWrite($stream,$payload."\r\n.\r\n");
        smtpReply($stream,[250]);
        @smtpCommand($stream,'QUIT',[221]);
    }finally{fclose($stream);}
}
function outboundSend(string $leadId,string $requestKey,string $subject,string $body,?string $workId=null,?string $mailUser=null,?callable $transport=null,?array $expectedSender=null): array {
    try{
        beginWorkTransaction();
        $result=outboundSendClaim($leadId,$requestKey,$subject,$body,$workId,$mailUser,$transport,$expectedSender);
        if(db()->inTransaction())db()->commit();
        return $result;
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function outboundSendClaim(string $leadId,string $requestKey,string $subject,string $body,?string $workId=null,?string $mailUser=null,?callable $transport=null,?array $expectedSender=null): array {
    $user=$mailUser??$_SESSION['user_id'];
    $lead=query('SELECT * FROM leads WHERE workspace_id=? AND id=? FOR UPDATE',[workspace(),$leadId])->fetch();
    if(!$lead||!canAccessLead($lead))fail('Lead not found',404);
    if($lead['stage']==='Lost')fail('Reopen this lead before sending email',409);
    if(!userHasProject($user,$lead['project_id']))fail('The sender no longer has access to this project.',403);
    $mailbox=mailboxFor($user,$lead['project_id']);if(!$mailbox)fail('Choose a sender for this project in Connected apps',409);
    if($expectedSender!==null&&($expectedSender['id']!==$mailbox['id']||(int)$expectedSender['revision']!==(int)$mailbox['revision']))fail('Project sender changed. Review and authorize again.',409);
    $recipient=mailAddress($lead['email']);
    if($subject===''||mb_strlen($subject)>250||preg_match('/[\r\n\x00-\x1f\x7f]/',$subject))fail('Check email subject',422);
    if($body===''||mb_strlen($body)>20000)fail('Check email body',422);
    if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$requestKey))fail('Invalid request key',422);
    $existing=query('SELECT * FROM outbound_emails WHERE workspace_id=? AND user_id=? AND request_key=?',[workspace(),$user,$requestKey])->fetch();
    if($existing){
        if($existing['lead_id']!==$leadId||$existing['recipient']!==$recipient||$existing['subject']!==$subject||$existing['body']!==$body||$existing['work_id']!==$workId||($existing['account_id']!==null&&($existing['account_id']!==$mailbox['id']||(int)$existing['sender_revision']!==(int)$mailbox['revision'])))fail('Request key already used for another email',409);
        return ['id'=>$existing['id'],'status'=>$existing['status'],'replayed'=>true];
    }
    $id=uid();
    try{query("INSERT INTO outbound_emails (id,workspace_id,lead_id,user_id,work_id,request_key,recipient,subject,body,project_id,account_id,sender_email,sender_revision,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'sending')",[$id,workspace(),$leadId,$user,$workId,$requestKey,$recipient,$subject,$body,$lead['project_id'],$mailbox['id'],$mailbox['from_email'],$mailbox['revision']]);}
    catch(PDOException $e){if($e->getCode()==='23000')fail('This email request is already being sent; refresh its status before retrying',409);throw $e;}
    // Persist the claim BEFORE external I/O. A crash leaves sending, never a new send.
    // Moves check this claim; sender configuration is the authorized snapshot at initiation.
    db()->commit();
    try{
        ($transport??'smtpSend')($mailbox,$recipient,$subject,$body);
        query("UPDATE outbound_emails SET status='sent',completed_at=UTC_TIMESTAMP() WHERE id=?",[$id]);
        audit($leadId,'email_sent',['email_id'=>$id,'to'=>$recipient,'subject'=>$subject,'work_id'=>$workId,'project_id'=>$lead['project_id'],'account_id'=>$mailbox['id'],'sender_email'=>$mailbox['from_email']]);
        return ['id'=>$id,'status'=>'sent'];
    }catch(Throwable $error){
        query("UPDATE outbound_emails SET status='needs_check',detail=?,completed_at=UTC_TIMESTAMP() WHERE id=?",[mb_substr($error->getMessage(),0,500),$id]);
        throw new RuntimeException('Delivery was not confirmed. Check the email status and Sent folder before trying again.',503);
    }
}
