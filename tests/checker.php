<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
$source=['title'=>'Test source','text'=>'The storm intensified after crossing the sea. It developed a circular shape with a large eye.'];
$scene=['narration'=>'The storm moved across the sea without intensifying.','visual_prompt'=>'A storm illustration','evidence'=>'The storm intensified after crossing the sea.'];
$draft=['title'=>'A storm crossing the sea','narration'=>$scene['narration'],'scenes'=>[$scene,$scene,$scene,$scene]];
try{(new Fact\Writer())->check($draft,$source);fwrite(STDERR,"FAIL: the local checker accepted a direct contradiction.\n");exit(1);}
catch(Throwable $e){if(strpos($e->getMessage(),'Automated source check rejected')!==0){fwrite(STDERR,$e->getMessage()."\n");exit(1);}echo "PASS: local AI checker rejected a narration that contradicts its evidence.\n";}
$scene=['narration'=>'After crossing the sea, the storm grew stronger.','visual_prompt'=>'A storm illustration','evidence'=>'The storm intensified after crossing the sea.'];
$draft=['title'=>'A storm grows stronger','narration'=>$scene['narration'],'scenes'=>[$scene,$scene,$scene,$scene]];
try{(new Fact\Writer())->check($draft,$source);echo "PASS: local AI checker accepted a faithful paraphrase of its evidence.\n";}
catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
