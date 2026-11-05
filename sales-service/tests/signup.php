<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('FIELDWORK_OAUTH_BASE_URL')!=='http://127.0.0.1:8792')exit("Use isolated signup server on port 8792.\n");
require dirname(__DIR__).'/backend/bootstrap.php';
ob_start(); // The expiry fixture opens its own test-only PHP session below.
$cookies=[];$spaces=[];$client=null;$marker=bin2hex(random_bytes(6));$password='  Signup-'.$marker.'  ';$email='signup-'.$marker.'@local.test';
$savedBuckets=[];
foreach(['workspace-signup-ip|127.0.0.1','workspace-signup-global|all','account-login-ip|127.0.0.1'] as $identity){$b=hash('sha256',$identity);$savedBuckets[$b]=query('SELECT * FROM oauth_rate_limits WHERE bucket=?',[$b])->fetch();}
function checkSignup(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function signupRequest(string $session,string $path,?array $data=null,bool $json=true,?string $csrf=null,array $extra=[]): array {
    global $cookies;$h=curl_init('http://127.0.0.1:8792'.$path);$headers=$extra;
    if(isset($cookies[$session]))$headers[]='Cookie: '.$cookies[$session];
    if($data!==null){$headers[]='Content-Type: '.($json?'application/json':'application/x-www-form-urlencoded');if($csrf!==null)$headers[]='X-CSRF-Token: '.$csrf;curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,$json?json_encode($data):http_build_query($data));}
    curl_setopt_array($h,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>10]);$raw=curl_exec($h);if($raw===false)throw new RuntimeException(curl_error($h));$length=curl_getinfo($h,CURLINFO_HEADER_SIZE);$head=substr($raw,0,$length);$body=substr($raw,$length);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
    if(preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi',$head,$m))$cookies[$session]=$m[1];return [$status,json_decode($body,true),$body,$head];
}
function csrfFrom(string $html): string {preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m);return $m[1]??'';}
try {
    [$status,$session]=signupRequest('web','/api/session');$csrf=$session['csrf'];$key=$session['signup_key'];$oldCookie=$cookies['web'];
    $form=['business'=>'Signup '.$marker,'name'=>'New Owner','email'=>strtoupper($email),'password'=>$password,'confirm'=>$password,'request_key'=>$key,'workspace_id'=>str_repeat('a',32),'role'=>'manager'];
    [$status]=signupRequest('web','/api/signup',$form);checkSignup($status===403,'signup rejects missing CSRF');
    [$status]=signupRequest('web','/api/signup',[...$form,'request_key'=>'bad'],true,$csrf);checkSignup($status===403,'signup rejects invalid form key');
    [$status]=signupRequest('web','/api/signup',[...$form,'password'=>str_repeat('x',73),'confirm'=>str_repeat('x',73)],true,$csrf);checkSignup($status===422,'bcrypt truncation rejected');
    [$status]=signupRequest('web','/api/signup',[...$form,'confirm'=>'wrong'],true,$csrf);checkSignup($status===422,'password confirmation required');
    [$status]=signupRequest('web','/api/signup',$form,true,$csrf);$owner=query('SELECT * FROM users WHERE email=?',[$email])->fetch();if($owner)$spaces[]=$owner['workspace_id'];
    checkSignup($status===200&&$owner&&$owner['role']==='owner'&&$owner['workspace_id']!==$form['workspace_id'],'public signup works with existing workspaces');
    checkSignup($cookies['web']!==$oldCookie&&password_verify($password,$owner['password_hash']),'session rotates and password whitespace preserved');
    checkSignup((int)query('SELECT COUNT(*) FROM projects WHERE workspace_id=?',[$owner['workspace_id']])->fetchColumn()===1,'General project created atomically');
    [$status]=signupRequest('web','/api/signup',$form,true,$csrf);checkSignup($status===200&&(int)query('SELECT COUNT(*) FROM users WHERE email=?',[$email])->fetchColumn()===1,'same signup retry does not create another owner');
    [$status]=signupRequest('web','/api/signup',[...$form,'business'=>'Changed retry'],true,$csrf);checkSignup($status===409,'changed retry cannot repurpose a completed form');
    [$status,$second]=signupRequest('other','/api/session');$form2=[...$form,'request_key'=>$second['signup_key']];$before=(int)query('SELECT COUNT(*) FROM workspaces')->fetchColumn();
    [$status]=signupRequest('other','/api/signup',$form2,true,$second['csrf']);checkSignup($status===409&&(int)query('SELECT COUNT(*) FROM workspaces')->fetchColumn()===$before,'duplicate normalized identity rolls back empty workspace');
    [$status]=signupRequest('web','/api/logout',[],true,$csrf);[$status,$s]=signupRequest('web','/api/session');$csrf=$s['csrf'];
    [$status]=signupRequest('web','/api/login',['email'=>strtoupper($email),'password'=>$password],true,$csrf);checkSignup($status===200,'existing login accepts newly registered owner');
    [$status,$clientData]=signupRequest('oauth','/oauth/register',['client_name'=>'Signup test','redirect_uris'=>['http://127.0.0.1:4567/callback']]);$client=$clientData['client_id'];$verifier=str_repeat('a',43);$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $params=['response_type'=>'code','client_id'=>$client,'redirect_uri'=>'http://127.0.0.1:4567/callback','scope'=>'leads:read leads:write tasks:write','state'=>'signup-state','resource'=>'http://127.0.0.1:8792/mcp','code_challenge'=>$challenge,'code_challenge_method'=>'S256'];
    [$status,,$html]=signupRequest('oauth','/oauth/authorize?'.http_build_query($params));$oauthCsrf=csrfFrom($html);checkSignup(str_contains($html,'Create account'),'OAuth offers Create account');
    [$status]=signupRequest('oauth','/oauth/decision',['action'=>'show_signup','csrf'=>'wrong'],false);checkSignup($status===403,'OAuth signup switching requires CSRF');
    [$status,,$html]=signupRequest('oauth','/oauth/decision',['action'=>'show_signup','csrf'=>$oauthCsrf],false);preg_match('/name="request_key" value="([a-f0-9]+)"/',$html,$m);$oauthKey=$m[1];
    $oauthEmail='oauth-signup-'.$marker.'@local.test';$oauthForm=[...$form,'business'=>'OAuth '.$marker,'email'=>$oauthEmail,'request_key'=>$oauthKey,'action'=>'signup','csrf'=>$oauthCsrf];
    [$status,,$html]=signupRequest('oauth','/oauth/decision',$oauthForm,false);$other=query('SELECT * FROM users WHERE email=?',[$oauthEmail])->fetch();if($other)$spaces[]=$other['workspace_id'];checkSignup($status===200&&str_contains($html,'Your workspace is ready')&&!str_contains($html,'Allow requested access'),'OAuth signup asks for sign-in before consent');
    [$status]=signupRequest('oauth','/oauth/decision',$oauthForm,false);checkSignup($status===200&&(int)query('SELECT COUNT(*) FROM users WHERE email=?',[$oauthEmail])->fetchColumn()===1,'OAuth signup retry is safe');
    [$status,,$html]=signupRequest('oauth','/oauth/decision',['action'=>'login','csrf'=>$oauthCsrf,'email'=>$oauthEmail,'password'=>$password],false);checkSignup($status===200&&str_contains($html,'Allow requested access'),'new owner signs in and sees plugin consent');
    [$status,,,$head]=signupRequest('oauth','/oauth/decision',['action'=>'approve','csrf'=>$oauthCsrf],false);preg_match('/^Location:\s*(\S+)/mi',$head,$m);parse_str(parse_url($m[1]??'',PHP_URL_QUERY)?:'',$returned);checkSignup($status===302&&($returned['state']??'')==='signup-state','OAuth signup preserves client state');
    [$status,$tokens]=signupRequest('oauth','/oauth/token',['grant_type'=>'authorization_code','client_id'=>$client,'code'=>$returned['code'],'redirect_uri'=>$params['redirect_uri'],'resource'=>$params['resource'],'code_verifier'=>$verifier],false);checkSignup($status===200&&isset($tokens['access_token']),'new account completes PKCE plugin link');
    $project=query('SELECT id FROM projects WHERE workspace_id=?',[$owner['workspace_id']])->fetchColumn();$otherProject=query('SELECT id FROM projects WHERE workspace_id=?',[$other['workspace_id']])->fetchColumn();$lead=uid();query('INSERT INTO leads (id,workspace_id,project_id,name,notes) VALUES (?,?,?,?,?)',[$lead,$owner['workspace_id'],$project,'Private test lead','Private']);
    [$status]=signupRequest('web','/api/leads?project='.$otherProject);checkSignup($status===404,'browser cannot request other tenant project');
    $call=['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>'get_lead','arguments'=>['project_id'=>$otherProject,'id'=>$lead]]];$bearer=['Authorization: Bearer '.$tokens['access_token']];
    [$status]=signupRequest('oauth','/mcp',$call,true,null,$bearer);checkSignup($status===404,'new OAuth owner cannot read other tenant lead');
    $call['params']=['name'=>'create_task','arguments'=>['project_id'=>$otherProject,'lead_id'=>$lead,'title'=>'Forbidden','due_at'=>'2030-01-01T12:00:00.000Z']];[$status]=signupRequest('oauth','/mcp',$call,true,null,$bearer);checkSignup($status===404,'new OAuth owner cannot write other tenant task');
    $call['params']=['name'=>'list_projects','arguments'=>[]];[$status,$result]=signupRequest('oauth','/mcp',$call,true,null,$bearer);$rows=json_decode($result['result']['content'][0]['text'],true);checkSignup($status===200&&count($rows)===1&&$rows[0]['id']===$otherProject,'plugin sees only its independent workspace');
    [$status,,$html]=signupRequest('oauth','/oauth/authorize?'.http_build_query($params));$nextCsrf=csrfFrom($html);
    [$status,,,$head]=signupRequest('oauth','/oauth/decision',['action'=>'approve','csrf'=>$nextCsrf],false);preg_match('/^Location:\s*(\S+)/mi',$head,$match);parse_str(parse_url($match[1]??'',PHP_URL_QUERY)?:'',$next);
    query('UPDATE users SET auth_version=auth_version+1 WHERE id=?',[$other['id']]);
    [$status]=signupRequest('oauth','/oauth/token',['grant_type'=>'authorization_code','client_id'=>$client,'code'=>$next['code'],'redirect_uri'=>$params['redirect_uri'],'resource'=>$params['resource'],'code_verifier'=>$verifier],false);
    checkSignup($status===401,'password rotation invalidates unexchanged authorization code');
    [$status,,$html]=signupRequest('expired','/oauth/authorize?'.http_build_query($params));$expiredCsrf=csrfFrom($html);
    session_save_path(dirname(__DIR__).'/var/sessions');session_id(explode('=',$cookies['expired'],2)[1]);session_start();$_SESSION['oauth_request']['expires_at']=time()-1;session_write_close();
    [$status]=signupRequest('expired','/oauth/decision',['action'=>'show_signup','csrf'=>$expiredCsrf],false);checkSignup($status===400,'expired OAuth connection cannot create a workspace');
    // Exercise shared rate limit without running dozens of requests against real identities.
    $bucket=hash('sha256','workspace-signup-ip|127.0.0.1');query('UPDATE oauth_rate_limits SET attempts=5,window_start=UTC_TIMESTAMP() WHERE bucket=?',[$bucket]);
    [$status,$s]=signupRequest('limited','/api/session');[$status]=signupRequest('limited','/api/signup',[...$form,'email'=>'limited-'.$marker.'@local.test','request_key'=>$s['signup_key']],true,$s['csrf']);checkSignup($status===429,'public registration rate limit enforced');
    echo "Self-service signup checks passed.\n";
} finally {
    foreach($spaces as $space){foreach(['oauth_grants','oauth_codes','tasks','leads','projects','users'] as $table)query("DELETE FROM $table WHERE workspace_id=?",[$space]);query('DELETE FROM workspaces WHERE id=?',[$space]);}
    foreach($savedBuckets as $b=>$record){query('DELETE FROM oauth_rate_limits WHERE bucket=?',[$b]);if($record)query('INSERT INTO oauth_rate_limits (bucket,window_start,attempts) VALUES (?,?,?)',[$b,$record['window_start'],$record['attempts']]);}
    if($client)query('DELETE FROM oauth_clients WHERE id=?',[$client]);
    foreach(['account-login-email|'.$email,'account-login-email|'.('oauth-signup-'.$marker.'@local.test')] as $bucket)query('DELETE FROM oauth_rate_limits WHERE bucket=?',[hash('sha256',$bucket)]);
}
