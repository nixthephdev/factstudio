<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';

use Fact\Health;
use Fact\Http;
use Fact\Process;
use Fact\Publishers;
use function Fact\db;
use function Fact\settings;
use function Fact\storage;
use function Fact\json;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; media-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
header('Cache-Control: no-store');

$action=(string)($_GET['action']??'');
$remote=$_SERVER['REMOTE_ADDR']??'';
$host=strtolower((string)parse_url('http://'.($_SERVER['HTTP_HOST']??''),PHP_URL_HOST));
$local=in_array($remote,['127.0.0.1','::1'],true)&&in_array($host,['localhost','127.0.0.1','[::1]','::1'],true);
if(!empty($_SERVER['HTTP_FORWARDED'])||!empty($_SERVER['HTTP_X_FORWARDED_FOR'])||!empty($_SERVER['HTTP_CF_CONNECTING_IP'])){$local=false;}
$signedMedia=$action==='media'&&($_GET['kind']??'')==='video'&&Publishers::validMedia((int)($_GET['id']??0),(int)($_GET['expires']??0),(string)($_GET['token']??''));
if(!$local&&!$signedMedia){http_response_code(403);exit('FACT Studio is local-only. Open it on localhost.');}

if($action==='media'){
    try{
        $id=(int)($_GET['id']??0);$job=db()->job($id);$kind=(string)($_GET['kind']??'');$dir=storage('jobs/'.$id.'/');
        if($kind==='video'){$path=$dir.'final.mp4';$mime='video/mp4';}
        elseif($kind==='poster'&&$local){$path=$dir.'scene-0.png';$mime='image/png';}
        elseif($kind==='captions'&&$local){$path=$dir.'captions.ass';$mime='text/plain; charset=utf-8';}
        else{throw new RuntimeException('Unknown media.');}
        if(!is_file($path)){throw new RuntimeException('Media is not ready yet.');}
        $size=filesize($path);$start=0;$end=$size-1;
        if(isset($_SERVER['HTTP_RANGE'])){
            if(!preg_match('/^bytes=(\d*)-(\d*)$/',$_SERVER['HTTP_RANGE'],$match)||($match[1]===''&&$match[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
            if($match[1]===''){$start=max(0,$size-(int)$match[2]);}else{$start=(int)$match[1];if($match[2]!==''){$end=min($end,(int)$match[2]);}}
            if($start>$end||$start>=$size){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
            http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
        }
        header('Content-Type: '.$mime);header('Accept-Ranges: bytes');header('Content-Length: '.($end-$start+1));
        if(isset($_GET['download'])&&$local){header('Content-Disposition: attachment; filename="fact-'.$id.'.'.($kind==='video'?'mp4':'ass').'"');}
        if($_SERVER['REQUEST_METHOD']==='HEAD'){exit;}
        $handle=fopen($path,'rb');fseek($handle,$start);$remaining=$end-$start+1;
        while($remaining>0&&!feof($handle)){$chunk=fread($handle,min(65536,$remaining));echo $chunk;$remaining-=strlen($chunk);if(connection_aborted()){break;}}fclose($handle);exit;
    }catch(Throwable $e){http_response_code(404);exit('Media not found.');}
}

session_name('FACTSTUDIO');
// Keep sessions with this app instead of relying on XAMPP's shared temp permissions.
session_save_path(storage('sessions'));
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','path'=>'/']);
session_start();
if(empty($_SESSION['csrf'])){$_SESSION['csrf']=bin2hex(random_bytes(32));}
$csrf=$_SESSION['csrf'];

if($action==='oauth_callback'){
    // Google redirects back from another site. SameSite=Lax is required for this callback.
    $state=(string)($_GET['state']??'');$saved=$_SESSION['oauth_state']??'';$expires=$_SESSION['oauth_expires']??0;
    unset($_SESSION['oauth_state'],$_SESSION['oauth_expires']);
    try{if($saved===''||!hash_equals($saved,$state)||$expires<time()){throw new RuntimeException('Google connection expired. Start the connection again.');}if(!empty($_GET['error'])){throw new RuntimeException('Google connection was not completed.');}Publishers::connectGoogle((string)($_GET['code']??''));$_SESSION['flash']='YouTube connected. Choose it as a destination when you are ready.';}
    catch(Throwable $e){$_SESSION['flash']=settings()->redact($e->getMessage());}
    header('Location: ./?view=connections');exit;
}

if($action==='api'){
    try{
        if($_SERVER['REQUEST_METHOD']==='GET'){
            if(($_GET['resource']??'')==='job'){$job=db()->job((int)($_GET['id']??0));$job['events']=db()->query('SELECT message,created_at FROM events WHERE job_id=? ORDER BY id DESC LIMIT 30',[$job['id']])->fetchAll();$job['publications']=db()->query('SELECT platform,state,remote_id FROM publications WHERE job_id=?',[$job['id']])->fetchAll();json(['job'=>$job]);}
            $usage=(int)db()->query('SELECT COALESCE(SUM(characters),0) FROM speech_usage WHERE day=?',[date('Y-m-d')])->fetchColumn();
            $health=Health::inspect(isset($_GET['refresh']));
            json(['jobs'=>db()->jobs(),'health'=>$health,'monitor'=>Fact\Monitor::inspect($health),'settings'=>settings()->publicValues(),'worker'=>Health::workerRunning(),'speech_used'=>$usage,'events'=>db()->query('SELECT * FROM events ORDER BY id DESC LIMIT 12')->fetchAll(),'server_time'=>date('c')]);
        }
        if($_SERVER['REQUEST_METHOD']!=='POST'){json(['error'=>'Method not allowed.'],405);}
        if(!hash_equals($csrf,(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))){json(['error'=>'Session expired. Reload the page.'],403);}
        $raw=file_get_contents('php://input',false,null,0,65537);if(strlen($raw)>65536){throw new RuntimeException('Request is too large.');}
        $input=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$operation=$input['operation']??'';
        if($operation==='create'){
            $mode=$input['mode']??'live';$topic=mb_substr(trim((string)($input['topic']??'')),0,300);
            if($mode==='live'&&(settings()->get('eleven_api_key')===''||settings()->get('eleven_voice')==='')){throw new RuntimeException('Connect your ElevenLabs key and voice in Connections first, or choose Free AI preview to make a video without paid credits.');}
            $pending=(int)db()->query("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','running','publishing')")->fetchColumn();if($pending>=3){throw new RuntimeException('There are already three active jobs. Let the queue finish first.');}
            if($mode==='live'){$today=(int)db()->query("SELECT COUNT(*) FROM jobs WHERE mode='live' AND substr(created_at,1,10)=?",[date('Y-m-d')])->fetchColumn();if($today>=(int)settings()->get('daily_limit')){throw new RuntimeException('Daily video limit reached. You can adjust the limit in Automation.');}}
            $id=$mode==='motion'?Fact\MotionVideo::create($topic):db()->createJob((string)$mode,$topic);session_write_close();Process::launchWorker();json(['id'=>$id]);
        }
        if($operation==='approve'){
            Fact\Review::approve((int)($input['id']??0));session_write_close();Process::launchWorker();json(['ok'=>true]);
        }
        if($operation==='retry'){
            $job=db()->job((int)($input['id']??0));if(!in_array($job['status'],['blocked','ready','failed'],true)){throw new RuntimeException('This job cannot be retried while it is active.');}
            if($job['status']==='ready'&&$job['mode']!=='live'){throw new RuntimeException('This preview is already finished.');}
            $updated=db()->query("UPDATE jobs SET status='queued',error=NULL,updated_at=? WHERE id=? AND status IN ('blocked','ready','failed')",[Fact\now(),(int)$job['id']]);if($updated->rowCount()!==1){throw new RuntimeException('This job already started. Refresh its status.');}db()->event((int)$job['id'],'Retry requested. Reusing saved scenes, narration and upload sessions.');session_write_close();Process::launchWorker();json(['ok'=>true]);
        }
        if($operation==='cancel'){$id=(int)($input['id']??0);$updated=db()->query("UPDATE jobs SET status='skipped',error=NULL,updated_at=? WHERE id=? AND status IN ('queued','blocked','ready')",[Fact\now(),$id]);if($updated->rowCount()!==1){throw new RuntimeException('Only queued, blocked or finished unpublished jobs can be skipped. This job may already have started.');}db()->event($id,'Video skipped. Its saved files remain in the library.');json(['ok'=>true]);}
        if($operation==='worker'){session_write_close();Process::launchWorker();json(['ok'=>true]);}
        if($operation==='settings'){settings()->save($input['values']??[]);if(is_file(storage('health.json'))){unlink(storage('health.json'));}json(['ok'=>true]);}
        if($operation==='clear_secret'){$key=(string)($input['key']??'');if(!in_array($key,Fact\Settings::SECRETS,true)){throw new RuntimeException('Unknown credential.');}settings()->set($key,'');json(['ok'=>true]);}
        if($operation==='voices'){$key=settings()->get('eleven_api_key');if($key===''){throw new RuntimeException('Save your ElevenLabs API key first.');}$r=Http::json('GET','https://api.elevenlabs.io/v1/voices',null,['xi-api-key: '.$key]);$voices=[];foreach($r['voices']??[]as$v){$voices[]=['id'=>$v['voice_id'],'name'=>$v['name']];}json(['voices'=>$voices]);}
        if($operation==='oauth'){
            if(settings()->get('google_client_id')===''||settings()->get('google_client_secret')===''){throw new RuntimeException('Save your Google OAuth client ID and secret first.');}
            $_SESSION['oauth_state']=bin2hex(random_bytes(32));$_SESSION['oauth_expires']=time()+600;
            json(['url'=>'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>settings()->get('google_client_id'),'redirect_uri'=>Publishers::callback(),'response_type'=>'code','scope'=>'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly','access_type'=>'offline','prompt'=>'consent','state'=>$_SESSION['oauth_state']])]);
        }
        json(['error'=>'Unknown action.'],404);
    }catch(Throwable $e){json(['error'=>settings()->redact($e->getMessage())],422);}
}

$flash=$_SESSION['flash']??'';unset($_SESSION['flash']);session_write_close();
require __DIR__.'/app/view.php';
