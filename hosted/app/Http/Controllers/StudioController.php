<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
class StudioController {
    public function state(){
        $worker=DB::table('workers')->first();
        $snapshot=$worker?json_decode($worker->snapshot,true):null;
        $videos=$worker?DB::table('videos')->where('worker_id',$worker->id)->get()->keyBy('job_id'):collect();
        if($snapshot){$snapshot['jobs']=array_map(function($job)use($videos){$video=$videos->get($job['id']);$job['media_url']=$video&&hash_equals($video->sha256,(string)($job['video_sha256']??''))?URL::temporarySignedRoute('video',now()->addHours(12),['video'=>$video->id]):null;return $job;},$snapshot['jobs']??[]);}
        return response()->json(['worker'=>$worker?['name'=>$worker->name,'last_seen_at'=>\Illuminate\Support\Carbon::parse($worker->last_seen_at)->toIso8601String(),
            'online'=>strtotime($worker->last_seen_at)>time()-90,'control_enabled'=>(bool)$worker->control_enabled]:null,
            'snapshot'=>$snapshot,'commands'=>$worker?DB::table('commands')->where('worker_id',$worker->id)->latest()->limit(20)->get():[]]);
    }
    public function command(Request $request){
        $input=$request->validate(['request_id'=>'required|uuid','operation'=>'required|in:create,approve,retry,cancel,settings','payload'=>'present|array']);
        $existing=DB::table('commands')->where('id',$input['request_id'])->first();if($existing){return response()->json(['id'=>$existing->id]);}
        $worker=DB::table('workers')->first();
        abort_unless($worker&&$worker->control_enabled&&strtotime($worker->last_seen_at)>time()-90,422,'Connect the office production worker before sending commands.');
        $payload=$input['payload'];
        if($input['operation']==='create'){$payload=validator($payload,['mode'=>'required|in:preview,live','topic'=>'nullable|string|max:300'])->validate();}
        elseif($input['operation']==='settings'){
            $allowed=['niche','duration','daily_limit','publish_time','schedule_enabled','auto_publish','review_required','speech_limit'];
            $payload=['values'=>array_intersect_key((array)($payload['values']??[]),array_flip($allowed))];
            abort_unless($payload['values'],422,'Choose an automation setting.');
        }else{
            $payload=validator($payload,['id'=>'required|integer|min:1','fingerprint'=>'required|string|size:64'])->validate();
        }
        DB::table('commands')->insert(['id'=>$input['request_id'],'worker_id'=>$worker->id,'operation'=>$input['operation'],
            'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'state'=>'queued','created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['id'=>$input['request_id']],201);
    }
    public function video(int $video){
        $record=DB::table('videos')->find($video);abort_unless($record,404);
        return response()->file(storage_path('app/private/'.$record->path),['Content-Type'=>'video/mp4','Cache-Control'=>'private, no-store','Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff']);
    }
}
