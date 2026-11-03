<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
try{
    $raw=Fact\Process::run(['git','ls-files','-z'],Fact\ROOT,15);$files=array_filter(explode("\0",$raw));
    $secrets=[];foreach(Fact\Settings::SECRETS as$name){$value=Fact\settings()->get($name);if(strlen($value)>=8){$secrets[$name]=$value;}}
    $issues=[];
    foreach($files as$file){
        if((strpos($file,'storage/')===0&&!in_array($file,['storage/.gitkeep','storage/.htaccess'],true))||(strpos($file,'tools/')===0&&$file!=='tools/.gitkeep')||preg_match('/\.(?:sqlite|factbackup|key|log)$/i',$file)){$issues[]='Private runtime file is tracked: '.$file;continue;}
        $content=file_get_contents(Fact\ROOT.'/'.$file);
        foreach($secrets as$name=>$secret){if(strpos($content,$secret)!==false){$issues[]='Saved credential '.$name.' appears in '.$file;}}
        if(preg_match('/\bEAA[A-Za-z0-9]{40,}\b/',$content)){$issues[]='Possible Meta credential in '.$file;}
        if(preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',$content)){$issues[]='Private key in '.$file;}
    }
    if($issues){throw new RuntimeException(implode("\n",$issues));}
    echo 'PASS: '.count($files)." tracked files exclude runtime data and contain none of the saved provider credentials.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
