<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
$command=$argv[1]??'help';
try{
    if($command==='pair'){
        $file=$argv[2]??'';if(!is_file($file)){throw new RuntimeException('Provide the private pairing JSON file.');}
        $pair=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if(!Fact\Hub::validUrl((string)($pair['url']??''))||!preg_match('/^[a-f0-9]{64}$/',(string)($pair['token']??''))){throw new RuntimeException('Invalid dashboard pairing file.');}
        Fact\settings()->set('remote_hub_url',rtrim($pair['url'],'/'));Fact\settings()->set('remote_hub_token',$pair['token']);
        echo "Dashboard pairing saved securely. Run php bin/hub.php run on the office GPU PC to start production control.\n";exit;
    }
    if(!in_array($command,['run','monitor'],true)){echo "FACT Studio hosted connection\n  php bin/hub.php pair <private-pairing-file.json>\n  php bin/hub.php monitor [--once]\n  php bin/hub.php run [--once]\n";exit;}
    $control=$command==='run';$lock=fopen(Fact\storage('hub.lock'),'c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){throw new RuntimeException('A hosted connection is already running on this PC.');}
    do{
        try{
            (new Fact\Hub())->sync($control);
            if($control){(new Fact\Pipeline())->tick();$pending=(int)Fact\db()->query("SELECT COUNT(*) FROM jobs WHERE status='queued'")->fetchColumn();if($pending&&!Fact\Health::workerRunning()){Fact\Process::launchWorker();}}
            echo Fact\now()." Dashboard synchronized.\n";
        }catch(Throwable $e){fwrite(STDERR,Fact\settings()->redact($e->getMessage())."\n");if(in_array('--once',$argv,true)){exit(1);}}
        if(in_array('--once',$argv,true)){break;}sleep(15);
    }while(true);
    flock($lock,LOCK_UN);fclose($lock);
}catch(Throwable $e){fwrite(STDERR,Fact\settings()->redact($e->getMessage())."\n");exit(1);}
