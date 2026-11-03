<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
try{
    $started=microtime(true);$research=new Fact\Research();$writer=new Fact\Writer();$candidates=$research->candidates();
    $selected=$writer->select($candidates,'A short, surprising space explanation',[]);$source=$research->article($selected);
    $script=$writer->produce($source,'A short, surprising space explanation');
    $result=['seconds'=>round(microtime(true)-$started,1),'source'=>$source,'script'=>$script];
    if(!is_dir(Fact\storage('benchmarks'))){mkdir(Fact\storage('benchmarks'),0700,true);}
    file_put_contents(Fact\storage('benchmarks/writer.json'),json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    echo json_encode(['seconds'=>$result['seconds'],'title'=>$script['title'],'words'=>$script['word_count'],'scenes'=>count($script['scenes']),'source'=>$source['url']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
