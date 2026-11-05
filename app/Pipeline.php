<?php
declare(strict_types=1);
namespace Fact;

final class Pipeline {
    private function stage(int $id,string $stage,string $message): void { db()->update($id,['stage'=>$stage]);db()->event($id,$message); }
    public function work(bool $once=true): bool {
        $handle=fopen(storage('worker.lock'),'c');if(!$handle||!flock($handle,LOCK_EX|LOCK_NB)){return false;}
        try{
            // The exclusive worker lock proves no previous worker is still processing these jobs.
            db()->query("UPDATE jobs SET status='blocked',error='Worker stopped unexpectedly. Retry to continue from saved progress.',updated_at=? WHERE status IN ('running','publishing')",[now()]);
            do{$job=db()->claim();if(!$job){break;}$this->run($job);}while(!$once);
        }finally{flock($handle,LOCK_UN);fclose($handle);}return true;
    }
    public function run(array $job): void {
        $id=(int)$job['id'];$data=$job['data'];$demo=$job['mode']==='demo';$preview=$job['mode']==='preview';$freeVoice=$demo||$preview;$directory=storage('jobs/'.$id);if(!is_dir($directory)){mkdir($directory,0700,true);}
        try{
            if(($data['adapter']??'')==='animatelcm'){(new MotionVideo())->run($job);return;}
            if(!Process::binary('ffmpeg')||!Process::binary('ffprobe')){throw new \RuntimeException('Install FFmpeg using scripts/install-ffmpeg.ps1, then retry.');}
            if(!$freeVoice&&!isset($data['voice'])&&(settings()->get('eleven_api_key')===''||settings()->get('eleven_voice')==='')){throw new \RuntimeException('Connect your ElevenLabs key and voice in Connections, or use Free AI preview.');}
            if($demo){
                if(!isset($data['script'])){$this->stage($id,'script','Preparing the sample story. Demo narration and artwork are not AI-generated.');$data['sources']=[];$data['script']=self::demoScript();$data['source_check']='sample';db()->update($id,['data'=>$data,'title'=>$data['script']['title']]);}
            }else{
                if(!isset($data['sources'])){
                    $this->stage($id,'research','Reading NASA sources and looking for an unused story.');$research=new Research();$candidates=$research->candidates();$competitors=$research->competitors();$chosen=(new Writer())->select($candidates,$job['topic'],$competitors);$data['sources']=[$research->article($chosen)];$data['competitors']=$competitors;db()->update($id,['data'=>$data]);
                }
                if(!isset($data['script'])){$this->stage($id,'script','Writing an original script with matching source passages. Up to three free local attempts.');$script=(new Writer())->produce($data['sources'][0],$job['topic']);$data['script']=$script;$data['source_check']='automated';db()->update($id,['data'=>$data,'title'=>$script['title']]);}
            }
            if(!isset($data['images'])){$this->stage($id,'visuals',$demo?'Drawing four sample scenes.':'Generating four original illustrations in ComfyUI.');$data['images']=(new Visuals())->generate($data['script']['scenes'],$directory,$demo);db()->update($id,['data'=>$data]);}
            if(!isset($data['voice'])){$this->stage($id,'voice',$freeVoice?'Recording free Windows preview narration.':'Generating ElevenLabs narration within your daily character limit.');$data['voice']=(new Voice())->generate($id,$data['script']['narration'],$directory,$freeVoice);db()->update($id,['data'=>$data]);}
            if(!isset($data['video'])){$this->stage($id,'render','Assembling scenes and narration into a vertical MP4 without text overlays.');$data['video']=(new Renderer())->render($directory,$data['script'],$data['voice'],$data['images']);db()->update($id,['data'=>$data]);}
            if($job['mode']==='live'&&settings()->get('auto_publish')==='1'){
                if(!isset($data['targets'])){$data['targets']=[];foreach(['youtube','facebook','instagram']as$platform){if(settings()->get($platform.'_enabled')==='1'){$data['targets'][]=$platform;}}db()->update($id,['data'=>$data]);}
                $this->stage($id,'publish','Sending the finished video to connected destinations.');db()->update($id,['status'=>'publishing']);$job=db()->job($id);$data['publications']=(new Publishers())->publish($job,$directory);db()->update($id,['status'=>'published','data'=>$data,'error'=>null]);db()->event($id,'All selected destinations confirmed the publication.');
            }else{db()->update($id,['status'=>'ready','stage'=>'complete','data'=>$data,'error'=>null]);db()->event($id,$freeVoice?'Preview MP4 is ready. It will never be published.':'MP4 is ready. Automatic publishing is currently off.');}
        }catch(\Throwable $e){$message=settings()->redact($e->getMessage());db()->update($id,['status'=>'blocked','error'=>$message]);db()->event($id,'Production paused: '.$message);}
    }
    public function tick(): ?int {
        $s=settings();if($s->get('schedule_enabled')!=='1'||date('H:i')<$s->get('publish_time')){return null;}
        // One scheduled slot per day. Additional manually queued jobs count toward the daily cap.
        $count=(int)db()->query("SELECT COUNT(*) FROM jobs WHERE mode='live' AND substr(created_at,1,10)=?",[date('Y-m-d')])->fetchColumn();
        if($count>=(int)$s->get('daily_limit')){return null;}
        $key='daily:'.date('Y-m-d');try{return db()->createJob('live','',$key);}catch(\PDOException $e){if(strpos($e->getMessage(),'UNIQUE constraint failed')!==false){return null;}throw $e;}
    }
    public static function demoScript(): array {
        $scenes=[
            ['narration'=>'Look up at the Sun, and you are seeing light that began its journey about eight minutes ago.','visual_prompt'=>'Sample star illustration'],
            ['narration'=>'That light travels across the space between the Sun and Earth before it reaches us.','visual_prompt'=>'Sample Earth illustration'],
            ['narration'=>'Even the nearest stars beyond our Sun are so far away that their light takes years to arrive.','visual_prompt'=>'Sample distant star illustration'],
            ['narration'=>'The night sky is a view into the past. Every point of light has a journey behind it.','visual_prompt'=>'Sample night sky illustration']
        ];
        return ['title'=>'A little closer to the stars','description'=>'An example film for testing your local production pipeline.','scenes'=>$scenes,'narration'=>implode(' ',array_column($scenes,'narration')),'word_count'=>82];
    }
}
