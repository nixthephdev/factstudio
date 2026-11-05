<?php
declare(strict_types=1);
namespace Fact;

final class Visuals {
    public function generate(array $scenes,string $directory,bool $demo): array {
        $files=[];
        try{
            foreach($scenes as$i=>$scene){$file=$directory.'/scene-'.$i.'.png';if(!is_file($file)){if($demo){$this->sample($file,$i);}else{$this->comfy($file,$scene['visual_prompt']);}}$files[]=basename($file);}
        }finally{
            if(!$demo){try{Http::json('POST',rtrim(settings()->get('comfy_url'),'/').'/free',['unload_models'=>true,'free_memory'=>true],[],10);}catch(\Throwable $e){}}
        }
        return $files;
    }
    public static function workflow(string $prompt,string $checkpoint,int $seed): array {
        $path=ROOT.'/workflows/sd15.json';$graph=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        $graph['4']['inputs']['ckpt_name']=$checkpoint;$graph['6']['inputs']['text']=$prompt.', editorial space illustration, rich color, cinematic light, no text, no watermark';$graph['3']['inputs']['seed']=$seed;return $graph;
    }
    private function comfy(string $file,string $prompt): void {
        $checkpoint=settings()->get('comfy_checkpoint');if($checkpoint===''){throw new \RuntimeException('Choose your installed Stable Diffusion checkpoint in Connections.');}
        $base=rtrim(settings()->get('comfy_url'),'/');$tag='fact_'.bin2hex(random_bytes(8));
        $graph=self::workflow($prompt,$checkpoint,random_int(1,2147483646));$graph['9']['inputs']['filename_prefix']=$tag;
        $response=Http::json('POST',$base.'/prompt',['prompt'=>$graph,'client_id'=>$tag],[],30);
        $id=$response['prompt_id']??null;if(!$id){throw new \RuntimeException('ComfyUI rejected the image workflow. Check that the checkpoint is installed.');}
        $started=time();
        while(time()-$started<900){
            $history=Http::json('GET',$base.'/history/'.rawurlencode($id),null,[],15);
            if(isset($history[$id])){
                $entry=$history[$id];
                if(($entry['status']['status_str']??'')==='error'){throw new \RuntimeException('ComfyUI image generation failed. Check its console for GPU or model errors.');}
                $image=$entry['outputs']['9']['images'][0]??null;
                if($image){$r=Http::request('GET',$base.'/view?'.http_build_query(['filename'=>$image['filename'],'subfolder'=>$image['subfolder']??'','type'=>$image['type']??'output']),null,[],45,15000000);if(@getimagesizefromstring($r['body'])===false){throw new \RuntimeException('ComfyUI returned an invalid image.');}file_put_contents($file,$r['body']);return;}
            }
            sleep(2);
        }
        throw new \RuntimeException('ComfyUI exceeded the 15-minute scene timeout. The queued image may still finish in ComfyUI.');
    }
    private function sample(string $file,int $scene): void {
        $width=720;$height=1280;$im=imagecreatetruecolor($width,$height);
        for($y=0;$y<$height;$y++){$t=$y/$height;$color=imagecolorallocate($im,(int)(15+10*$t),(int)(24+15*$t),(int)(42+25*$t));imageline($im,0,$y,$width,$y,$color);}
        for($i=0;$i<140;$i++){$x=($i*173+41)%720;$y=($i*277+79)%1280;$c=imagecolorallocate($im,130+($i%100),150+($i%90),190+($i%60));imagefilledellipse($im,$x,$y,$i%4+1,$i%4+1,$c);}
        $colors=[[255,183,77],[130,168,220],[238,125,83],[174,198,229]];$rgb=$colors[$scene%4];
        for($r=210;$r>0;$r--){$shade=0.5+0.5*(1-$r/210);$c=imagecolorallocate($im,(int)($rgb[0]*$shade),(int)($rgb[1]*$shade),(int)($rgb[2]*$shade));imagefilledellipse($im,(int)(360-$r/9),(int)(520-$r/9),$r*2,$r*2,$c);}
        imagepng($im,$file);imagedestroy($im);
    }
}
