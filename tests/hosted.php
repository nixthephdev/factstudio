<?php
declare(strict_types=1);
$temporary=__DIR__.'/tmp/hosted-'.bin2hex(random_bytes(5));mkdir($temporary,0700,true);putenv('FACT_STORAGE='.$temporary.'/office');
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../hosted/vendor/autoload.php';
$app=require __DIR__.'/../hosted/bootstrap/app.php';
$app->useStoragePath($temporary.'/laravel');
foreach(['framework/cache/data','framework/sessions','framework/views','logs'] as $folder){mkdir($temporary.'/laravel/'.$folder,0700,true);}
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database=$temporary.'/control.sqlite';touch($database);
config(['database.connections.sqlite.database'=>$database,'app.url'=>'http://localhost','session.driver'=>'array',
    'studio.worker_token_hash'=>hash('sha256','test-worker-token')]);
Illuminate\Support\Facades\DB::purge();
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
$passed=0;
function check(bool $value,string $name): void {global $passed;if(!$value){throw new RuntimeException('FAIL: '.$name);}echo 'PASS: '.$name."\n";$passed++;}
function request(string $path,array $data=[],string $token='test-worker-token',array $files=[]){
    $server=['HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token];
    if(!$files){$server['CONTENT_TYPE']='application/json';}
    $request=Illuminate\Http\Request::create('http://localhost'.$path,'POST',$files?$data:[],[], $files,$server,$files?null:json_encode($data));
    return app(Illuminate\Contracts\Http\Kernel::class)->handle($request);
}
function decoded($response): array {return json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);}

$identity=str_repeat('a',32);$poll=['identity'=>$identity,'name'=>'Test office','control_enabled'=>true,
    'snapshot'=>['jobs'=>[],'settings'=>['niche'=>'Space','eleven_api_key'=>'must-not-be-stored']]];
check(request('/api/worker/poll',$poll,'invalid')->getStatusCode()===401,'Worker endpoints reject an invalid pairing token');
check(request('/api/worker/poll',$poll)->getStatusCode()===200,'Paired worker can report a heartbeat');
$row=Illuminate\Support\Facades\DB::table('workers')->first();
check(!isset(json_decode($row->snapshot,true)['settings']['eleven_api_key']),'The dashboard stores only allowed automation settings');
check(json_decode($row->snapshot,true)['settings']['niche']==='Space','Validated snapshots retain the automation settings needed by the dashboard');
$other=$poll;$other['identity']=str_repeat('b',32);
check(request('/api/worker/poll',$other)->getStatusCode()===409,'A second installation cannot take over the paired worker');
$state=Illuminate\Http\Request::create('http://localhost/state','GET',[],[],[],['HTTP_ACCEPT'=>'application/json']);
check(app(Illuminate\Contracts\Http\Kernel::class)->handle($state)->getStatusCode()===401,'Private dashboard state requires an admin session');

$id=Fact\db()->createJob('live');$directory=Fact\storage('jobs/'.$id);mkdir($directory,0700,true);
file_put_contents($directory.'/final.mp4',pack('N',24).'ftypisom'.pack('N',512).'isomiso2'.pack('N',8).'mdat'.str_repeat("\0",64));
Fact\settings()->set('youtube_enabled','1');Fact\settings()->set('youtube_channel','Test destination');
Fact\db()->update($id,['status'=>'ready','data'=>['video'=>['file'=>'final.mp4','duration'=>18.23],
    'script'=>Fact\Pipeline::demoScript(),'sources'=>[],'images'=>[]]]);
$fixture=Fact\db()->job($id);$review=$fixture;$review['data']['targets']=Fact\Review::targets();$fingerprint=Fact\Review::fingerprint($review);
$videoHash=hash_file('sha256',$directory.'/final.mp4');
$poll['snapshot']['jobs']=[['id'=>$id,'video_sha256'=>$videoHash]];
check(request('/api/worker/poll',$poll)->getStatusCode()===200,'Worker can report a finished video checksum');
$upload=$temporary.'/upload.mp4';copy($directory.'/final.mp4',$upload);
$response=request('/api/worker/upload',['identity'=>$identity,'job_id'=>$id,'sha256'=>$videoHash],'test-worker-token',
    ['video'=>new Illuminate\Http\UploadedFile($upload,'final.mp4','video/mp4',null,true)]);
