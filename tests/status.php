<?php
require __DIR__.'/../app/bootstrap.php';
$jobs=Fact\db()->jobs();
foreach(array_slice($jobs,0,3)as$job){echo json_encode(['id'=>$job['id'],'mode'=>$job['mode'],'status'=>$job['status'],'stage'=>$job['stage'],'title'=>$job['title'],'error'=>$job['error']],JSON_UNESCAPED_SLASHES)."\n";}
