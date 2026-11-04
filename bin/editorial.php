<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use Fact\Process;
use function Fact\db;
use function Fact\storage;

// A curated quality study. This command never publishes; paid narration is explicit.
$paid=in_array('--elevenlabs',$argv,true);
$requestedIds=array_values(array_filter(array_slice($argv,1),fn($value)=>ctype_digit($value)));
$lock=fopen(storage('worker.lock'),'c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { fwrite(STDERR,"Wait for the active worker to finish.\n");exit(1); }
$id=0;
try {
    $ffmpeg=Process::binary('ffmpeg');$ffprobe=Process::binary('ffprobe');
    if (!$ffmpeg || !$ffprobe) { throw new RuntimeException('FFmpeg and FFprobe are required.'); }
    $assets=storage('editorial-assets');
    foreach (['sun.mp4','earth.jpg'] as $asset) { if (!is_file($assets.'/'.$asset)) { throw new RuntimeException('Missing NASA asset: '.$asset); } }
    $id=$requestedIds?(int)$requestedIds[0]:db()->createJob($paid?'live':'demo','Sunlight: a message from the past');
    $job=db()->job($id);
    if ($job['mode']!==($paid?'live':'demo')) { throw new RuntimeException('Job mode does not match the selected narration provider.'); }
    $directory=storage('jobs/'.$id);if (!is_dir($directory)) { mkdir($directory,0700,true); }
    $scenes=[
        ['narration'=>'Sunlight is already eight minutes old when it reaches you.','title'=>['YOUR DAYLIGHT','IS ALREADY OLD.'],'number'=>'08:20','label'=>'A MESSAGE FROM THE PAST','caption'=>['Sunlight takes time','to reach you.'],'foot'=>'Approximate Sun-to-Earth light travel time','visual'=>'sun'],
        ['narration'=>'Our star is about ninety-three million miles away.','title'=>['AN INCREDIBLE','DISTANCE.'],'number'=>'93 MILLION','label'=>'BETWEEN OUR STAR AND HOME','caption'=>['About 93 million miles.','One way.'],'foot'=>'Average distance • NASA Science','visual'=>'earth'],
        ['narration'=>'Even light takes about eight minutes and twenty seconds to cross that distance.','title'=>['THE FASTEST','MESSAGE.'],'number'=>'8 MIN 20 SEC','label'=>'THE JOURNEY OF SUNLIGHT','caption'=>['Even light needs','time to cross space.'],'foot'=>'Diagram not to scale • approximate travel time','visual'=>'diagram'],
        ['narration'=>'So daylight is a message from the past.','title'=>['LOOK AT THE','WORLD DIFFERENTLY.'],'number'=>'PAST → NOW','label'=>'EVERY RAY HAS A JOURNEY','caption'=>['Daylight is a message','from the past.'],'foot'=>'NASA / Goddard SVS • Earth: NASA / ISS Expedition 13','visual'=>'earth'],
        ['narration'=>'You are living in the present, lit by the past.','title'=>['LIVING IN THE NOW.','LIT BY THE PAST.'],'number'=>'fact.','label'=>'STAY CURIOUS','caption'=>['Living in the present.','Lit by the past.'],'foot'=>'Solar imagery: NASA / Goddard SVS / SDO / VSO','visual'=>'sun'],
    ];
    if($paid){
        $scenes[0]=array_replace($scenes[0],['narration'=>'Daylight is old news. Literally.','title'=>['DAYLIGHT IS','OLD NEWS.'],'label'=>'WAIT. WHAT?','number'=>'OLD NEWS']);
        $scenes[1]=array_replace($scenes[1],['narration'=>'The Sun is ninety-three million miles away. So how long does its light take to reach you?','title'=>['HOW LONG DOES','THE JOURNEY TAKE?'],'label'=>'HERE IS THE QUESTION']);
        $scenes[2]=array_replace($scenes[2],['narration'=>'About eight minutes and twenty seconds.','title'=>['THERE IS','YOUR ANSWER.'],'label'=>'LIGHT IS FAST. SPACE IS VAST.']);
        $scenes[3]=array_replace($scenes[3],['narration'=>'The sunlight hitting your window left the Sun minutes ago.','title'=>['AT YOUR WINDOW.','FROM THE PAST.'],'label'=>'LET THAT SINK IN']);
        $scenes[4]=array_replace($scenes[4],['narration'=>"You're living in the present... lit by the past."]);
    }
    $sources=[['title'=>'NASA: All About the Sun','url'=>'https://spaceplace.nasa.gov/all-about-the-sun/en/'],['title'=>'NASA SVS: The Active Sun, 304 Angstroms','url'=>'https://svs.gsfc.nasa.gov/3983/'],['title'=>'NASA SVS: Origin of Light','url'=>'https://svs.gsfc.nasa.gov/11084/']];
    $narration=implode(' ',array_column($scenes,'narration'));
    $data=['sources'=>$sources,'production_note'=>'Curated NASA visuals and original editorial motion graphics with '.($paid?'ElevenLabs narration. Manually rendered; no publishing was requested.':'free Windows narration. This quality study cannot publish.'),'script'=>['title'=>$paid?'Daylight is old news. Literally.':'Your daylight is a message from the past','description'=>'A source-backed editorial quality study using credited NASA visuals.','narration'=>$narration,'scenes'=>$scenes]];
    db()->update($id,['status'=>'running','stage'=>'voice','title'=>$data['script']['title'],'data'=>$data,'error'=>null]);
    db()->event($id,$paid?'User requested ElevenLabs narration. Hook, curiosity, payoff and closing reframe. No publishing.':'Building a curated NASA editorial study. No paid generation or publishing.');
    $font='C:/Windows/Fonts/seguisb.ttf';$bold='C:/Windows/Fonts/segoeuib.ttf';
    if (!is_file($font) || !is_file($bold)) { throw new RuntimeException('Segoe UI fonts were not found.'); }
    $probe=function(string $file)use($ffprobe,$directory):array{return json_decode(Process::run([$ffprobe,'-v','error','-show_streams','-show_format','-of','json',$file],$directory,30),true,512,JSON_THROW_ON_ERROR);};
    $sceneAlignments=[];
    if($paid){
        $hash=hash('sha256',$narration);$hashFile=$directory.'/editorial-narration.sha256';
        if(is_file($directory.'/voice.json') && (!is_file($hashFile) || trim(file_get_contents($hashFile))!==$hash)){
            throw new RuntimeException('Saved paid narration belongs to a different script. Use a new job; existing audio is preserved.');
        }
        if(!is_file($directory.'/voice.json') && (int)db()->query('SELECT COUNT(*) FROM speech_usage WHERE job_id=?',[$id])->fetchColumn()>0){
            throw new RuntimeException('A paid narration request was already attempted for this job. Check the provider before authorizing another charge.');
        }
        file_put_contents($hashFile,$hash);
        echo 'Narration: '.mb_strlen($narration)." characters; one ElevenLabs request if not cached.\n";
        $voiceResult=(new Fact\Voice())->generate($id,$narration,$directory,false);
        $data['voice']=$voiceResult;db()->update($id,['data'=>$data]);
        $alignment=$voiceResult['original_alignment']??$voiceResult['alignment'];
        if(empty($alignment['characters']) || implode('',$alignment['characters'])!==$narration){
            throw new RuntimeException('Paid audio is saved, but exact character alignment is unavailable. Do not regenerate; adapt timing using the saved audio.');
        }
        $fullDuration=(float)$probe($directory.'/'.$voiceResult['file'])['format']['duration'];
        $offset=0;
        foreach($scenes as $i=>$scene){
            $length=mb_strlen($scene['narration']);$nextOffset=$offset+$length+1;
            $start=$i===0?0:max(0,(float)$alignment['character_start_times_seconds'][$offset]-.04);
            $end=$i===count($scenes)-1?$fullDuration:max($start+.1,(float)$alignment['character_start_times_seconds'][$nextOffset]-.04);
            $slice=['characters'=>array_slice($alignment['characters'],$offset,$length),'character_start_times_seconds'=>array_map(fn($t)=>max(0,(float)$t-$start),array_slice($alignment['character_start_times_seconds'],$offset,$length)),'character_end_times_seconds'=>array_map(fn($t)=>max(0,(float)$t-$start),array_slice($alignment['character_end_times_seconds'],$offset,$length))];
            $sceneAlignments[$i]=$slice;
            Process::run([$ffmpeg,'-hide_banner','-loglevel','error','-y','-ss',sprintf('%.4f',$start),'-i',$directory.'/'.$voiceResult['file'],'-t',sprintf('%.4f',$end-$start),'-ar','48000','-ac','1','editorial-voice-'.$i.'.wav'],$directory,60);
            $offset=$nextOffset;
        }
    }
    $segments=[];$total=0.0;
    foreach ($scenes as $i=>$scene) {
        echo 'Scene '.($i+1)." / ".count($scenes)."\n";
        $voice=$directory.'/editorial-voice-'.$i.'.wav';
        if (!is_file($voice)) {
            $text=$directory.'/editorial-text-'.$i.'.txt';file_put_contents($text,$scene['narration']);
            Process::run(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',Fact\ROOT.'/scripts/editorial-voice.ps1','-TextFile',$text,'-OutputFile',$voice],Fact\ROOT,90);
        }
        $duration=(float)$probe($voice)['format']['duration']+($paid?0:0.25);$total+=$duration;
        if($paid){file_put_contents($directory.'/editorial-captions-'.$i.'.ass',Fact\Renderer::captions($scene['narration'],$sceneAlignments[$i],$duration));}
        $canvas=imagecreatetruecolor(720,1280);imagesavealpha($canvas,true);imagealphablending($canvas,false);imagefill($canvas,0,0,imagecolorallocatealpha($canvas,0,0,0,127));imagealphablending($canvas,true);
        $white=imagecolorallocate($canvas,248,247,241);$muted=imagecolorallocate($canvas,157,169,181);$orange=imagecolorallocate($canvas,255,173,83);
        // Dark top and bottom gradients preserve both imagery and legible type.
        for($y=0;$y<1280;$y++) {
            $opacity=$y<400?0.9*(1-$y/470):($y>750?min(0.96,($y-750)/400):0);
            if($opacity>0){imageline($canvas,0,$y,719,$y,imagecolorallocatealpha($canvas,4,9,16,(int)(127*(1-$opacity))));}
        }
        if($scene['visual']==='diagram'){
            for($radius=110;$radius>60;$radius-=4){imagefilledellipse($canvas,90,537,$radius,$radius,imagecolorallocatealpha($canvas,255,162,63,123));}
            imagefilledellipse($canvas,90,537,60,60,$orange);
            imagefilledellipse($canvas,621,537,34,34,imagecolorallocate($canvas,84,184,226));
            imagettftext($canvas,16,0,72,600,$white,$bold,'SUN');
            imagettftext($canvas,16,0,587,600,$white,$bold,'EARTH');
        }
        imagettftext($canvas,15,0,42,60,$orange,$bold,'FACT / SPACE NOTES');
        imagettftext($canvas,13,0,606,60,$muted,$font,sprintf('%02d / 05',$i+1));
        imageline($canvas,42,83,678,83,imagecolorallocatealpha($canvas,255,255,255,100));
        imagettftext($canvas,13,0,43,128,$orange,$bold,$scene['label']);
        foreach($scene['title'] as $line=>$text){imagettftext($canvas,34,0,40,188+$line*51,$white,$bold,$text);}
        $size=$i===1?55:($i===2?48:($i===4?100:($paid?50:68)));
        imagettftext($canvas,$size,0,40,950,$orange,$bold,$scene['number']);
        if(!$paid){foreach($scene['caption'] as $line=>$text){imagettftext($canvas,31,0,42,1024+$line*43,$white,$bold,$text);}}
        $credit=$scene['foot'];imagettftext($canvas,10,0,42,1194,$muted,$font,$credit);
        imageline($canvas,42,1230,678,1230,imagecolorallocatealpha($canvas,255,255,255,100));
        imagefilledrectangle($canvas,42,1228,42+(int)(636*($i+1)/5),1232,$orange);
        $overlay=$directory.'/editorial-overlay-'.$i.'.png';imagepng($canvas,$overlay);imagedestroy($canvas);
        $output='editorial-segment-'.$i.'.mp4';
        $args=[$ffmpeg,'-hide_banner','-loglevel','error','-y'];
        if($scene['visual']==='sun'){
            array_push($args,'-ss',(string)($i===0?6:30),'-i',$assets.'/sun.mp4');
            $background="[0:v]scale=-2:1280,crop=720:1280:(iw-720)/2:0,setsar=1,eq=contrast=1.08:saturation=1.12[bg];";
        }elseif($scene['visual']==='earth'){
            array_push($args,'-loop','1','-framerate','30','-i',$assets.'/earth.jpg');
            $background="[0:v]scale=1152:2048:force_original_aspect_ratio=increase,crop=1152:2048,zoompan=z='1.08+0.00028*on':x='iw/2-iw/zoom/2':y='ih/2-ih/zoom/2':d=1:s=720x1280:fps=30,setsar=1,eq=contrast=1.04:saturation=0.95[bg];";
        }else{
            array_push($args,'-f','lavfi','-i','color=c=0x060c17:s=720x1280:r=30');
            $background="[0:v]drawgrid=w=60:h=60:t=1:c=0x172337@0.45,drawbox=x=80:y=535:w=560:h=2:c=0xffac53@0.4:t=fill[diagram];color=c=0xffde9e:s=18x8:r=30[photon];[diagram][photon]overlay=x='90+min(t*85,510)':y=532[bg];";
        }
        array_push($args,'-loop','1','-i',$overlay,'-i',$voice);
        $subtitleFilter=$paid?'ass=editorial-captions-'.$i.'.ass,':'';
        $filter=$background."[bg][1:v]overlay=0:0:shortest=1,".$subtitleFilter."fade=t=in:st=0:d=0.12,fade=t=out:st=".sprintf('%.3f',max(0,$duration-.15)).":d=0.15,format=yuv420p[v];[2:a]apad,atrim=duration=".sprintf('%.3f',$duration).",aresample=48000,aformat=channel_layouts=stereo[a]";
        array_push($args,'-filter_complex',$filter,'-map','[v]','-map','[a]','-t',sprintf('%.3f',$duration),'-r','30','-c:v','libx264','-preset','veryfast','-crf','20','-threads','4','-c:a','aac','-b:a','160k','-movflags','+faststart',$output);
        db()->update($id,['stage'=>'render']);Process::run($args,$directory,900);$segments[]="file '$output'";
    }
    file_put_contents($directory.'/editorial-segments.txt',implode("\n",$segments));
    Process::run([$ffmpeg,'-hide_banner','-loglevel','error','-y','-f','concat','-safe','0','-i','editorial-segments.txt','-f','lavfi','-i','aevalsrc=0.035*sin(2*PI*110*t)+0.018*sin(2*PI*164.81*t)+0.012*sin(2*PI*220*t):s=48000','-filter_complex','[0:a]loudnorm=I=-16:TP=-1.5:LRA=9[voice];[1:a]volume=0.16,afade=t=in:d=1,afade=t=out:st='.sprintf('%.3f',max(0,$total-2)).':d=2[bed];[voice][bed]amix=inputs=2:duration=first:normalize=0,alimiter=limit=0.95[a]','-map','0:v','-map','[a]','-c:v','copy','-c:a','aac','-b:a','192k','-ar','48000','-ac','2','-movflags','+faststart','final.mp4'],$directory,300);
    Process::run([$ffmpeg,'-hide_banner','-loglevel','error','-y','-ss','1.5','-i','final.mp4','-frames:v','1','scene-0.png'],$directory,30);
    $check=$probe($directory.'/final.mp4');$video=null;$audio=false;
    foreach($check['streams'] as $stream){if($stream['codec_type']==='video'){$video=$stream;}if($stream['codec_type']==='audio'){$audio=true;}}
    if(!$audio || ($video['width']??0)!==720 || ($video['height']??0)!==1280){throw new RuntimeException('Final media validation failed.');}
    $data['video']=['file'=>'final.mp4','duration'=>round((float)$check['format']['duration'],2),'bytes'=>filesize($directory.'/final.mp4'),'width'=>720,'height'=>1280];$data['images']=['scene-0.png'];
    file_put_contents($directory.'/credits.json',json_encode(['sources'=>$sources,'solar_credit'=>'NASA/Goddard Space Flight Center Scientific Visualization Studio, SDO Science Team, Virtual Solar Observatory','earth_credit'=>'NASA Goddard Conceptual Image Lab; Earth photo: NASA/ISS Expedition 13 crew','voice'=>$paid?'ElevenLabs; user-selected saved voice':'Microsoft Zira Desktop; free Windows synthesis','sound'=>'Original synthesized ambient tones','note'=>'Solar footage is archival false-color ultraviolet imagery, not a view in visible light. Earth scene is a NASA composite.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    db()->update($id,['status'=>'ready','stage'=>'complete','data'=>$data,'error'=>null]);db()->event($id,'Curated editorial MP4 ready. '.($paid?'ElevenLabs':'Windows').' narration; credited NASA footage/composite. No publishing.');
    echo "Ready: video $id, ".$data['video']['duration']." seconds.\n";
}catch(Throwable $e){if($id){db()->update($id,['status'=>'blocked','error'=>Fact\settings()->redact($e->getMessage())]);}fwrite(STDERR,Fact\settings()->redact($e->getMessage()).PHP_EOL);exit(1);
}finally{flock($lock,LOCK_UN);fclose($lock);}