check($response->getStatusCode()===200,'A matching MP4 can be uploaded into private hosted storage');
$url=decoded($response)['url'];
$media=app(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::create($url,'HEAD'));
check($media->getStatusCode()===200&&$media->headers->get('Content-Type')==='video/mp4','A signed MP4 link supports external platform retrieval');
$range=app(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::create($url,'GET',[],[],[],['HTTP_RANGE'=>'bytes=0-19']));
check($range->getStatusCode()===206&&$range->headers->get('Content-Length')==='20','Signed video previews support byte-range playback');
$tampered=preg_replace('/signature=[a-f0-9]+/','signature=invalid',$url);
check(app(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::create($tampered,'GET'))->getStatusCode()===403,'A tampered media signature is rejected');

$commandId='11111111-1111-4111-8111-111111111111';
Illuminate\Support\Facades\DB::table('commands')->insert(['id'=>$commandId,'worker_id'=>$row->id,'operation'=>'approve',
    'payload'=>json_encode(['id'=>$id,'fingerprint'=>$fingerprint]),'state'=>'queued','created_at'=>now(),'updated_at'=>now()]);
$commands=decoded(request('/api/worker/poll',$poll))['commands'];
check(count($commands)===1&&$commands[0]['operation']==='approve','A queued review request is delivered to the paired worker');
$hub=new Fact\Hub();$result=$hub->execute($commands[0]);
check($result['state']==='completed'&&Fact\db()->job($id)['status']==='queued','Matching remote approval queues the saved video locally');
$again=$hub->execute($commands[0]);
check($again===$result,'Replayed command returns the saved result without executing again');
check(request('/api/worker/ack',['identity'=>$identity,'id'=>$commandId]+$result)->getStatusCode()===200,'The worker can acknowledge a completed command');
check(decoded(request('/api/worker/poll',$poll))['commands']===[],'Acknowledged commands are not delivered again');
check((int)Fact\db()->query('SELECT COUNT(*) FROM speech_usage')->fetchColumn()===0,'Remote approval never requests new narration');
check((int)Fact\db()->query('SELECT COUNT(*) FROM publications')->fetchColumn()===0,'Approval tests perform no external publishing');
$expired='22222222-2222-4222-8222-222222222222';
Illuminate\Support\Facades\DB::table('commands')->insert(['id'=>$expired,'worker_id'=>$row->id,'operation'=>'create','payload'=>'{}','state'=>'queued','created_at'=>now()->subHours(2),'updated_at'=>now()->subHours(2)]);
request('/api/worker/poll',$poll);
check(Illuminate\Support\Facades\DB::table('commands')->where('id',$expired)->value('state')==='expired','Old unanswered commands expire instead of unexpectedly running later');
$uncertain='33333333-3333-4333-8333-333333333333';
Fact\db()->query('INSERT INTO hub_commands(id,state,result) VALUES(?,?,?)',[$uncertain,'started','']);
$interrupted=$hub->execute(['id'=>$uncertain,'operation'=>'create','payload'=>['mode'=>'live']]);
check($interrupted['state']==='failed','An interrupted command is not blindly repeated');
check((int)Fact\db()->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===1,'Replayed or interrupted requests create no duplicate jobs');
check(!Fact\Hub::validUrl('http://example.com')&&!Fact\Hub::validUrl('https://user@example.com')&&!Fact\Hub::validUrl('https://example.com/?token=x'),'Pairing rejects HTTP, embedded credentials and URL query strings');
echo "\n$passed hosted bridge checks passed. No production data or paid providers were changed.\n";
