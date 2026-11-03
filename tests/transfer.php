<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
try{
    $backup=$argv[1]??'';$key=$argv[2]??'';
    if(!is_file($backup)||!is_file($key)){throw new RuntimeException('Provide the private backup and recovery key file paths.');}
    $original=[];foreach(Fact\Settings::SECRETS as$name){$original[$name]=Fact\settings()->get($name);}
    $target=__DIR__.'/tmp/restore-'.bin2hex(random_bytes(5));mkdir($target,0700,true);
    putenv('FACT_STORAGE='.$target);
    $output=Fact\Process::run([Fact\Process::php(),Fact\ROOT.'/bin/transfer.php','import',$backup,$key],Fact\ROOT,90);
    $restored=new Fact\Settings(new Fact\Database($target.'/studio.sqlite'));
    foreach($original as$name=>$value){if(!hash_equals($value,$restored->get($name))){throw new RuntimeException('Restored credential mismatch for '.$name);}}
    if($restored->get('instagram_enabled')!=='0'||$restored->get('auto_publish')!=='0'||$restored->get('schedule_enabled')!=='0'){throw new RuntimeException('Restored machine automation was unexpectedly enabled.');}
    if(!is_file($target.'/jobs/5/final.mp4')){throw new RuntimeException('The existing AI preview was not restored.');}
    echo "PASS: encrypted backup restores all saved credentials and video library into an isolated fresh studio.\n";
    echo "PASS: restored machine starts with Instagram, scheduling and automatic publishing disabled.\n";
    try{Fact\Process::run([Fact\Process::php(),Fact\ROOT.'/bin/transfer.php','import',$backup,$key],Fact\ROOT,90);throw new RuntimeException('Restore overwrote a configured studio.');}
    catch(Throwable $e){if(strpos($e->getMessage(),'already contains settings or videos')===false){throw $e;}}
    echo "PASS: restore refuses to overwrite a configured studio. No secrets were printed.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
