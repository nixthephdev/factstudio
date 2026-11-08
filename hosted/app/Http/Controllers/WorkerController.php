<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
class WorkerController {
    private function worker(Request $request){
        $identity=$request->input('identity');abort_unless(is_string($identity)&&preg_match('/^[a-f0-9]{32}$/',$identity),422,'Invalid worker identity.');
        $worker=DB::table('workers')->first();
        abort_unless($worker&&hash_equals($worker->identity,$identity),409,'This dashboard is paired to a different installation.');return $worker;
    }
    public function poll(Request $request){
        $input=$request->validate(['identity'=>'required|string|regex:/^[a-f0-9]{32}$/','name'=>'required|string|max:80',
            'control_enabled'=>'required|boolean','snapshot'=>'required|array','snapshot.jobs'=>'present|array|max:60',
            'snapshot.settings'=>'sometimes|array','snapshot.health'=>'sometimes|array','snapshot.monitor'=>'sometimes|array',
            'snapshot.speech_used'=>'sometimes|integer|min:0','snapshot.worker'=>'sometimes|boolean','snapshot.server_time'=>'sometimes|string']);
        DB::transaction(function()use($input){
            $worker=DB::table('workers')->first();
            abort_if($worker&&!hash_equals($worker->identity,$input['identity']),409,'This dashboard is already paired to another installation.');
            $snapshot=array_intersect_key($input['snapshot'],array_flip(['jobs','health','monitor','settings','worker','speech_used','server_time']));
            $snapshot['settings']=array_intersect_key($snapshot['settings']??[],array_flip(['niche','duration','daily_limit','publish_time','schedule_enabled','auto_publish','review_required','speech_limit']));
            $values=['name'=>$input['name'],'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'control_enabled'=>$input['control_enabled'],'last_seen_at'=>now()];
            if($worker){DB::table('workers')->where('id',$worker->id)->update($values);}else{DB::table('workers')->insert(['identity'=>$input['identity']]+$values);}
        });
        $worker=$this->worker($request);
        DB::table('commands')->where('worker_id',$worker->id)->whereIn('state',['queued','sent'])->where('created_at','<',now()->subMinutes(30))
            ->update(['state'=>'expired','result'=>'The worker did not complete this command within 30 minutes. Check its current state before sending another command.','updated_at'=>now()]);
        $commands=$input['control_enabled']?DB::table('commands')->where('worker_id',$worker->id)->whereIn('state',['queued','sent'])->oldest()->limit(10)->get():collect();
        foreach($commands as $command){DB::table('commands')->where('id',$command->id)->update(['state'=>'sent','updated_at'=>now()]);$command->payload=json_decode($command->payload,true);}
        $media=[];foreach(DB::table('videos')->where('worker_id',$worker->id)->get() as $video){$media[$video->job_id]=['sha256'=>$video->sha256,'url'=>URL::temporarySignedRoute('video',now()->addHours(12),['video'=>$video->id])];}
        return response()->json(['commands'=>$commands,'media'=>(object)$media]);
    }
    public function acknowledge(Request $request){
        $worker=$this->worker($request);
        $input=$request->validate(['id'=>'required|uuid','state'=>'required|in:completed,failed','result'=>'required|string|max:1500']);
        $command=DB::table('commands')->where('id',$input['id'])->where('worker_id',$worker->id)->first();abort_unless($command,404);
        if(in_array($command->state,['queued','sent'],true)){DB::table('commands')->where('id',$command->id)->update(['state'=>$input['state'],'result'=>$input['result'],'updated_at'=>now()]);}
        return response()->json(['ok'=>true]);
    }
    public function upload(Request $request){
        $worker=$this->worker($request);
        $input=$request->validate(['job_id'=>'required|integer|min:1','sha256'=>'required|string|regex:/^[a-f0-9]{64}$/',
            'video'=>'required|file|mimetypes:video/mp4,application/octet-stream|max:65536']);
        $snapshot=json_decode($worker->snapshot,true);$job=collect($snapshot['jobs']??[])->firstWhere('id',(int)$input['job_id']);
        abort_unless($job&&hash_equals((string)($job['video_sha256']??''),$input['sha256']),422,'The video must match the current worker snapshot.');
        $upload=$request->file('video');abort_unless(hash_equals($input['sha256'],hash_file('sha256',$upload->getRealPath())),422,'The upload checksum did not match.');
        $relative='videos/'.$worker->id.'/'.$input['job_id'].'-'.$input['sha256'].'.mp4';$target=storage_path('app/private/'.dirname($relative));
        if(!is_dir($target)){mkdir($target,0700,true);}$upload->move($target,basename($relative));
        DB::table('videos')->updateOrInsert(['worker_id'=>$worker->id,'job_id'=>$input['job_id']],['sha256'=>$input['sha256'],'path'=>$relative,'updated_at'=>now(),'created_at'=>now()]);
        $record=DB::table('videos')->where('worker_id',$worker->id)->where('job_id',$input['job_id'])->first();
        return response()->json(['ok'=>true,'url'=>URL::temporarySignedRoute('video',now()->addHours(12),['video'=>$record->id])]);
    }
}
