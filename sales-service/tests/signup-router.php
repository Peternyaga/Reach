<?php
// Dedicated local test server. Never packaged for hosting.
declare(strict_types=1);
if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true))exit;
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/api/'))require dirname(__DIR__).'/backend/router.php';
elseif(str_starts_with($path,'/oauth/')||$path==='/mcp'||str_starts_with($path,'/.well-known/'))require dirname(__DIR__).'/backend/hosted-router.php';
else {
    $file=realpath(dirname(__DIR__).'/dist'.$path);$root=realpath(dirname(__DIR__).'/dist');
    if($file&&is_file($file)&&str_starts_with($file,$root.DIRECTORY_SEPARATOR)){
        header('Content-Type: '.match(pathinfo($file,PATHINFO_EXTENSION)){'js'=>'text/javascript','css'=>'text/css',default=>'text/html'});readfile($file);
    }else {header('Content-Type: text/html');readfile($root.'/index.html');}
}
