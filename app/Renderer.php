<?php
declare(strict_types=1);
namespace Fact;

final class Renderer {
    public static function assTime(float $seconds): string {
        $ticks=(int)round(max(0,$seconds)*100);return sprintf('%d:%02d:%02d.%02d',intdiv($ticks,360000),intdiv($ticks,6000)%60,intdiv($ticks,100)%60,$ticks%100);
    }
    public static function captionText(string $text): string { return str_replace(['\\','{','}',"\n","\r"],['/','(',')',' ',' '],$text); }
    public static function captions(string $text,?array $alignment,float $duration): string {
        $header="[Script Info]\nScriptType: v4.00+\nPlayResX: 720\nPlayResY: 1280\nWrapStyle: 0\n\n[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\nStyle: Default,Segoe UI,43,&H00FFFFFF,&H000000FF,&H00130F0D,&H80000000,-1,0,0,0,100,100,0,0,1,3,1,2,55,55,230,1\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        $chunks=[];
        if($alignment&&!empty($alignment['characters'])){
            $chars=$alignment['characters'];$starts=$alignment['character_start_times_seconds']??[];$ends=$alignment['character_end_times_seconds']??[];
            $buffer='';$start=0.0;$end=0.0;$words=0;
            foreach($chars as$i=>$char){if($buffer===''){$start=(float)($starts[$i]??$end);}$buffer.=$char;$end=(float)($ends[$i]??$start+0.05);if(preg_match('/\s/u',$char)){$words++;}if(($words>=5&&preg_match('/\s/u',$char))||$i===count($chars)-1){$chunks[]=[trim($buffer),$start,min($duration,$end)];$buffer='';$words=0;}}
        }else{
            $words=preg_split('/\s+/u',trim($text));$groups=array_chunk($words,5);$offset=0;$total=count($words);
            foreach($groups as$group){$start=$duration*$offset/$total;$offset+=count($group);$chunks[]=[implode(' ',$group),$start,$duration*$offset/$total];}
        }
        foreach($chunks as[$line,$start,$end]){if($line!==''&&$end>$start){$header.='Dialogue: 0,'.self::assTime($start).','.self::assTime($end).',Default,,0,0,0,,'.self::captionText($line)."\n";}}
        return $header;
    }
    public function render(string $directory,array $script,array $voice,array $images): array {
        $ffmpeg=Process::binary('ffmpeg');$ffprobe=Process::binary('ffprobe');
        if(!$ffmpeg||!$ffprobe){throw new \RuntimeException('FFmpeg is missing. Run scripts/install-ffmpeg.ps1, then retry this job.');}
        $probe=json_decode(Process::run([$ffprobe,'-v','error','-show_entries','format=duration','-of','json',$directory.'/'.$voice['file']],$directory,20),true);
        $duration=(float)($probe['format']['duration']??0);
        if($duration<5||$duration>90){throw new \RuntimeException('Narration must be between 5 and 90 seconds.');}
        $total=max(1,array_sum(array_map(fn($s)=>mb_strlen($s['narration']),$script['scenes'])));$concat=[];
        foreach($images as$i=>$image){
            $length=$duration*mb_strlen($script['scenes'][$i]['narration'])/$total+0.08;
            $segment='segment-'.$i.'.mp4';
            $filter="scale=900:1600:force_original_aspect_ratio=increase,crop=900:1600,zoompan=z='min(zoom+0.0005,1.10)':x='iw/2-iw/zoom/2':y='ih/2-ih/zoom/2':d=1:s=720x1280:fps=24,setsar=1,format=yuv420p";
            Process::run([$ffmpeg,'-hide_banner','-loglevel','error','-y','-loop','1','-framerate','24','-i',$image,'-t',sprintf('%.3f',$length),'-vf',$filter,'-an','-c:v','libx264','-preset','veryfast','-crf','24','-threads','4',$segment],$directory,600);
            $concat[]="file '".$segment."'";
        }
        file_put_contents($directory.'/segments.txt',implode("\n",$concat));
        Process::run([$ffmpeg,'-hide_banner','-loglevel','error','-y','-f','concat','-safe','0','-i','segments.txt','-i',$voice['file'],'-t',sprintf('%.3f',$duration),'-af','loudnorm=I=-16:TP=-1.5:LRA=11','-c:v','libx264','-preset','veryfast','-crf','23','-threads','4','-pix_fmt','yuv420p','-c:a','aac','-b:a','160k','-ar','48000','-ac','2','-movflags','+faststart','final.mp4'],$directory,900);
        $check=json_decode(Process::run([$ffprobe,'-v','error','-show_streams','-show_format','-of','json','final.mp4'],$directory,20),true);
        $video=null;$audio=false;foreach($check['streams']??[]as$stream){if($stream['codec_type']==='video'){$video=$stream;}if($stream['codec_type']==='audio'){$audio=true;}}
        if(!$audio||!$video||$video['width']!==720||$video['height']!==1280||abs((float)$check['format']['duration']-$duration)>1){throw new \RuntimeException('Rendered video failed its audio, dimensions or duration check.');}
        return ['file'=>'final.mp4','duration'=>round($duration,2),'bytes'=>filesize($directory.'/final.mp4'),'width'=>720,'height'=>1280];
    }
}
