<?php
declare(strict_types=1);
namespace Fact;

final class Voice {
    public function generate(int $jobId,string $text,string $directory,bool $demo): array {
        $meta=$directory.'/voice.json';
        if(is_file($meta)){$result=json_decode(file_get_contents($meta),true);if($result&&is_file($directory.'/'.$result['file'])){return $result;}}
        if($demo){
            if(PHP_OS_FAMILY!=='Windows'){throw new \RuntimeException('The free demo voice requires Windows. Live production uses ElevenLabs.');}
            file_put_contents($directory.'/narration.txt',$text);
            Process::run(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',ROOT.'/scripts/demo-voice.ps1','-TextFile',$directory.'/narration.txt','-OutputFile',$directory.'/voice.wav'],ROOT,90);
            $result=['file'=>'voice.wav','alignment'=>null,'provider'=>'Windows demo voice'];
        }else{
            $key=settings()->get('eleven_api_key');$voice=settings()->get('eleven_voice');
            if($key===''||$voice===''){throw new \RuntimeException('Add your ElevenLabs API key and voice ID in Connections.');}
            db()->reserveSpeech($jobId,mb_strlen($text),(int)settings()->get('speech_limit'));
            // A timed-out request is not automatically repeated: it may have consumed credits.
            $response=Http::json('POST','https://api.elevenlabs.io/v1/text-to-speech/'.rawurlencode($voice).'/with-timestamps',['text'=>$text,'model_id'=>settings()->get('eleven_model')],['xi-api-key: '.$key],180,18000000);
            $audio=base64_decode($response['audio_base64']??'',true);
            if($audio===false||strlen($audio)<100){throw new \RuntimeException('ElevenLabs returned no usable audio.');}
            file_put_contents($directory.'/voice.mp3',$audio);
            $result=['file'=>'voice.mp3','alignment'=>$response['normalized_alignment']??$response['alignment']??null,'provider'=>'ElevenLabs'];
        }
        file_put_contents($meta,json_encode($result,JSON_THROW_ON_ERROR));return $result;
    }
}
