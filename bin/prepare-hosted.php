<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/bootstrap.php';
$url='https://fulodental.com/studio';$remoteRoot='/home/u758889313/domains/fulodental.com/fact-studio';
$directory=Fact\storage('deploy');if(!is_dir($directory)){mkdir($directory,0700,true);}
$bundlePath=$directory.'/hosted-setup.json';
if(is_file($bundlePath)){$bundle=json_decode(file_get_contents($bundlePath),true,512,JSON_THROW_ON_ERROR);}
else{
    $password=rtrim(strtr(base64_encode(random_bytes(21)),'+/','-_'),'=');
    $bundle=['username'=>'nix','password'=>$password,'password_hash'=>password_hash($password,PASSWORD_BCRYPT),
        'worker_token'=>bin2hex(random_bytes(32)),'app_key'=>'base64:'.base64_encode(random_bytes(32))];
    file_put_contents($bundlePath,json_encode($bundle,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);@chmod($bundlePath,0600);
}
$values=['APP_NAME'=>'FACT Studio','APP_ENV'=>'production','APP_KEY'=>$bundle['app_key'],'APP_DEBUG'=>'false','APP_URL'=>$url,'ASSET_URL'=>$url,
    'APP_TIMEZONE'=>'Asia/Singapore','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$remoteRoot.'/database/studio.sqlite',
    'SESSION_SECURE_COOKIE'=>'true','FACT_ADMIN_USER'=>$bundle['username'],'FACT_ADMIN_PASSWORD_HASH'=>$bundle['password_hash'],
    'FACT_WORKER_TOKEN_HASH'=>hash('sha256',$bundle['worker_token'])];
$format=static function(array $values): string{$lines=[];foreach($values as $key=>$value){$lines[]=$key."='".$value."'";}return implode("\n",$lines)."\n";};
file_put_contents($directory.'/hosted-production.env',$format($values));@chmod($directory.'/hosted-production.env',0600);
file_put_contents($directory.'/office-pairing.json',json_encode(['url'=>$url,'token'=>$bundle['worker_token']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));@chmod($directory.'/office-pairing.json',0600);
file_put_contents($directory.'/control-room-access.txt',"FACT Studio\nURL: $url\nUsername: ".$bundle['username']."\nPassword: ".$bundle['password']."\n\nKeep this file private.\n");@chmod($directory.'/control-room-access.txt',0600);
$values['APP_ENV']='local';$values['APP_URL']='http://127.0.0.1:8095';$values['ASSET_URL']='http://127.0.0.1:8095';$values['DB_DATABASE']=str_replace('\\','/',realpath(__DIR__.'/../hosted/database')).'/studio.sqlite';$values['SESSION_SECURE_COOKIE']='false';
if(!is_file(__DIR__.'/../hosted/.env')){file_put_contents(__DIR__.'/../hosted/.env',$format($values));}
if(!is_file(__DIR__.'/../hosted/database/studio.sqlite')){touch(__DIR__.'/../hosted/database/studio.sqlite');}
echo "Private deployment files prepared under storage/deploy/. No credentials were printed.\n";
