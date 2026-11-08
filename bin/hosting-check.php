<?php
declare(strict_types=1);
// CLI-only, read-only diagnostics. Does not load the studio database or credentials.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$disabled=array_filter(array_map('trim',explode(',',(string)ini_get('disable_functions'))));
$extensions=[];foreach(['curl','gd','mbstring','openssl','pdo_sqlite'] as $extension){$extensions[$extension]=extension_loaded($extension);}
$processes=function_exists('proc_open')&&!in_array('proc_open',$disabled,true);
$ffmpeg=false;$ffprobe=false;
foreach(explode(PATH_SEPARATOR,getenv('PATH')?:'') as $directory){
    foreach(['ffmpeg','ffprobe'] as $binary){
        if(is_file(rtrim($directory,'/\\').'/'.$binary.(PHP_OS_FAMILY==='Windows'?'.exe':''))){$$binary=true;}
    }
}
$root=getenv('FACT_CHECK_ROOT')?:dirname(__DIR__);$storage=$root.'/storage';
echo json_encode(['php'=>PHP_VERSION,'os'=>PHP_OS_FAMILY,'architecture'=>PHP_INT_SIZE*8,
    'extensions'=>$extensions,'background_processes'=>$processes,'ffmpeg_on_path'=>$ffmpeg,'ffprobe_on_path'=>$ffprobe,
    'storage_writable'=>is_dir($storage)&&is_writable($storage),'disk_free_gb'=>round((float)disk_free_space($root)/1073741824,1),
    'max_execution_time'=>(int)ini_get('max_execution_time'),'memory_limit'=>ini_get('memory_limit'),
    'persistent_service_required'=>true,'ai_compute_required'=>true,
    'note'=>'These checks do not verify hosting permission for persistent workers, installed AI models, GPU access or public dashboard authentication.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
