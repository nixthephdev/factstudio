<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
try {
    $research=new Fact\Research();$candidates=$research->candidates();$source=$research->article($candidates[0]);
    echo json_encode(['candidates'=>count($candidates),'article'=>$source['title'],'source_url'=>$source['url'],'readable_characters'=>mb_strlen($source['text'])],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
