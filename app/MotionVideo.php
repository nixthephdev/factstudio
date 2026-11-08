<?php
declare(strict_types=1);
namespace Fact;

/** Short, silent, non-publishing motion tests on the dedicated local ComfyUI server. */
final class MotionVideo {
    public const BASE = 'http://127.0.0.1:8189';
    public const DEFAULT_PROMPT = 'A waterfall flowing down dark rocks in a lush green forest, white water splashes into a pool, realistic nature cinematography, static camera';

    public static function assertReady(): void {
        try {
            $info=Http::json('GET',self::BASE.'/object_info/ADE_AnimateDiffLoaderGen1',null,[],5);
            $combo=$info['ADE_AnimateDiffLoaderGen1']['input']['required']['model_name']??[];
            $models=$combo[1]['options']??$combo[0]??[];
            if(!in_array('AnimateLCM_sd15_t2v.ckpt',$models,true)){throw new \RuntimeException('Motion model missing.');}
        } catch(\Throwable $e) {
            throw new \RuntimeException('Start the motion server with scripts/start-animation.ps1 after running scripts/install-animation.ps1.');
        }
    }

    public static function create(string $prompt=''): int {
        self::assertReady();
        $prompt=trim($prompt)?:self::DEFAULT_PROMPT;
        if(mb_strlen($prompt)>1000){throw new \InvalidArgumentException('Keep the motion prompt under 1,000 characters.');}
        $id=db()->createJob('preview',$prompt);
        db()->update($id,['title'=>'Motion study: '.mb_substr($prompt,0,70),'data'=>[
            'adapter'=>'animatelcm','prompt'=>$prompt,'seed'=>random_int(1,2147483646),
            'production_note'=>'Experimental local AnimateLCM motion study. 16 generated frames at 512 × 512, played at 8 fps (2 seconds). Silent, no text overlays, no paid calls, never publishes. Prompt adherence and motion quality vary. AI imagery is illustrative, not scientific footage.'
        ]]);
        db()->event($id,'Short motion preview queued. Generates a sequence of frames with a temporal model; no narration or publishing.');
        return $id;
    }

    public static function workflow(string $prompt,string $checkpoint,int $seed,string $tag): array {
        $graph=json_decode(file_get_contents(ROOT.'/workflows/animatelcm.json'),true,512,JSON_THROW_ON_ERROR);
        $graph['1']['inputs']['ckpt_name']=$checkpoint;
        $graph['5']['inputs']['text']=$prompt.', no text, no captions, no watermark';
        $graph['8']['inputs']['seed']=$seed;
        $graph['10']['inputs']['filename_prefix']=$tag;
        return $graph;
    }

