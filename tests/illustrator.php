<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
try{
    $directory=Fact\storage('benchmarks/illustrator');if(!is_dir($directory)){mkdir($directory,0700,true);}
    $started=microtime(true);$images=(new Fact\Visuals())->generate([['visual_prompt'=>'A cinematic editorial illustration of a brilliant blue and orange comet crossing a deep navy star field above a mountain horizon, delicate flowing comet tails, scientifically inspired, no text or labels']],$directory,false);
    $image=getimagesize($directory.'/'.$images[0]);
    echo json_encode(['seconds'=>round(microtime(true)-$started,1),'width'=>$image[0],'height'=>$image[1],'file'=>$directory.'/'.$images[0]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
