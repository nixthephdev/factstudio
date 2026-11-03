<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
$id=(int)($argv[1]??0);
try{
    $job=Fact\db()->job($id);(new Fact\Writer())->check($job['data']['script'],$job['data']['sources'][0]);
    echo "Source review accepted job $id.\n";
}catch(Throwable $e){
    if(isset($job)&&$job['mode']==='preview'&&strpos($e->getMessage(),'Automated source check rejected')===0){Fact\db()->update($id,['status'=>'skipped','error'=>'Earlier preview failed the stricter scene-by-scene source check. Generate a new AI preview.']);Fact\db()->event($id,'Earlier preview retired after the stricter source check rejected its narration.');}
    fwrite(STDERR,$e->getMessage()."\n");exit(1);
}
