<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

// Run under a service manager on an always-on host. Existing production switches still apply.
$interval=30;
foreach(array_slice($argv,1) as $argument){
    if(preg_match('/^--interval=(\d+)$/',$argument,$matches)){$interval=max(5,min(300,(int)$matches[1]));}
    elseif($argument!=='--once'){fwrite(STDERR,"Usage: php bin/daemon.php [--once] [--interval=30]\n");exit(1);}
}
$lock=fopen(Fact\storage('daemon.lock'),'c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Another FACT Studio daemon is already running.\n");exit(1);}
$stopping=false;
if(function_exists('pcntl_async_signals')){
    pcntl_async_signals(true);
    foreach([SIGTERM,SIGINT] as $signal){pcntl_signal($signal,function()use(&$stopping): void{$stopping=true;});}
}
try{
    do{
        try{
            $pipeline=new Fact\Pipeline();$pipeline->tick();$pipeline->work(true);
        }catch(Throwable $e){fwrite(STDERR,Fact\now().' '.Fact\settings()->redact($e->getMessage())."\n");}
        if(in_array('--once',$argv,true)||$stopping){break;}
        sleep($interval);
    }while(!$stopping);
}finally{flock($lock,LOCK_UN);fclose($lock);}
