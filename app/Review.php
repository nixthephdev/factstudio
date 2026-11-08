<?php
declare(strict_types=1);
namespace Fact;

final class Review {
    public static function targets(): array {
        $targets=[];
        foreach(['youtube','facebook','instagram'] as $platform){
            if(settings()->get($platform.'_enabled')==='1'){$targets[]=$platform;}
        }
        return $targets;
    }

    public static function destinations(): array {
        $s=settings();$destinations=[];
        foreach(self::targets() as $platform){
            $label=$platform==='youtube'?($s->get('youtube_channel')?:'YouTube channel'):
                ($platform==='facebook'?'Facebook Page '.$s->get('facebook_page_id'):'Instagram account '.$s->get('instagram_id'));
            $destinations[]=['platform'=>$platform,'label'=>$label,'visibility'=>$platform==='youtube'?$s->get('youtube_privacy'):'public'];
        }
        return $destinations;
    }

    public static function fingerprint(array $job): string {
        if($job['mode']!=='live'||empty($job['data']['video'])||empty($job['data']['script'])){
            throw new \RuntimeException('Only a finished original video can be approved for publishing.');
        }
        $file=storage('jobs/'.(int)$job['id'].'/final.mp4');
        if(!is_file($file)||filesize($file)===0){throw new \RuntimeException('The finished MP4 is missing. Restore it before approving this video.');}
        $s=settings();$context=[];
        foreach($job['data']['targets']??[] as $platform){
            if(!in_array($platform,self::targets(),true)){throw new \RuntimeException('An approved destination was disabled. Review the destinations before publishing.');}
            $keys=['youtube'=>['google_client_id','youtube_channel','youtube_privacy','google_refresh_token'],
                'facebook'=>['facebook_page_id','facebook_token'], 'instagram'=>['instagram_id','instagram_token']][$platform]??null;
            if($keys===null){throw new \RuntimeException('Unsupported publishing destination.');}
            foreach($keys as $key){$context[$platform][$key]=hash('sha256',$s->get($key));}
        }
        if(!$context){throw new \RuntimeException('Enable at least one publishing destination in Connections.');}
        return hash('sha256',json_encode(['title'=>$job['title'],'script'=>$job['data']['script'],
            'sources'=>$job['data']['sources']??[],'video'=>hash_file('sha256',$file),'destinations'=>$context],JSON_THROW_ON_ERROR));
    }

    public static function approve(int $id,?string $expectedFingerprint=null): void {
        $db=db();$db->pdo->exec('BEGIN IMMEDIATE');
        try{
            $job=$db->job($id);
            if(!in_array($job['status'],['ready','blocked'],true)){throw new \RuntimeException('Only a finished video waiting in the library can be approved.');}
            if((int)$db->query('SELECT COUNT(*) FROM publications WHERE job_id=?',[$id])->fetchColumn()>0){
                throw new \RuntimeException('Publishing already started for this video. Retry it using the original destinations.');
            }
            $job['data']['targets']=self::targets();
            $fingerprint=self::fingerprint($job);
            if($expectedFingerprint!==null&&!hash_equals($fingerprint,$expectedFingerprint)){throw new \RuntimeException('The video or destinations changed after the dashboard preview. Refresh and review the current version.');}
            $job['data']['review']=['state'=>'approved','approved_at'=>now(),'fingerprint'=>$fingerprint];
            $job['data']['publish_requested']=true;
            $db->update($id,['status'=>'queued','stage'=>'publish','error'=>null,'data'=>$job['data']]);
            $db->event($id,'Video approved for publishing to '.implode(', ',$job['data']['targets']).'. Saved narration and video will be reused.');
            $db->pdo->exec('COMMIT');
        }catch(\Throwable $e){$db->pdo->exec('ROLLBACK');throw $e;}
    }

    public static function assertApproved(array $job): void {
        if($job['mode']!=='live'){throw new \RuntimeException('Demo and preview videos cannot be published.');}
        $review=$job['data']['review']??[];
        if(settings()->get('review_required')!=='1'&&($review['state']??'')!=='approved'){return;}
        if(($review['state']??'')!=='approved'||!hash_equals((string)($review['fingerprint']??''),self::fingerprint($job))){
            throw new \RuntimeException('This video needs review. Its content or publishing settings may have changed since approval.');
        }
    }
}
