<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

$base=rtrim(Fact\settings()->get('instagram_video_base'),'/');
if (!preg_match('~^https://[a-z0-9-]+\.trycloudflare\.com$~',$base)) {
    fwrite(STDERR,"Start the Instagram Quick Tunnel first.\n");exit(1);
}
try {
    foreach (['/','/index.php?action=api','/storage/secret.key'] as $path) {
        $r=Fact\Http::request('GET',$base.$path,null,[],20,100000,[403,404]);
        if (!in_array($r['status'],[403,404],true)) { throw new RuntimeException('Private route was publicly accessible: '.$path); }
    }
    echo "PASS: public dashboard, API and credential file access blocked\n";
    $jobId=null;
    foreach (Fact\db()->jobs() as $job) {
        if (is_file(Fact\storage('jobs/'.$job['id'].'/final.mp4'))) { $jobId=(int)$job['id'];break; }
    }
    if ($jobId===null) { throw new RuntimeException('An existing rendered video is needed to test media delivery.'); }
    $expires=time()+300;
    $path='/index.php?'.http_build_query(['action'=>'media','id'=>$jobId,'kind'=>'video','expires'=>$expires,'token'=>Fact\Publishers::mediaToken($jobId,$expires)]);
    $r=Fact\Http::request('HEAD',$base.$path,null,[],30,1000);
    if ($r['status']!==200 || $r['body']!=='' || (int)($r['headers']['content-length']??0)<=0 || ($r['headers']['content-type']??'')!=='video/mp4') {
        throw new RuntimeException('Signed video header check failed.');
    }
    echo "PASS: signed video headers available over public HTTPS; no video bytes transferred\n";
    $r=Fact\Http::request('GET',$base.'/index.php?action=media&kind=video&id='.$jobId.'&expires='.$expires.'&token=invalid',null,[],20,1000,[403]);
    if ($r['status']!==403) { throw new RuntimeException('Invalid video signature was accepted.'); }
    echo "PASS: invalid video signature blocked\nNo publishing requests were made.\n";
} catch(Throwable $e) {
    fwrite(STDERR,Fact\settings()->redact($e->getMessage()).PHP_EOL);exit(1);
}
