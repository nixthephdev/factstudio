<?php
declare(strict_types=1);
namespace Fact;

final class Research {
    public static function nasaUrl(string $url): bool {
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        return parse_url($url,PHP_URL_SCHEME)==='https' && in_array($host,['www.nasa.gov','nasa.gov','science.nasa.gov'],true) && !parse_url($url,PHP_URL_USER) && !parse_url($url,PHP_URL_PORT);
    }
    private static function fetch(string $url): string {
        for($i=0;$i<4;$i++){
            if(!self::nasaUrl($url)){throw new \RuntimeException('Research URL is outside the supported NASA source list.');}
            $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>35,CURLOPT_USERAGENT=>'FACTStudio/1.0',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
            $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$next=curl_getinfo($ch,CURLINFO_REDIRECT_URL);$error=curl_error($ch);curl_close($ch);
            if($status>=300&&$status<400&&$next){$url=$next;continue;}
            if($body===false||$status!==200){throw new \RuntimeException('NASA source request failed (HTTP '.$status.'). '.$error);}
            if(strlen($body)>5000000){throw new \RuntimeException('Source document is too large.');}
            return $body;
        }
        throw new \RuntimeException('Source redirected too many times.');
    }
    public function candidates(): array {
        $xml=self::fetch('https://www.nasa.gov/feed/');
        $previous=libxml_use_internal_errors(true);$rss=simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET);libxml_use_internal_errors($previous);
        if(!$rss){throw new \RuntimeException('NASA returned an unreadable feed.');}
        $seen=db()->query("SELECT data FROM jobs WHERE mode IN ('live','preview') AND status NOT IN ('failed','skipped')")->fetchAll();$urls=[];
        foreach($seen as$row){$data=json_decode($row['data'],true)?:[];foreach($data['sources']??[]as$s){$urls[]=$s['url']??'';}}
        $items=[];
        foreach($rss->channel->item??[]as$item){
            $url=trim((string)$item->link);$title=html_entity_decode(strip_tags((string)$item->title),ENT_QUOTES|ENT_HTML5,'UTF-8');
            if(!self::nasaUrl($url)||in_array($url,$urls,true)){continue;}
            // Avoid administrative stories in an astronomy channel.
            if(preg_match('/\b(obituary|administrator|contract award|procurement|funeral)\b/i',$title)||stripos($title,'APOD:')===0){continue;}
            $items[]=['title'=>$title,'url'=>$url,'summary'=>mb_substr(trim(html_entity_decode(strip_tags((string)$item->description),ENT_QUOTES|ENT_HTML5,'UTF-8')),0,1200),'published'=>(string)$item->pubDate];
            if(count($items)>=10){break;}
        }
        if(!$items){throw new \RuntimeException('No unused NASA stories are available. Try again after the feed updates.');}
        return $items;
    }
    public function article(array $candidate): array {
        $html=self::fetch($candidate['url']);$doc=new \DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);$xpath=new \DOMXPath($doc);
        $paragraphs=$xpath->query('//main//p|//article//p');$parts=[];
        foreach($paragraphs as$p){$text=trim(preg_replace('/\s+/u',' ',$p->textContent));if(mb_strlen($text)>60){$parts[]=$text;}}
        $text=mb_substr(implode("\n",array_unique($parts)),0,14000);
        if(mb_strlen($text)<300){throw new \RuntimeException('The source article has too little readable text. Production stopped before writing unsupported facts.');}
        $candidate['text']=$text;return $candidate;
    }
    public function competitors(): array {
        $key=settings()->get('youtube_api_key');if($key===''){return [];}
        $search=Http::json('GET','https://www.googleapis.com/youtube/v3/search?'.http_build_query(['part'=>'snippet','type'=>'video','maxResults'=>8,'q'=>settings()->get('youtube_query'),'order'=>'relevance','key'=>$key]));
        $ids=[];foreach($search['items']??[]as$item){$ids[]=$item['id']['videoId'];}
        if(!$ids){return [];}
        $videos=Http::json('GET','https://www.googleapis.com/youtube/v3/videos?'.http_build_query(['part'=>'snippet,statistics,contentDetails','id'=>implode(',',$ids),'key'=>$key]));
        $result=[];foreach($videos['items']??[]as$video){$result[]=['id'=>$video['id'],'title'=>$video['snippet']['title'],'channel'=>$video['snippet']['channelTitle'],'views'=>(int)($video['statistics']['viewCount']??0),'published'=>$video['snippet']['publishedAt'],'duration'=>$video['contentDetails']['duration']];}
        usort($result,fn($a,$b)=>$b['views']<=>$a['views']);return $result;
    }
}
