<?php
declare(strict_types=1);
putenv('FACT_STORAGE='.__DIR__.'/tmp/automation-'.bin2hex(random_bytes(5)));
require __DIR__.'/../app/bootstrap.php';

use Fact\Monitor;
use Fact\Pipeline;
use Fact\Publishers;
use Fact\Review;
use Fact\Voice;
use function Fact\db;
use function Fact\settings;
use function Fact\storage;

$passed=0;
function check(bool $condition,string $name): void {
    global $passed;if(!$condition){throw new RuntimeException('FAIL: '.$name);}echo 'PASS: '.$name."\n";$passed++;
}
function rejects(callable $fn,string $name): void {
    try{$fn();}catch(Throwable $e){check(true,$name);return;}check(false,$name);
}
function finished(string $mode='live'): int {
    $id=db()->createJob($mode);$directory=storage('jobs/'.$id);mkdir($directory,0700,true);
    file_put_contents($directory.'/final.mp4','fixture video bytes');
    db()->update($id,['status'=>'ready','stage'=>'complete','data'=>['sources'=>[],
        'script'=>Pipeline::demoScript(),'images'=>['scene-0.png'],'voice'=>['file'=>'voice.mp3'],
        'video'=>['file'=>'final.mp4','duration'=>40]]]);
    return $id;
}

$s=settings();
check($s->get('review_required')==='1','New and existing studios require review by default');
$s->set('eleven_api_key','fake-test-key');$s->set('eleven_voice','fake-test-voice');
$s->set('auto_publish','1');$s->set('youtube_enabled','1');$s->set('youtube_channel','Test channel');
// Completed fixtures skip every generator. These placeholder paths satisfy only the renderer preflight.
$s->set('ffmpeg_path',__FILE__);$s->set('ffprobe_path',__FILE__);
$id=finished();(new Pipeline())->run(db()->job($id));$job=db()->job($id);
check($job['status']==='ready'&&$job['stage']==='review','Automatic publishing waits for review without calling a provider');
check($job['data']['review']['state']==='pending','Pending review is saved with the video');
rejects(fn()=>(new Publishers())->publish($job,storage('jobs/'.$id)),'Calling the publisher directly cannot bypass review');
check((int)db()->query('SELECT COUNT(*) FROM publications')->fetchColumn()===0,'Unapproved videos create no upload sessions');
Review::approve($id);$approved=db()->job($id);
check($approved['status']==='queued'&&$approved['data']['publish_requested']===true,'Approval queues publishing of the saved video');
check($approved['data']['targets']===['youtube'],'Approval saves the selected destinations');
Review::assertApproved($approved);check(true,'Unchanged video and destination pass approval validation');
rejects(fn()=>Review::approve($id),'Repeated approval cannot queue a second publish');
$s->set('youtube_privacy','private');
rejects(fn()=>Review::assertApproved($approved),'Changing YouTube visibility invalidates approval');
$s->set('youtube_privacy','public');
$s->set('google_refresh_token','different-account');
rejects(fn()=>Review::assertApproved($approved),'Changing connected account invalidates approval');
$s->set('google_refresh_token','');
file_put_contents(storage('jobs/'.$id.'/final.mp4'),'different finished video');
rejects(fn()=>Review::assertApproved($approved),'Replacing the MP4 invalidates approval');
(new Pipeline())->run(db()->job($id));
check(db()->job($id)['status']==='blocked','Changed approved content pauses before any upload');
Review::approve($id);Review::assertApproved(db()->job($id));check(true,'A changed video can be reviewed again before publishing starts');
$changed=db()->job($id);$changed['title']='A different publication title';
rejects(fn()=>Review::assertApproved($changed),'Changing the title invalidates approval');
$s->set('youtube_enabled','0');
rejects(fn()=>Review::assertApproved(db()->job($id)),'Disabling an approved destination pauses publication');
$withoutTarget=finished();
rejects(fn()=>Review::approve($withoutTarget),'Approval without a publishing destination is rejected');
check(db()->job($withoutTarget)['status']==='ready','Rejected approval leaves the video available for review');
$s->set('youtube_enabled','1');
$preview=finished('preview');$demo=finished('demo');
rejects(fn()=>Review::approve($preview),'AI previews cannot be approved for publishing');
rejects(fn()=>Review::approve($demo),'Sample films cannot be approved for publishing');
(new Pipeline())->run(db()->job($preview));
check(db()->job($preview)['status']==='ready'&&empty(db()->job($preview)['data']['review']),'Previews finish normally with review and automatic publishing enabled');
$partial=finished();db()->query('INSERT INTO publications(job_id,platform,state,updated_at) VALUES(?,?,?,?)',[$partial,'youtube','uploading',Fact\now()]);
rejects(fn()=>Review::approve($partial),'A partial upload cannot be redirected by approving again');

$speech=finished();db()->reserveSpeech($speech,100,2500);
rejects(fn()=>(new Voice())->generate($speech,'Do not send this.',storage('jobs/'.$speech),false),'A historical speech reservation prevents a second uncertain paid request');
check((int)db()->query('SELECT SUM(characters) FROM speech_usage WHERE job_id=?',[$speech])->fetchColumn()===100,'A blocked narration retry reserves no additional credits');
$speechMarker=finished();file_put_contents(storage('jobs/'.$speechMarker.'/speech-request.json'),'{}');
rejects(fn()=>(new Voice())->generate($speechMarker,'Do not send this.',storage('jobs/'.$speechMarker),false),'A saved uncertain request prevents another paid request');
file_put_contents(storage('jobs/'.$speech.'/voice.mp3'),'saved audio');
file_put_contents(storage('jobs/'.$speech.'/voice.json'),json_encode(['file'=>'voice.mp3','provider'=>'ElevenLabs']));
$voice=(new Voice())->generate($speech,'Existing narration',storage('jobs/'.$speech),false);
check($voice['file']==='voice.mp3','Saved narration remains reusable after a request reservation');

$health=[];foreach(['ffmpeg','ollama','comfy','elevenlabs','disk'] as $key){$health[$key]=['ready'=>true,'detail'=>$key];}
$s->set('schedule_enabled','1');
$monitor=Monitor::inspect($health);
check($monitor['scheduler']['recent']===false&&in_array('schedule',array_column($monitor['issues'],'kind'),true),'Monitoring flags an enabled scheduler that has not checked in');
Monitor::recordTick();$monitor=Monitor::inspect($health);
check($monitor['scheduler']['recent']===true,'A scheduler heartbeat clears the stale-check alert');
check($monitor['awaiting_review']>0&&$monitor['total']>0,'Monitoring counts reviewable videos and the full library');
$health['ollama']['ready']=false;$health['ollama']['detail']='Start the writer';
check(in_array('Start the writer',array_column(Monitor::inspect($health)['issues'],'message'),true),'Monitoring includes missing production services');
$s->save(['review_required'=>'0']);
check($s->get('review_required')==='0','Review mode can be explicitly disabled in Automation');
Review::assertApproved(db()->job($withoutTarget));check(true,'Disabling review allows an unapproved job through the review gate');
echo "\n$passed automation checks passed. No generation or publishing requests were sent.\n";
