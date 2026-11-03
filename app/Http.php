<?php
declare(strict_types=1);
namespace Fact;

final class Http {
    public static function request(string $method,string $url,$body=null,array $headers=[],int $timeout=45,int $maxBytes=4000000,array $accepted=[]): array {
        $ch=curl_init($url); $output=''; $responseHeaders=[];
        $headers[]='User-Agent: FACTStudio/1.0 (local content production)';
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>$timeout,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$output,$maxBytes){if(strlen($output)+strlen($chunk)>$maxBytes){return 0;}$output.=$chunk;return strlen($chunk);},CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$responseHeaders){$parts=explode(':',$line,2);if(count($parts)===2){$responseHeaders[strtolower(trim($parts[0]))]=trim($parts[1]);}return strlen($line);}]);
        if($body!==null){curl_setopt($ch,CURLOPT_POSTFIELDS,is_array($body)?http_build_query($body):$body);}
        $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $error=curl_error($ch);curl_close($ch);
        if($ok===false){throw new \RuntimeException('Connection failed to '.parse_url($url,PHP_URL_HOST).': '.$error);}
        if(($status<200||$status>=300)&&!in_array($status,$accepted,true)){
            $decoded=json_decode($output,true);$detail=$decoded['error']['message']??$decoded['detail']['message']??$decoded['error']??'';
            throw new \RuntimeException('HTTP '.$status.' from '.parse_url($url,PHP_URL_HOST).(is_string($detail)&&$detail!==''?': '.mb_substr($detail,0,250):''));
        }
        return ['body'=>$output,'headers'=>$responseHeaders,'status'=>$status];
    }
    public static function json(string $method,string $url,?array $data=null,array $headers=[],int $timeout=45,int $maxBytes=4000000): array {
        $headers[]='Content-Type: application/json';
        $r=self::request($method,$url,$data===null?null:json_encode($data,JSON_THROW_ON_ERROR),$headers,$timeout,$maxBytes);
        return json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
    }
}
