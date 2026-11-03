<?php
declare(strict_types=1);
namespace Fact;

final class Health {
    public static function inspect(bool $force=false): array {
        $cache=storage('health.json');if(!$force&&is_file($cache)&&time()-filemtime($cache)<25){return json_decode(file_get_contents($cache),true)?:[];}
        $s=settings();$items=[];
        $items['ffmpeg']=['name'=>'Video renderer','ready'=>Process::binary('ffmpeg')!==null&&Process::binary('ffprobe')!==null,'detail'=>'FFmpeg + FFprobe'];
        try{$tags=Http::json('GET',rtrim($s->get('ollama_url'),'/').'/api/tags',null,[],2);$names=array_column($tags['models']??[],'name');$model=$s->get('ollama_model');$found=in_array($model,$names,true)||in_array($model.':latest',$names,true);$items['ollama']=['name'=>'Local writer','ready'=>$found,'detail'=>$found?$model:'Ollama is running; download '.$model];}catch(\Throwable $e){$items['ollama']=['name'=>'Local writer','ready'=>false,'detail'=>'Install or start Ollama'];}
        try{$info=Http::json('GET',rtrim($s->get('comfy_url'),'/').'/object_info/CheckpointLoaderSimple',null,[],2);$checkpoints=$info['CheckpointLoaderSimple']['input']['required']['ckpt_name'][0]??[];$chosen=$s->get('comfy_checkpoint');$items['comfy']=['name'=>'AI illustrations','ready'=>$chosen!==''&&in_array($chosen,$checkpoints,true),'detail'=>$chosen!==''?'Checkpoint: '.$chosen:'ComfyUI is running; choose a checkpoint','checkpoints'=>$checkpoints];}catch(\Throwable $e){$items['comfy']=['name'=>'AI illustrations','ready'=>false,'detail'=>'Install or start ComfyUI','checkpoints'=>[]];}
        $items['elevenlabs']=['name'=>'Narration','ready'=>$s->get('eleven_api_key')!==''&&$s->get('eleven_voice')!=='','detail'=>$s->get('eleven_api_key')!==''?'Credentials saved; connection not yet tested':'Add ElevenLabs key + voice'];
        $items['youtube']=['name'=>'YouTube','ready'=>$s->get('google_refresh_token')!=='','detail'=>$s->get('youtube_channel')?:'Connect a channel'];
        $items['facebook']=['name'=>'Facebook','ready'=>$s->get('facebook_token')!==''&&$s->get('facebook_page_id')!=='','detail'=>'Page publishing'];
        $items['instagram']=['name'=>'Instagram','ready'=>$s->get('instagram_token')!==''&&$s->get('instagram_id')!==''&&$s->get('instagram_video_base')!=='','detail'=>'Professional account + public video URL'];
        $items['disk']=['name'=>'Free storage','ready'=>(float)disk_free_space(ROOT)>3*1024*1024*1024,'detail'=>round((float)disk_free_space(ROOT)/1024/1024/1024,1).' GB available'];
        file_put_contents($cache,json_encode($items),LOCK_EX);return $items;
    }
    public static function workerRunning(): bool {
        $handle=fopen(storage('worker.lock'),'c');if(!$handle){return false;}$free=flock($handle,LOCK_EX|LOCK_NB);if($free){flock($handle,LOCK_UN);}fclose($handle);return !$free;
    }
}
