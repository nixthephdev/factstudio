<?php
declare(strict_types=1);
putenv('FACT_STORAGE='.__DIR__.'/tmp/run-'.bin2hex(random_bytes(5)));
require __DIR__.'/../app/bootstrap.php';
use Fact\Pipeline;
use Fact\Publishers;
use Fact\Renderer;
use Fact\Research;
use Fact\Settings;
use Fact\Writer;
use function Fact\db;
use function Fact\settings;
use function Fact\storage;
$passed=0;
function check(bool $value,string $name): void {global $passed;if(!$value){throw new RuntimeException('FAIL: '.$name);}echo 'PASS: '.$name."\n";$passed++;}
function rejects(callable $fn,string $name): void {try{$fn();}catch(Throwable $e){check(true,$name);return;}check(false,$name);}

$s=settings();$s->set('eleven_api_key','test-secret-value');
check($s->get('eleven_api_key')==='test-secret-value','Credentials decrypt correctly');
$stored=db()->query('SELECT value FROM settings WHERE key=?',['eleven_api_key'])->fetchColumn();
check(strpos($stored,'test-secret-value')===false,'Credentials are encrypted at rest');
$public=$s->publicValues();check(!isset($public['eleven_api_key'])&&$public['eleven_api_key_set']===true,'Browser settings never expose saved secrets');
$s->save(['eleven_api_key'=>'']);check($s->get('eleven_api_key')==='test-secret-value','Blank password retains saved secret');
check($s->redact('Error: test-secret-value')==='Error: [redacted]','Provider failures redact credentials');
rejects(fn()=>$s->save(['ollama_url'=>'http://169.254.169.254:80']),'Local model endpoint rejects remote hosts');
rejects(fn()=>$s->save(['app_url'=>'http://evil.example']),'OAuth callback rejects remote hosts');
check(Research::nasaUrl('https://science.nasa.gov/story/')&&!Research::nasaUrl('https://nasa.gov.evil.example/story')&&!Research::nasaUrl('http://www.nasa.gov/')&&!Research::nasaUrl('https://www.nasa.gov:8080/'),'Research URLs reject spoofed hosts and ports');

$id=db()->createJob('live','test');$first=db()->claim();check((int)$first['id']===$id,'Queued job can be claimed');check(db()->claim()===null,'Running job cannot be claimed twice');
db()->reserveSpeech($id,200,300);rejects(fn()=>db()->reserveSpeech($id,101,300),'Speech reservations enforce daily character cap');
check((int)db()->query('SELECT SUM(characters) FROM speech_usage')->fetchColumn()===200,'Rejected speech reservation rolls back');
db()->createJob('live','','unique-day');rejects(fn()=>db()->createJob('live','','unique-day'),'Schedule slots cannot be queued twice');
$s->set('schedule_enabled','1');$s->set('publish_time','00:00');$s->set('daily_limit','3');$scheduled=(new Pipeline())->tick();check(is_int($scheduled),'Due schedule queues one video');check((new Pipeline())->tick()===null,'Repeated scheduler tick does not duplicate a video');

$source=['text'=>'Sunlight takes about eight minutes to reach Earth. Light travels through the vacuum of space.'];
$scene=['narration'=>'Sunlight takes about eight minutes to reach our world, crossing the space between us and the Sun.','visual_prompt'=>'An illustration of sunlight crossing space.','evidence'=>'Sunlight takes about eight minutes to reach Earth.'];
$draft=['title'=>'A small journey','description'=>'Original short explanation','scenes'=>[$scene,$scene,$scene,$scene]];
$valid=Writer::validate($draft,$source,40);check($valid['word_count']>25,'Source-backed script schema accepted');
$bad=$draft;$bad['scenes'][0]['evidence']='An invented fact with no source passage.';rejects(fn()=>Writer::validate($bad,$source,40),'Invented evidence is rejected before paid narration');
$bad=$draft;$bad['scenes']=[];rejects(fn()=>Writer::validate($bad,$source,40),'Empty scene list is rejected');
check(Renderer::assTime(61.23)==='0:01:01.23'&&Renderer::assTime(59.999)==='0:01:00.00','Subtitle timestamp rounding handles minute boundaries');
check(Renderer::captionText('{\\p1} surprise')==='(/p1) surprise','Subtitle formatting injection is escaped');
$ass=Renderer::captions('one two three four five six seven',null,7);check(substr_count($ass,'Dialogue:')===2,'Fallback captions cover narration in short phrases');
$alignment=['characters'=>['H','i',' '],'character_start_times_seconds'=>[0,.1,.2],'character_end_times_seconds'=>[.1,.2,.3]];
check(strpos(Renderer::captions('Hi',$alignment,1),'0:00:00.30')!==false,'ElevenLabs alignment controls subtitle timing');
$expires=time()+1000;$token=Publishers::mediaToken($id,$expires);check(Publishers::validMedia($id,$expires,$token),'Valid expiring media signature is accepted');
check(!Publishers::validMedia($id+1,$expires,$token)&&!Publishers::validMedia($id,time()-1,$token),'Media signature rejects altered job and expired link');
rejects(fn()=>(new Publishers())->publish(['mode'=>'demo'],storage()),'Demo videos cannot publish even if called directly');
rejects(fn()=>(new Publishers())->publish(['mode'=>'preview'],storage()),'AI previews cannot publish even if called directly');
$short=$draft;foreach($short['scenes']as&$row){$row['narration']='What is this?';}unset($row);rejects(fn()=>Writer::validate($short,$source,40),'Question-only teaser is rejected instead of treated as a complete script');
$graph=Fact\Visuals::workflow('Planet illustration','test.safetensors',42);check($graph['4']['inputs']['ckpt_name']==='test.safetensors'&&$graph['5']['inputs']['batch_size']===1,'ComfyUI workflow uses selected checkpoint and sequential image batches');
if(Fact\Process::binary('ffmpeg')&&Fact\Process::binary('ffprobe')){
    $preview=db()->createJob('preview');$s->set('auto_publish','1');$s->set('youtube_enabled','1');
    db()->update($preview,['data'=>['sources'=>[],'script'=>Pipeline::demoScript(),'images'=>['test.png'],'voice'=>['file'=>'test.wav'],'video'=>['file'=>'test.mp4']]]);
    (new Pipeline())->run(db()->job($preview));
    check(db()->job($preview)['status']==='ready'&&(int)db()->query('SELECT COUNT(*) FROM publications WHERE job_id=?',[$preview])->fetchColumn()===0,'AI preview ignores automatic publishing even with a destination enabled');
}
echo "\n$passed checks passed. No paid API calls were made.\n";
