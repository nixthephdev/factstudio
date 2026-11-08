<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use Fact\Pipeline;
use function Fact\db;
use function Fact\settings;
$command=$argv[1]??'help';
try{
    if($command==='work'){(new Pipeline())->work(in_array('--once',$argv,true));echo "Queue processed.\n";}
    elseif($command==='tick'){(new Pipeline())->tick();(new Pipeline())->work(false);echo "Schedule checked.\n";}
    elseif($command==='demo'){$id=db()->createJob('demo');echo "Demo queued: $id\n";(new Pipeline())->work(true);$job=db()->job($id);echo $job['status'].': '.($job['error']??'Finished')."\n";exit($job['status']==='ready'?0:1);}
    elseif($command==='preview'){$id=db()->createJob('preview');echo "AI preview queued: $id\n";(new Pipeline())->work(true);$job=db()->job($id);echo $job['status'].': '.($job['error']??'Finished')."\n";exit($job['status']==='ready'?0:1);}
    elseif($command==='retry'){$id=(int)($argv[2]??0);$job=db()->job($id);if(!in_array($job['status'],['blocked','failed'],true)){throw new RuntimeException('Only blocked or failed jobs can be retried.');}db()->update($id,['status'=>'queued','error'=>null]);(new Pipeline())->work(true);$job=db()->job($id);echo $job['status'].': '.($job['error']??'Finished')."\n";exit(in_array($job['status'],['ready','published'],true)?0:1);}
    elseif($command==='health'){echo json_encode(Fact\Health::inspect(true),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";}
    elseif($command==='monitor'){echo json_encode(Fact\Monitor::inspect(Fact\Health::inspect(true)),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";}
    else{echo "FACT Studio\n  php bin/console.php demo\n  php bin/console.php preview\n  php bin/console.php work [--once]\n  php bin/console.php tick\n  php bin/console.php health\n  php bin/console.php monitor\n";}
}catch(Throwable $e){fwrite(STDERR,settings()->redact($e->getMessage())."\n");exit(1);}
