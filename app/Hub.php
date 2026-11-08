<?php
declare(strict_types=1);
namespace Fact;

final class Hub {
    public static function validUrl(string $url): bool {
        $parts=parse_url($url);
        return is_array($parts)&&($parts['scheme']??'')==='https'&&!empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['query'])&&!isset($parts['fragment']);
    }
    public static function identity(): string {
        $handle=fopen(storage('hub-identity'),'c+b');if(!$handle||!flock($handle,LOCK_EX)){throw new \RuntimeException('Cannot read the office worker identity.');}
        try{$identity=stream_get_contents($handle);if($identity===''){$identity=bin2hex(random_bytes(16));fwrite($handle,$identity);fflush($handle);}if(!preg_match('/^[a-f0-9]{32}$/',$identity)){throw new \RuntimeException('The office worker identity is damaged.');}return $identity;}
        finally{flock($handle,LOCK_UN);fclose($handle);}
    }
    public static function commandFingerprint(array $job): string {
        return hash('sha256',json_encode([$job['id'],$job['mode'],$job['created_at'],$job['topic']],JSON_THROW_ON_ERROR));
    }
    public static function snapshot(): array {
        $health=Health::inspect();$jobs=[];
        foreach(db()->jobs() as $summary){
            $job=db()->job((int)$summary['id']);$data=$job['data'];$file=storage('jobs/'.$job['id'].'/final.mp4');
            $hash=!empty($data['video'])&&is_file($file)?hash_file('sha256',$file):null;
            $fingerprint=null;
            if($job['mode']==='live'&&in_array($job['status'],['ready','blocked'],true)&&$hash){
                try{$reviewJob=$job;$reviewJob['data']['targets']=Review::targets();$fingerprint=Review::fingerprint($reviewJob);}catch(\Throwable $e){}
            }
            $jobs[]=['id'=>(int)$job['id'],'mode'=>$job['mode'],'status'=>$job['status'],'stage'=>$job['stage'],'title'=>$job['title'],
                'error'=>$job['error'],'created_at'=>$job['created_at'],'updated_at'=>$job['updated_at'],
                'data'=>['video'=>$data['video']??null],'narration'=>$data['script']['narration']??'',
                'sources'=>array_map(fn($source)=>['title'=>$source['title']??'Source','url'=>$source['url']??''],$data['sources']??[]),
                'command_fingerprint'=>self::commandFingerprint($job),'review_fingerprint'=>$fingerprint,'video_sha256'=>$hash,
                'publication_started'=>(int)db()->query('SELECT COUNT(*) FROM publications WHERE job_id=?',[$job['id']])->fetchColumn()>0];
        }
        $keys=['niche','duration','daily_limit','publish_time','schedule_enabled','auto_publish','review_required','speech_limit'];$values=[];
        foreach($keys as $key){$values[$key]=settings()->get($key);}
        return ['jobs'=>$jobs,'health'=>$health,'monitor'=>Monitor::inspect($health),'settings'=>$values,'worker'=>Health::workerRunning(),
            'speech_used'=>(int)db()->query('SELECT COALESCE(SUM(characters),0) FROM speech_usage WHERE day=?',[date('Y-m-d')])->fetchColumn(),'server_time'=>now()];
    }
    private function request(string $path,array $payload,?string $file=null): array {
        $base=rtrim(settings()->get('remote_hub_url'),'/');$token=settings()->get('remote_hub_token');
        $localTest=getenv('FACT_HUB_ALLOW_HTTP')==='1'&&preg_match('~^http://127\.0\.0\.1:[0-9]+$~',$base);
        if((!self::validUrl($base)&&!$localTest)||$token===''){throw new \RuntimeException('Pair this office studio with the hosted dashboard first.');}
        $headers=['Authorization: Bearer '.$token,'Accept: application/json'];
        if($file!==null){$payload['video']=new \CURLFile($file,'video/mp4','final.mp4');$body=$payload;}
        else{$headers[]='Content-Type: application/json';$body=json_encode($payload,JSON_THROW_ON_ERROR);}
        $ch=curl_init($base.'/api/worker/'.$path);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>$file?180:35,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS]);
        $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
        if($response===false){throw new \RuntimeException('Dashboard connection failed: '.$error);}
        if($status<200||$status>=300){throw new \RuntimeException('Dashboard request failed (HTTP '.$status.'). Check the address, pairing and server logs.');}
        return json_decode($response,true,512,JSON_THROW_ON_ERROR);
    }
    public function execute(array $command): array {
        $id=(string)($command['id']??'');
        if(!preg_match('/^[a-f0-9-]{36}$/i',$id)){throw new \RuntimeException('Invalid dashboard command ID.');}
        db()->query("CREATE TABLE IF NOT EXISTS hub_commands (id TEXT PRIMARY KEY,state TEXT NOT NULL,result TEXT NOT NULL)");
        $saved=db()->query('SELECT * FROM hub_commands WHERE id=?',[$id])->fetch();
        if($saved){
            if($saved['state']==='started'){$saved=['state'=>'failed','result'=>'The worker stopped during this request. Inspect the current library before sending another request.'];db()->query('UPDATE hub_commands SET state=?,result=? WHERE id=?',[$saved['state'],$saved['result'],$id]);}
            return ['state'=>$saved['state'],'result'=>$saved['result']];
        }
        db()->query('INSERT INTO hub_commands(id,state,result) VALUES(?,?,?)',[$id,'started','']);
        try{
            $operation=$command['operation']??'';$payload=$command['payload']??[];
            if($operation==='create'){
                $mode=$payload['mode']??'';if(!in_array($mode,['live','preview'],true)){throw new \RuntimeException('Only an original or AI preview can be requested from the dashboard.');}
                $health=Health::inspect(true);
                foreach($mode==='live'?['ffmpeg','ollama','comfy','elevenlabs','disk']:['ffmpeg','ollama','comfy','disk'] as $key){if(empty($health[$key]['ready'])){throw new \RuntimeException($health[$key]['detail']??'Production service is unavailable.');}}
                $active=(int)db()->query("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','running','publishing')")->fetchColumn();if($active>=3){throw new \RuntimeException('The office already has three active jobs.');}
                $today=(int)db()->query("SELECT COUNT(*) FROM jobs WHERE mode='live' AND substr(created_at,1,10)=?",[date('Y-m-d')])->fetchColumn();if($mode==='live'&&$today>=(int)settings()->get('daily_limit')){throw new \RuntimeException('Daily video limit reached.');}
                $created=db()->createJob($mode,mb_substr(trim((string)($payload['topic']??'')),0,300));$message='Video '.$created.' queued on the office worker.';
            }elseif($operation==='settings'){
                $allowed=['niche','duration','daily_limit','publish_time','schedule_enabled','auto_publish','review_required','speech_limit'];
                settings()->save(array_intersect_key((array)($payload['values']??[]),array_flip($allowed)));$message='Office automation settings saved.';
            }else{
                $job=db()->job((int)($payload['id']??0));$jobId=(int)$job['id'];
                if($operation==='approve'){Review::approve($jobId,(string)($payload['fingerprint']??''));$message='Video '.$jobId.' approved for publishing.';}
                else{
                    if(!hash_equals(self::commandFingerprint($job),(string)($payload['fingerprint']??''))){throw new \RuntimeException('This video does not match the dashboard request. Refresh the library.');}
                    if($operation==='retry'){
                        $updated=db()->query("UPDATE jobs SET status='queued',error=NULL,updated_at=? WHERE id=? AND status IN ('blocked','failed')",[now(),$jobId]);
                        if($updated->rowCount()!==1){throw new \RuntimeException('Only paused jobs can be retried.');}$message='Video '.$jobId.' queued using saved progress.';
                    }elseif($operation==='cancel'){
                        $updated=db()->query("UPDATE jobs SET status='skipped',error=NULL,updated_at=? WHERE id=? AND status IN ('queued','ready','blocked')",[now(),$jobId]);
                        if($updated->rowCount()!==1){throw new \RuntimeException('This video has already started or cannot be skipped.');}$message='Video '.$jobId.' skipped.';
                    }else{throw new \RuntimeException('Unsupported dashboard command.');}
                    db()->event($jobId,$message);
                }
            }
            $result=['state'=>'completed','result'=>$message];
        }catch(\Throwable $e){$result=['state'=>'failed','result'=>settings()->redact($e->getMessage())];}
        db()->query('UPDATE hub_commands SET state=?,result=? WHERE id=?',[$result['state'],$result['result'],$id]);return $result;
    }
    public function sync(bool $control=false,bool $acceptCommands=true): void {
        $identity=self::identity();$snapshot=self::snapshot();
        $response=$this->request('poll',['identity'=>$identity,'name'=>gethostname()?:'Office studio','control_enabled'=>$control,'snapshot'=>$snapshot]);
        if($control&&$acceptCommands){foreach($response['commands']??[] as $command){$result=$this->execute($command);$this->request('ack',['identity'=>$identity,'id'=>$command['id']]+$result);}}
        foreach($snapshot['jobs'] as $job){
            if(!$job['video_sha256']){continue;}$remote=$response['media'][$job['id']]??null;
            if(($remote['sha256']??null)!==$job['video_sha256']){
                $uploaded=$this->request('upload',['identity'=>$identity,'job_id'=>$job['id'],'sha256'=>$job['video_sha256']],storage('jobs/'.$job['id'].'/final.mp4'));
                $remote=['url'=>$uploaded['url']??''];
            }
            if($job['mode']==='live'){
                $url=(string)($remote['url']??'');$base=rtrim(settings()->get('remote_hub_url'),'/');
                if(strpos($url,$base.'/video/')!==0){continue;}
                $current=db()->job($job['id']);$data=$current['data'];parse_str((string)parse_url($data['hosted_media_url']??'',PHP_URL_QUERY),$query);
                if((int)($query['expires']??0)<time()+3600){db()->query("UPDATE jobs SET data=json_set(data,'$.hosted_media_url',?),updated_at=? WHERE id=?",[$url,now(),$job['id']]);}
            }
        }
    }
}
