<?php
declare(strict_types=1);
namespace Fact;

final class Publishers {
    private function saved(int $id,string $platform): array {
        $row=db()->query('SELECT * FROM publications WHERE job_id=? AND platform=?',[$id,$platform])->fetch();
        if(!$row){return ['state'=>'new','remote_id'=>null,'data'=>[]];}$row['data']=json_decode($row['data'],true)?:[];return $row;
    }
    private function save(int $id,string $platform,string $state,?string $remote,array $data): void {
        db()->query('INSERT INTO publications(job_id,platform,state,remote_id,data,updated_at) VALUES(?,?,?,?,?,?) ON CONFLICT(job_id,platform) DO UPDATE SET state=excluded.state,remote_id=excluded.remote_id,data=excluded.data,updated_at=excluded.updated_at',[$id,$platform,$state,$remote,json_encode($data,JSON_THROW_ON_ERROR),now()]);
    }
    public static function callback(): string { return rtrim(settings()->get('app_url'),'/').'/index.php?action=oauth_callback'; }
    public static function googleAccess(): string {
        $refresh=settings()->get('google_refresh_token');if($refresh===''){throw new \RuntimeException('Connect your YouTube channel in Connections.');}
        $r=Http::request('POST','https://oauth2.googleapis.com/token',['client_id'=>settings()->get('google_client_id'),'client_secret'=>settings()->get('google_client_secret'),'refresh_token'=>$refresh,'grant_type'=>'refresh_token']);
        $data=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);if(empty($data['access_token'])){throw new \RuntimeException('Google did not return an access token. Reconnect YouTube.');}return $data['access_token'];
    }
    public static function connectGoogle(string $code): void {
        $r=Http::request('POST','https://oauth2.googleapis.com/token',['client_id'=>settings()->get('google_client_id'),'client_secret'=>settings()->get('google_client_secret'),'code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>self::callback()]);
        $token=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
        if(empty($token['access_token'])||empty($token['refresh_token'])){throw new \RuntimeException('Google did not grant offline access. Reconnect and allow channel access.');}
        $channel=Http::json('GET','https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true',null,['Authorization: Bearer '.$token['access_token']]);
        if(empty($channel['items'][0]['id'])){throw new \RuntimeException('No YouTube channel was found for this Google account.');}
        settings()->set('google_refresh_token',$token['refresh_token']);settings()->set('youtube_channel',$channel['items'][0]['snippet']['title']);
    }
    public function publish(array $job,string $directory): array {
        if($job['mode']!=='live'){throw new \RuntimeException('Demo and preview videos cannot be published.');}
        $results=[];$targets=$job['data']['targets']??[];
        foreach($targets as$platform){
            $saved=$this->saved((int)$job['id'],$platform);
            if($saved['state']==='published'){$results[$platform]=$saved['data']['url']??'';continue;}
            db()->event((int)$job['id'],'Publishing to '.ucfirst($platform).'.');
            if($platform==='youtube'){$results[$platform]=$this->youtube($job,$directory,$saved);}
            elseif($platform==='facebook'){$results[$platform]=$this->facebook($job,$directory,$saved);}
            elseif($platform==='instagram'){$results[$platform]=$this->instagram($job,$saved);}
            else{throw new \RuntimeException('Unsupported publishing destination.');}
        }
        if(!$results){throw new \RuntimeException('Enable a publishing destination in Connections.');}return $results;
    }
    private function description(array $job): string {
        $text=$job['data']['script']['description']."\n\nVisuals are AI-generated illustrations.\nSources:\n";
        foreach($job['data']['sources']??[]as$source){$text.=$source['url']."\n";}return mb_substr($text,0,4500);
    }
    private function youtube(array $job,string $directory,array $saved): string {
        $id=(int)$job['id'];$token=self::googleAccess();$file=$directory.'/final.mp4';$size=filesize($file);$session=$saved['data']['session']??null;
        $auth=['Authorization: Bearer '.$token];$offset=0;$response=null;
        if($session){
            if(parse_url($session,PHP_URL_SCHEME)!=='https'||parse_url($session,PHP_URL_HOST)!=='www.googleapis.com'){throw new \RuntimeException('Invalid saved upload session.');}
            $response=Http::request('PUT',$session,'',array_merge($auth,['Content-Length: 0','Content-Range: bytes */'.$size]),45,4000000,[308]);
            if($response['status']===308){$range=$response['headers']['range']??'';if(preg_match('/bytes=0-([0-9]+)/',$range,$m)){$offset=(int)$m[1]+1;}}
        }else{
            $body=['snippet'=>['title'=>$job['data']['script']['title'],'description'=>$this->description($job),'categoryId'=>'28','defaultLanguage'=>'en'],'status'=>['privacyStatus'=>settings()->get('youtube_privacy'),'selfDeclaredMadeForKids'=>false,'containsSyntheticMedia'=>true]];
            $r=Http::request('POST','https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status',json_encode($body),array_merge($auth,['Content-Type: application/json; charset=UTF-8','X-Upload-Content-Length: '.$size,'X-Upload-Content-Type: video/mp4']));
            $session=$r['headers']['location']??null;if(!$session){throw new \RuntimeException('YouTube did not return an upload session.');}
            $this->save($id,'youtube','uploading',null,['session'=>$session]);
        }
        if(!$response||$response['status']===308){
            $handle=fopen($file,'rb');try{fseek($handle,$offset);while($offset<$size){$chunk=fread($handle,min(8*1024*1024,$size-$offset));if($chunk===false||$chunk===''){throw new \RuntimeException('Cannot read rendered video.');}$end=$offset+strlen($chunk)-1;
                $response=Http::request('PUT',$session,$chunk,array_merge($auth,['Content-Type: video/mp4','Content-Length: '.strlen($chunk),'Content-Range: bytes '.$offset.'-'.$end.'/'.$size]),180,4000000,[308]);$offset=$end+1;}
            }finally{fclose($handle);}
        }
        $result=json_decode($response['body'],true);$remote=$result['id']??null;
        if(!$remote){throw new \RuntimeException('YouTube upload is not confirmed. Retry to query the same upload session; do not create a replacement job.');}
        $url='https://www.youtube.com/watch?v='.rawurlencode($remote);$this->save($id,'youtube','published',$remote,['url'=>$url,'privacy'=>$result['status']['privacyStatus']??settings()->get('youtube_privacy')]);return $url;
    }
    private function graph(string $method,string $path,?array $data,string $token): array {
        $url='https://graph.facebook.com/'.settings()->get('graph_version').'/'.$path;
        if($method==='GET'&&$data){$url.='?'.http_build_query($data);$data=null;}
        $r=Http::request($method,$url,$data,['Authorization: Bearer '.$token],60);return json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
    }
    private function facebook(array $job,string $directory,array $saved): string {
        $id=(int)$job['id'];$token=settings()->get('facebook_token');$page=settings()->get('facebook_page_id');
        if($token===''||!ctype_digit($page)){throw new \RuntimeException('Add a Facebook Page ID and Page access token in Connections.');}
        $remote=$saved['remote_id'];$data=$saved['data'];
        if(!$remote){$r=$this->graph('POST',$page.'/video_reels',['upload_phase'=>'start'],$token);$remote=$r['video_id']??null;$data=['upload_url'=>$r['upload_url']??''];if(!$remote||!$data['upload_url']){throw new \RuntimeException('Facebook did not return an upload session.');}$this->save($id,'facebook','created',$remote,$data);}
        if(($saved['state']??'')!=='processing'){
            if(parse_url($data['upload_url'],PHP_URL_SCHEME)!=='https'||parse_url($data['upload_url'],PHP_URL_HOST)!=='rupload.facebook.com'){throw new \RuntimeException('Invalid Facebook upload URL.');}
            $file=$directory.'/final.mp4';$status=$this->graph('GET',$remote,['fields'=>'status'],$token);$transferred=(int)($status['status']['uploading_phase']['bytes_transferred']??$status['status']['uploading_phase']['bytes_transfered']??0);
            if(($status['status']['uploading_phase']['status']??'')!=='complete'){
                $body=file_get_contents($file,false,null,$transferred);Http::request('POST',$data['upload_url'],$body,['Authorization: OAuth '.$token,'offset: '.$transferred,'file_size: '.filesize($file),'Content-Type: application/octet-stream'],240);
            }
            $this->graph('POST',$page.'/video_reels',['video_id'=>$remote,'upload_phase'=>'finish','video_state'=>'PUBLISHED','description'=>$this->description($job),'title'=>$job['data']['script']['title']],$token);
            $this->save($id,'facebook','processing',$remote,$data);
        }
        for($i=0;$i<20;$i++){$r=$this->graph('GET',$remote,['fields'=>'status'],$token);$status=$r['status']??[];if(($status['publishing_phase']['status']??'')==='complete'){$url='https://www.facebook.com/reel/'.$remote;$this->save($id,'facebook','published',$remote,['url'=>$url]);return $url;}if(in_array($status['video_status']??'',['error','upload_failed','expired'],true)){throw new \RuntimeException('Facebook rejected the rendered video. Check Page publishing access and video specifications.');}sleep(3);}
        throw new \RuntimeException('Facebook is still processing. Retry this job to check the same upload without creating another Reel.');
    }
    public static function mediaToken(int $id,int $expires): string {
        $path=storage('media.key');if(!is_file($path)){file_put_contents($path,bin2hex(random_bytes(32)),LOCK_EX);@chmod($path,0600);}
        return hash_hmac('sha256',$id.':'.$expires,file_get_contents($path));
    }
    public static function validMedia(int $id,int $expires,string $token): bool {
        return $expires>=time()&&$expires<=time()+7200&&is_file(storage('media.key'))&&hash_equals(self::mediaToken($id,$expires),$token);
    }
    private function instagram(array $job,array $saved): string {
        $id=(int)$job['id'];$token=settings()->get('instagram_token');$account=settings()->get('instagram_id');$base=settings()->get('instagram_video_base');
        if($token===''||!ctype_digit($account)||parse_url($base,PHP_URL_SCHEME)!=='https'){throw new \RuntimeException('Instagram needs a professional account ID, a Facebook-login publishing token and a public HTTPS studio URL. See the setup guide.');}
        if($saved['state']==='publishing'){throw new \RuntimeException('Instagram publication was submitted but not confirmed. Check Instagram before retrying; this job will not submit a duplicate.');}
        $container=$saved['remote_id'];
        if(!$container){$expires=time()+7200;$videoUrl=rtrim($base,'/').'/index.php?'.http_build_query(['action'=>'media','id'=>$id,'kind'=>'video','expires'=>$expires,'token'=>self::mediaToken($id,$expires)]);$r=$this->graph('POST',$account.'/media',['media_type'=>'REELS','video_url'=>$videoUrl,'caption'=>$job['data']['script']['title']."\n\n".$this->description($job),'share_to_feed'=>'true'],$token);$container=$r['id']??null;if(!$container){throw new \RuntimeException('Instagram did not create a Reel container.');}$this->save($id,'instagram','processing',$container,[]);}
        for($i=0;$i<30;$i++){$r=$this->graph('GET',$container,['fields'=>'status_code,status'],$token);if(($r['status_code']??'')==='FINISHED'){break;}if(in_array($r['status_code']??'',['ERROR','EXPIRED'],true)){throw new \RuntimeException('Instagram rejected the video container. Check its URL and account access.');}sleep(3);}
        if(($r['status_code']??'')!=='FINISHED'){throw new \RuntimeException('Instagram is still preparing the video. Retry to check the existing container.');}
        $this->save($id,'instagram','publishing',$container,[]);
        $published=$this->graph('POST',$account.'/media_publish',['creation_id'=>$container],$token);$remote=$published['id']??null;
        if(!$remote){throw new \RuntimeException('Instagram publication was not confirmed. Check your account before retrying.');}
        $this->save($id,'instagram','published',$remote,[]);
        $url='https://www.instagram.com/';try{$info=$this->graph('GET',$remote,['fields'=>'permalink'],$token);$url=$info['permalink']??$url;}catch(\Throwable $e){}
        $this->save($id,'instagram','published',$remote,['url'=>$url]);return $url;
    }
}
