<?php
declare(strict_types=1);
namespace Fact;

final class Monitor {
    public static function recordTick(): void {
        file_put_contents(storage('scheduler.json'),json_encode(['checked_at'=>now()],JSON_THROW_ON_ERROR),LOCK_EX);
    }

    public static function inspect(array $health): array {
        $db=db();$s=settings();$counts=[];
        foreach($db->query('SELECT status,COUNT(*) AS total FROM jobs GROUP BY status')->fetchAll() as $row){$counts[$row['status']]=(int)$row['total'];}
        $reviewCount=(int)$db->query("SELECT COUNT(*) FROM jobs WHERE mode='live' AND status='ready'")->fetchColumn();
        $tick=is_file(storage('scheduler.json'))?json_decode((string)file_get_contents(storage('scheduler.json')),true):null;
        $checked=$tick['checked_at']??null;$fresh=$checked!==null&&strtotime($checked)>=time()-900;
        $issues=[];
        foreach(['ffmpeg','ollama','comfy','elevenlabs','disk'] as $key){
            if(empty($health[$key]['ready'])){$issues[]=['kind'=>'setup','message'=>$health[$key]['detail']??'Check '.$key,'view'=>'connections'];}
        }
        $blocked=($counts['blocked']??0)+($counts['failed']??0);
        if($blocked){$issues[]=['kind'=>'blocked','message'=>$blocked.' video'.($blocked===1?' needs':'s need').' attention.','view'=>'library'];}
        if($reviewCount){$issues[]=['kind'=>'review','message'=>$reviewCount.' original video'.($reviewCount===1?' is':'s are').' ready for your review.','view'=>'library'];}
        if($s->get('schedule_enabled')==='1'&&!$fresh){$issues[]=['kind'=>'schedule','message'=>'No scheduler check in the last 15 minutes. Check the Windows task and keep this PC awake.','view'=>'automation'];}
        return ['counts'=>$counts,'total'=>array_sum($counts),'awaiting_review'=>$reviewCount,'issues'=>$issues,
            'scheduler'=>['enabled'=>$s->get('schedule_enabled')==='1','checked_at'=>$checked,'recent'=>$fresh],
            'review_required'=>$s->get('review_required')==='1','destinations'=>Review::destinations()];
    }
}
