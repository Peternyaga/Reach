<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

// Shared by browser registration and OAuth; do not trust proxy headers as IPs.
function accountRateLimit(string $scope,string $identity,int $limit,int $seconds): void {
    $bucket=hash('sha256',$scope.'|'.$identity);$cutoff=gmdate('Y-m-d H:i:s',time()-$seconds);
    query('INSERT INTO oauth_rate_limits (bucket,window_start,attempts) VALUES (?,UTC_TIMESTAMP(),1) ON DUPLICATE KEY UPDATE attempts=IF(window_start<?,1,attempts+1),window_start=IF(window_start<?,UTC_TIMESTAMP(),window_start)',[$bucket,$cutoff,$cutoff]);
    if((int)query('SELECT attempts FROM oauth_rate_limits WHERE bucket=?',[$bucket])->fetchColumn()>$limit)fail('Too many attempts. Try again later.',429);
}
function accountLoginLimit(string $email): void {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    accountRateLimit('account-login-ip',$ip,40,900);
    accountRateLimit('account-login-email',$email,8,900);
}
function signupKey(): string {return $_SESSION['signup_key']??=bin2hex(random_bytes(24));}
function accountNewPassword(mixed $password): string {
    // PASSWORD_DEFAULT uses bcrypt: reject truncation instead of ignoring bytes.
    if(!is_string($password)||strlen($password)<12||strlen($password)>72||str_contains($password,"\0"))fail('Use a password of 12–72 bytes (non-ASCII characters may use more than one byte)');
    return $password;
}
function createWorkspaceOwner(array $data): array {
    $key=textField($data,'request_key',48,true);
    if(!hash_equals(signupKey(),$key))fail('Refresh the page and try again',403);
    $business=textField($data,'business',150,true);$name=textField($data,'name',150,true);
    $email=strtolower(textField($data,'email',254,true));
    $password=accountNewPassword($data['password']??null);$confirm=$data['confirm']??null;
    if(!is_string($confirm)||!hash_equals($password,$confirm))fail('Passwords do not match');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Check your email address');
    $digest=hash_hmac('sha256',json_encode([$business,$name,$email,$password],JSON_THROW_ON_ERROR),$key);
    $receipt=$_SESSION['signup_receipt']??null;
    if($receipt){
        if(!hash_equals($receipt['digest'],$digest))fail('This form has already been submitted. Reload to start again.',409);
        return $receipt['user'];
    }
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    accountRateLimit('workspace-signup-ip',$ip,5,3600);
    accountRateLimit('workspace-signup-global','all',100,3600);
    $hash=password_hash($password,PASSWORD_DEFAULT);$workspaceId=uid();$userId=uid();
    db()->beginTransaction();
    try {
        query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$workspaceId,$business]);
        query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$userId,$workspaceId,$name,$email,$hash,'owner']);
        query('INSERT INTO projects (id,workspace_id,name) VALUES (?,?,?)',[uid(),$workspaceId,'General']);
        db()->commit();
    } catch(Throwable $error){
        if(db()->inTransaction())db()->rollBack();
        if($error instanceof PDOException&&($error->errorInfo[1]??null)===1062)fail('That email is already registered. Sign in, or use your team invitation.',409);
        throw $error;
    }
    $user=['id'=>$userId,'workspace_id'=>$workspaceId,'name'=>$name,'email'=>$email,'role'=>'owner','auth_version'=>1];
    $_SESSION['signup_receipt']=['digest'=>$digest,'user'=>$user];
    return $user;
}
function signInAccount(array $user): void {
    session_regenerate_id(true);
    unset($_SESSION['agent_session']);
    $_SESSION['user_id']=$user['id'];$_SESSION['workspace_id']=$user['workspace_id'];
    $_SESSION['user_name']=$user['name'];$_SESSION['user_role']=$user['role'];$_SESSION['auth_version']=(int)$user['auth_version'];
}