    public function run(array $job): void {
        if($job['mode']!=='preview'||($job['data']['adapter']??'')!=='animatelcm'){throw new \InvalidArgumentException('Motion tests must use non-publishing preview jobs.');}
        $id=(int)$job['id'];$data=$job['data'];$dir=storage('jobs/'.$id);
        if(!is_dir($dir)){mkdir($dir,0700,true);}
        $ffmpeg=Process::binary('ffmpeg');$ffprobe=Process::binary('ffprobe');
        if(!$ffmpeg||!$ffprobe){throw new \RuntimeException('Install FFmpeg first.');}
        db()->update($id,['status'=>'running','stage'=>'visuals','error'=>null]);
        if(!isset($data['frames'])){
            self::assertReady();
            if(empty($data['motion_prompt_id'])){
                if(!empty($data['motion_submitted'])){throw new \RuntimeException('The last ComfyUI submission was not confirmed. Inspect its queue/history before creating another motion test.');}
                // Release idle image/writer weights; do not interrupt another ComfyUI queue.
                try {
                    $base=rtrim(settings()->get('comfy_url'),'/');
                    $queue=Http::json('GET',$base.'/queue',null,[],3);
                    if(empty($queue['queue_running'])&&empty($queue['queue_pending'])){Http::json('POST',$base.'/free',['unload_models'=>true,'free_memory'=>true],[],10);}
                }catch(\Throwable $e){}
                try{Http::json('POST',rtrim(settings()->get('ollama_url'),'/').'/api/generate',['model'=>settings()->get('ollama_model'),'keep_alive'=>0],[],10);}catch(\Throwable $e){}
                $tag='fact_motion_'.$id.'_'.bin2hex(random_bytes(5));
                $graph=self::workflow($data['prompt'],settings()->get('comfy_checkpoint'),$data['seed'],$tag);
                file_put_contents($dir.'/motion-workflow.json',json_encode($graph,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
                $data['motion_spec']=['width'=>$graph['7']['inputs']['width'],'height'=>$graph['7']['inputs']['height'],'frames'=>$graph['7']['inputs']['batch_size']];
                $data['motion_submitted']=true;db()->update($id,['data'=>$data]);
                $submitted=Http::request('POST',self::BASE.'/prompt',json_encode(['prompt'=>$graph,'client_id'=>$tag],JSON_THROW_ON_ERROR),['Content-Type: application/json'],30,4000000,[400]);
                $response=json_decode($submitted['body'],true,512,JSON_THROW_ON_ERROR);
                if($submitted['status']===400){unset($data['motion_submitted']);db()->update($id,['data'=>$data]);throw new \RuntimeException('ComfyUI rejected the motion workflow: '.json_encode($response['node_errors']??$response['error']??[]));}
                if(empty($response['prompt_id'])){throw new \RuntimeException('ComfyUI did not confirm the motion request. Inspect its queue before submitting again.');}
                $data['motion_prompt_id']=$response['prompt_id'];db()->update($id,['data'=>$data]);
                db()->event($id,'Temporal sampling started: 16 frames together, '.$graph['8']['inputs']['steps'].' steps. First run also loads the models.');
            }
            $started=time();$frames=null;
            while(time()-$started<3600){
                $history=Http::json('GET',self::BASE.'/history/'.rawurlencode($data['motion_prompt_id']),null,[],15);
                $entry=$history[$data['motion_prompt_id']]??null;
                if($entry){
                    if(($entry['status']['status_str']??'')==='error'){
                        $detail='Check storage/logs/animation.err.log.';
                        foreach($entry['status']['messages']??[]as$message){if(($message[0]??'')==='execution_error'){$detail=mb_substr((string)($message[1]['exception_message']??$detail),0,500);}}
                        throw new \RuntimeException('Motion generation failed: '.$detail);
                    }
                    $frames=$entry['outputs']['10']['images']??null;
                    if($frames){break;}
                }
                sleep(3);
            }
            if(!$frames){throw new \RuntimeException('The motion test exceeded one hour. It may still be running in ComfyUI; retry checks the same request.');}
            $spec=$data['motion_spec']??['width'=>320,'height'=>576,'frames'=>16];
            if(count($frames)!==$spec['frames']){throw new \RuntimeException('The motion model did not return all generated frames.');}
            $data['frames']=[];
            foreach($frames as$i=>$frame){
                $response=Http::request('GET',self::BASE.'/view?'.http_build_query(['filename'=>$frame['filename'],'subfolder'=>$frame['subfolder']??'','type'=>$frame['type']??'output']),null,[],30,15000000);
                $size=@getimagesizefromstring($response['body']);
                if(!$size||$size[0]!==$spec['width']||$size[1]!==$spec['height']){throw new \RuntimeException('Invalid generated motion frame.');}
                $name=sprintf('motion-%03d.png',$i);file_put_contents($dir.'/'.$name,$response['body']);$data['frames'][]=$name;
            }
            copy($dir.'/'.$data['frames'][0],$dir.'/scene-0.png');$data['images']=['scene-0.png'];
            db()->update($id,['data'=>$data]);
        }
        db()->update($id,['stage'=>'render']);
        $spec=$data['motion_spec']??['width'=>320,'height'=>576,'frames'=>16];
        Process::run([$ffmpeg,'-hide_banner','-loglevel','error','-y','-framerate','8','-i','motion-%03d.png','-frames:v','16','-an','-c:v','libx264','-preset','veryfast','-crf','18','-pix_fmt','yuv420p','-movflags','+faststart','final.mp4'],$dir,120);
        $check=json_decode(Process::run([$ffprobe,'-v','error','-count_frames','-show_streams','-show_format','-of','json','final.mp4'],$dir,30),true,512,JSON_THROW_ON_ERROR);
        $stream=$check['streams'][0]??[];
        if(($stream['codec_name']??'')!=='h264'||($stream['width']??0)!==$spec['width']||($stream['height']??0)!==$spec['height']||(int)($stream['nb_read_frames']??0)!==16||abs((float)($check['format']['duration']??0)-2)>0.1){throw new \RuntimeException('Motion MP4 validation failed.');}
        $data['video']=['file'=>'final.mp4','duration'=>2,'bytes'=>filesize($dir.'/final.mp4'),'width'=>$spec['width'],'height'=>$spec['height'],'fps'=>8,'generated_frames'=>16];
        db()->update($id,['status'=>'ready','stage'=>'complete','data'=>$data,'error'=>null]);
        db()->event($id,'Motion MP4 ready: 16 generated frames, 2 seconds, no text, no publishing.');
        try{Http::json('POST',self::BASE.'/free',['unload_models'=>true,'free_memory'=>true],[],10);}catch(\Throwable $e){}
    }
}
