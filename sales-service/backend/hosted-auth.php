<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/registration.php';

function hostedBase(): string {
    $base=rtrim((string)getenv('FIELDWORK_OAUTH_BASE_URL'),'/');
    $local=getenv('FIELDWORK_OAUTH_ALLOW_HTTP_LOCAL')==='1' && preg_match('#^http://127\.0\.0\.1:\d+$#',$base);
    if(!preg_match('#^https://[a-z0-9.-]+(?::\d+)?$#i',$base) && !$local)fail('Hosted OAuth needs a configured HTTPS origin',503);
    return $base;
}
function hostedResource(): string {return hostedBase().'/mcp';}
function hostedSupportedScopes(): array {return ['leads:read','leads:write','tasks:write','email:send'];}
function hostedRequestedScopes(string $raw): string {
    $scopes=explode(' ',trim($raw));
    if(!$scopes||count($scopes)!==count(array_unique($scopes))||!in_array('leads:read',$scopes,true)||array_diff($scopes,hostedSupportedScopes()))fail('Invalid scope',400);
    return implode(' ',array_values(array_intersect(hostedSupportedScopes(),$scopes)));
}
function hostedJson(array $value,int $status=200): never {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    echo json_encode($value,JSON_THROW_ON_ERROR);exit;
}
function hostedHtml(string $body,int $status=200): never {
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");header('Referrer-Policy: no-referrer');
    http_response_code($status);header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Connect Fieldwork</title><style>body{font:16px system-ui;background:#f6f5ef;color:#17352d;margin:0;display:grid;place-items:center;min-height:100vh}main{box-sizing:border-box;margin:1rem 0;background:white;padding:2.5rem;max-width:32rem;width:calc(100% - 3rem);border-radius:1.2rem;box-shadow:0 15px 50px #17352d18}h1{font-size:1.8rem}label{display:block;margin:1rem 0}input{box-sizing:border-box;width:100%;padding:.75rem;border:1px solid #b7c4bd;border-radius:.5rem;font:inherit}button{padding:.8rem 1.2rem;border:0;border-radius:.5rem;background:#173f2b;color:white;font:inherit;cursor:pointer}.secondary{background:#e8eeea;color:#173f2b}p,li{line-height:1.5}.access-list{padding-left:1.4rem;margin:.5rem 0 1rem}.access-list li+li{margin-top:.4rem}.actions{display:flex;gap:.7rem;flex-wrap:wrap}form+form{margin-top:1rem}@media(max-width:600px){main{padding:1.5rem;width:calc(100% - 2rem)}}</style><main>'.$body.'</main></html>';exit;
}
function hostedEscape(string $text): string {return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hostedNow(): string {return gmdate('Y-m-d H:i:s');}
function hostedFuture(string $change): string {return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify($change)->format('Y-m-d H:i:s');}
function hostedRedirect(string $url): never {header('Location: '.$url,true,302);exit;}
function hostedClient(string $id): array {
    if(!preg_match('/^[a-f0-9]{32}$/',$id))fail('Invalid client',400);
    $client=query('SELECT * FROM oauth_clients WHERE id=?',[$id])->fetch();
    if(!$client)fail('Unknown client',400);
    return $client;
}
function hostedRateLimit(string $scope,string $identity,int $limit,int $seconds): void {
    $bucket=hash('sha256',$scope.'|'.$identity);
    $cutoff=gmdate('Y-m-d H:i:s',time()-$seconds);
    query('INSERT INTO oauth_rate_limits (bucket,window_start,attempts) VALUES (?,UTC_TIMESTAMP(),1) ON DUPLICATE KEY UPDATE attempts=IF(window_start<?,1,attempts+1),window_start=IF(window_start<?,UTC_TIMESTAMP(),window_start)',[$bucket,$cutoff,$cutoff]);
    $attempts=(int)query('SELECT attempts FROM oauth_rate_limits WHERE bucket=?',[$bucket])->fetchColumn();
    if($attempts>$limit)fail('Too many attempts. Try again later.',429);
}
function hostedRedirectAllowed(string $uri): bool {
    if(strlen($uri)>1024 || str_contains($uri,'#'))return false;
    $parts=parse_url($uri);if(!$parts||isset($parts['user'])||isset($parts['pass']))return false;
    $scheme=strtolower($parts['scheme']??'');$host=strtolower($parts['host']??'');
    if($scheme==='http' && !in_array($host,['127.0.0.1','localhost'],true))return false;
    if($scheme!=='https' && $scheme!=='http')return false;
    $allowed=array_filter(array_map('trim',explode(',',(string)(getenv('FIELDWORK_OAUTH_REDIRECT_HOSTS')?:'chatgpt.com,127.0.0.1,localhost'))));
    return in_array($host,$allowed,true);
}
function hostedMetadata(string $path): never {
    $base=hostedBase();
    if($path==='/.well-known/oauth-protected-resource' || $path==='/.well-known/oauth-protected-resource/mcp'){
        hostedJson(['resource'=>hostedResource(),'authorization_servers'=>[$base],'scopes_supported'=>hostedSupportedScopes()]);
    }
    hostedJson(['issuer'=>$base,'authorization_response_iss_parameter_supported'=>true,'authorization_endpoint'=>$base.'/oauth/authorize','token_endpoint'=>$base.'/oauth/token','registration_endpoint'=>$base.'/oauth/register','token_endpoint_auth_methods_supported'=>['none'],'code_challenge_methods_supported'=>['S256'],'scopes_supported'=>hostedSupportedScopes(),'response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token']]);
}
function hostedRegister(): never {
    hostedRateLimit('register',(string)($_SERVER['REMOTE_ADDR']??''),20,3600);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)fail('Registration too large',413);
    $body=json_decode(file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);
    if(!is_array($body)||!isset($body['redirect_uris'])||!is_array($body['redirect_uris'])||count($body['redirect_uris'])<1||count($body['redirect_uris'])>5)fail('Invalid redirect URIs',400);
    foreach($body['redirect_uris'] as $uri)if(!is_string($uri)||!hostedRedirectAllowed($uri))fail('Redirect URI not allowed',400);
    $uris=array_values(array_unique($body['redirect_uris']));
    if(($body['token_endpoint_auth_method']??'none')!=='none')fail('Only public PKCE clients are supported',400);
    $name=trim((string)($body['client_name']??'Codex client'));if($name===''||mb_strlen($name)>150)fail('Invalid client name',400);
    $id=uid();query('INSERT INTO oauth_clients (id,name,redirect_uris) VALUES (?,?,?)',[$id,$name,json_encode($uris,JSON_THROW_ON_ERROR)]);
    hostedJson(['client_id'=>$id,'client_id_issued_at'=>time(),'client_name'=>$name,'redirect_uris'=>$uris,'grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code'],'token_endpoint_auth_method'=>'none'],201);
}
function hostedAuthorizationPage(?string $error=null,bool $signup=false): never {
    $request=$_SESSION['oauth_request']??null;
    if(!$request||($request['expires_at']??0)<time())hostedHtml('<h1>Connection expired</h1><p>Return to Codex and try connecting again.</p>',400);
    $name=hostedEscape($request['client_name']);$csrf=hostedEscape($_SESSION['oauth_csrf']);
    $warning=$error?'<p role="alert">'.hostedEscape($error).'</p>':'';
    $scopes=explode(' ',$request['scope']);
    $access=['read leads and internal follow-ups'];
    if(in_array('leads:write',$scopes,true))$access[]='create and update leads and notes';
    if(in_array('tasks:write',$scopes,true))$access[]='create, reschedule, and complete internal follow-ups';
    if(in_array('email:send',$scopes,true))$access[]='send an email to a lead only when a signed-in user explicitly allows it on that agent task';
    $accessList='<ul class="access-list">';
    foreach($access as $permission)$accessList.='<li>'.hostedEscape(ucfirst($permission)).'</li>';
    $accessList.='</ul>';
    if(empty($_SESSION['user_id'])){
        $switch='<form method="post" action="/oauth/decision"><input type="hidden" name="csrf" value="'.$csrf.'"><button class="secondary" name="action" value="'.($signup?'show_login':'show_signup').'">'.($signup?'Already have an account? Sign in':'Create account').'</button></form>';
        if($signup)hostedHtml('<p>FIELDWORK REACH</p><h1>Create your account</h1><p>Create an independent workspace. To join an existing team, use your owner’s invitation instead.</p>'.$warning.'<form method="post" action="/oauth/decision"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="signup"><input type="hidden" name="request_key" value="'.hostedEscape(signupKey()).'"><label>Workspace name<input name="business" autocomplete="organization" maxlength="150" required></label><label>Your name<input name="name" autocomplete="name" maxlength="150" required></label><label>Email<input name="email" type="email" autocomplete="email" maxlength="254" required></label><label>Password · 12 characters minimum<input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label><label>Confirm password<input name="confirm" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label><button>Create account</button></form>'.$switch);
        $created=isset($_SESSION['signup_receipt'])?'<p role="status">Your workspace is ready. Sign in, then review the plugin permissions.</p>':'';
        hostedHtml('<p>FIELDWORK REACH</p><h1>Sign in to connect</h1><p>'.$name.' requests permission to:</p>'.$accessList.'<p>Email is sent only for individually authorized agent tasks; no calls or calendar invitations are made.</p>'.$created.$warning.'<form method="post" action="/oauth/decision"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="login"><label>Email<input name="email" type="email" autocomplete="username" maxlength="254" required></label><label>Password<input name="password" type="password" autocomplete="current-password" maxlength="500" required></label><button>Sign in</button></form>'.$switch);
    }
    $user=query('SELECT name,active,auth_version FROM users WHERE id=?',[$_SESSION['user_id']])->fetch();
    if(!$user||!$user['active']||(int)$user['auth_version']!==(int)($_SESSION['auth_version']??0)){unset($_SESSION['user_id']);hostedAuthorizationPage();}
    hostedHtml('<p>FIELDWORK</p><h1>Connect '.$name.'?</h1><p>Signed in as <strong>'.hostedEscape($user['name']).'</strong>. This grants access in your workspace to:</p>'.$accessList.'<p>Email is sent only for individually authorized agent tasks; no calls or calendar invitations are made.</p>'.$warning.'<form method="post" action="/oauth/decision"><input type="hidden" name="csrf" value="'.$csrf.'"><div class="actions"><button name="action" value="approve">Allow requested access</button><button class="secondary" name="action" value="deny">Cancel</button></div></form>');
}
function hostedAuthorize(): never {
    $client=hostedClient((string)($_GET['client_id']??''));$redirect=(string)($_GET['redirect_uri']??'');
    if(!in_array($redirect,json_decode($client['redirect_uris'],true,8,JSON_THROW_ON_ERROR),true))fail('Unregistered redirect URI',400);
    $challenge=(string)($_GET['code_challenge']??'');$state=(string)($_GET['state']??'');$resource=(string)($_GET['resource']??'');
    if(($_GET['response_type']??'')!=='code'||($_GET['code_challenge_method']??'')!=='S256'||!preg_match('/^[A-Za-z0-9_-]{43}$/',$challenge)||strlen($state)>512||$resource!==hostedResource())fail('Invalid authorization request',400);
    $scope=hostedRequestedScopes((string)($_GET['scope']??''));
    $_SESSION['oauth_request']=['client_id'=>$client['id'],'client_name'=>$client['name'],'redirect_uri'=>$redirect,'challenge'=>$challenge,'state'=>$state,'resource'=>$resource,'scope'=>$scope,'expires_at'=>time()+1800];
    $_SESSION['oauth_csrf']=bin2hex(random_bytes(24));hostedAuthorizationPage();
}
function hostedDecision(): never {
    if(!isset($_SESSION['oauth_csrf'])||!hash_equals($_SESSION['oauth_csrf'],(string)($_POST['csrf']??'')))fail('Invalid connection form',403);
    $request=$_SESSION['oauth_request']??null;if(!$request||($request['expires_at']??0)<time())fail('Connection expired. Return to Codex and connect again.',400);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)fail('Connection form too large',413);
    $action=(string)($_POST['action']??'');
    if(in_array($action,['show_signup','show_login','signup'],true)){
        if(isset($_SESSION['user_id']))fail('Sign out before creating another workspace',409);
        if($action==='signup'){
            try{createWorkspaceOwner($_POST);}
            catch(RuntimeException $error){if(!in_array($error->getCode(),[409,422],true))throw $error;hostedAuthorizationPage($error->getMessage(),true);}
            hostedAuthorizationPage();
        }
        hostedAuthorizationPage(null,$action==='show_signup');
    }
    if($action==='login'){
        $email=strtolower(trim((string)($_POST['email']??'')));$password=(string)($_POST['password']??'');
        accountLoginLimit($email);
        $user=query('SELECT * FROM users WHERE email=? AND active=1',[$email])->fetch();
        if(!$user||!password_verify($password,$user['password_hash'])){usleep(400000);hostedAuthorizationPage('Email or password is incorrect.');}
        signInAccount($user);hostedAuthorizationPage();
    }
    if(!in_array($action,['approve','deny'],true))fail('Invalid decision',400);
    $parts=['state'=>$request['state'],'iss'=>hostedBase()];
    if($action==='deny')$parts['error']='access_denied';
    else{
        $user=query('SELECT id,workspace_id,active,auth_version FROM users WHERE id=?',[$_SESSION['user_id']??''])->fetch();
        if(!$user||!$user['active']||(int)$user['auth_version']!==(int)($_SESSION['auth_version']??0))fail('Sign in again',401);
        $code=bin2hex(random_bytes(32));
        query('INSERT INTO oauth_codes (code_hash,client_id,user_id,workspace_id,redirect_uri,resource,scope,code_challenge,expires_at,auth_version) VALUES (?,?,?,?,?,?,?,?,?,?)',[hash('sha256',$code),$request['client_id'],$user['id'],$user['workspace_id'],$request['redirect_uri'],$request['resource'],$request['scope'],$request['challenge'],hostedFuture('+5 minutes'),(int)$user['auth_version']]);
        $parts['code']=$code;
    }
    unset($_SESSION['oauth_request'],$_SESSION['oauth_csrf']);
    hostedRedirect($request['redirect_uri'].(str_contains($request['redirect_uri'],'?')?'&':'?').http_build_query($parts,'','&',PHP_QUERY_RFC3986));
}
function hostedIssueGrant(array $user,string $clientId,string $resource,string $scope): array {
    $access=bin2hex(random_bytes(32));$refresh=bin2hex(random_bytes(32));
    query('INSERT INTO oauth_grants (id,client_id,user_id,workspace_id,resource,scope,access_hash,refresh_hash,access_expires_at,refresh_expires_at,auth_version) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[uid(),$clientId,$user['id'],$user['workspace_id'],$resource,$scope,hash('sha256',$access),hash('sha256',$refresh),hostedFuture('+1 hour'),hostedFuture('+30 days'),(int)$user['auth_version']]);
    return ['access_token'=>$access,'token_type'=>'Bearer','expires_in'=>3600,'refresh_token'=>$refresh,'scope'=>$scope];
}
function hostedToken(): never {
    $client=hostedClient((string)($_POST['client_id']??''));$resource=(string)($_POST['resource']??'');
    if($resource!==hostedResource())fail('Invalid resource',400);
    $grant=(string)($_POST['grant_type']??'');db()->beginTransaction();
    if($grant==='authorization_code'){
        $code=(string)($_POST['code']??'');$verifier=(string)($_POST['code_verifier']??'');
        if(!preg_match('/^[a-f0-9]{64}$/',$code)||!preg_match('/^[A-Za-z0-9._~-]{43,128}$/',$verifier))fail('Invalid authorization code',400);
        $record=query('SELECT * FROM oauth_codes WHERE code_hash=? FOR UPDATE',[hash('sha256',$code)])->fetch();
        if(!$record||$record['used_at']||$record['expires_at']<=hostedNow()||$record['client_id']!==$client['id']||$record['resource']!==$resource||$record['redirect_uri']!==($_POST['redirect_uri']??''))fail('Invalid authorization code',400);
        $computed=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
        if(!hash_equals($record['code_challenge'],$computed))fail('Invalid code verifier',400);
        $user=query('SELECT id,workspace_id,auth_version,active FROM users WHERE id=? AND workspace_id=?',[$record['user_id'],$record['workspace_id']])->fetch();
        if(!$user||!$user['active']||(int)$user['auth_version']!==(int)$record['auth_version'])fail('Account unavailable. Sign in again.',401);
        query('UPDATE oauth_codes SET used_at=UTC_TIMESTAMP() WHERE code_hash=?',[$record['code_hash']]);
        $result=hostedIssueGrant($user,$client['id'],$resource,$record['scope']);
    }elseif($grant==='refresh_token'){
        $refresh=(string)($_POST['refresh_token']??'');if(!preg_match('/^[a-f0-9]{64}$/',$refresh))fail('Invalid refresh token',400);
        $record=query('SELECT g.*,u.active,u.auth_version AS current_auth_version FROM oauth_grants g JOIN users u ON u.id=g.user_id AND u.workspace_id=g.workspace_id WHERE g.refresh_hash=? FOR UPDATE',[hash('sha256',$refresh)])->fetch();
        if(!$record||$record['client_id']!==$client['id']||$record['resource']!==$resource||$record['revoked_at']||$record['refresh_expires_at']<=hostedNow()||!$record['active']||(int)$record['auth_version']!==(int)$record['current_auth_version'])fail('Invalid refresh token',400);
        query('UPDATE oauth_grants SET revoked_at=UTC_TIMESTAMP() WHERE id=?',[$record['id']]);
        $result=hostedIssueGrant(['id'=>$record['user_id'],'workspace_id'=>$record['workspace_id'],'auth_version'=>$record['auth_version']],$client['id'],$resource,$record['scope']);
    }else fail('Unsupported grant type',400);
    db()->commit();hostedJson($result);
}
function hostedBearer(string $requiredScope='leads:read'): array {
    $header=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    if(!preg_match('/^Bearer ([a-f0-9]{64})$/',$header,$match))fail('Authentication required',401);
    $record=query('SELECT g.*,u.name,u.role,u.active,u.auth_version AS current_auth_version FROM oauth_grants g JOIN users u ON u.id=g.user_id AND u.workspace_id=g.workspace_id WHERE g.access_hash=?',[hash('sha256',$match[1])])->fetch();
    if(!$record||$record['resource']!==hostedResource()||$record['revoked_at']||$record['access_expires_at']<=hostedNow()||!$record['active']||(int)$record['auth_version']!==(int)$record['current_auth_version'])fail('Authentication required',401);
    if(!in_array($requiredScope,explode(' ',$record['scope']),true))fail('Additional Fieldwork permission required',403);
    return $record;
}
function hostedChallenge(string $requiredScope='leads:read',string $error='invalid_token'): string {
    $scope=$requiredScope==='leads:read'?'leads:read':'leads:read '.$requiredScope;
    return 'Bearer resource_metadata="'.hostedBase().'/.well-known/oauth-protected-resource", scope="'.$scope.'", error="'.$error.'", error_description="Connect your Fieldwork account with the requested permission"';
}
