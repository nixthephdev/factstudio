<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
$lock=fopen(Fact\storage('worker.lock'),'c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Wait for the active studio worker to finish.\n");exit(1);}
$id=0;
try{
    if(($argv[1]??'')==='--resume'){
        $id=(int)($argv[2]??0);$job=Fact\db()->job($id);
        if(($job['data']['adapter']??'')!=='animatelcm'||$job['mode']!=='preview'){throw new RuntimeException('Only motion previews can be resumed here.');}
        if($job['status']==='skipped'){throw new RuntimeException('This motion study was skipped or rejected. Create a new shot; its diagnostic files are preserved.');}
        if($job['status']==='ready'){echo "Motion preview $id is already ready.\n";exit;}
    }else{$id=Fact\MotionVideo::create(implode(' ',array_slice($argv,1)));}
    echo "Motion preview: $id\n";flush();
    (new Fact\MotionVideo())->run(Fact\db()->job($id));
    echo "Ready: ".Fact\storage('jobs/'.$id.'/final.mp4')."\nOpen http://localhost:8085/?view=library\n";
}catch(Throwable $e){
    $message=Fact\settings()->redact($e->getMessage());
    if($id){Fact\db()->update($id,['status'=>'blocked','error'=>$message]);Fact\db()->event($id,$message);}
    fwrite(STDERR,$message.PHP_EOL);exit(1);
}finally{flock($lock,LOCK_UN);fclose($lock);}
