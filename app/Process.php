<?php
declare(strict_types=1);
namespace Fact;

final class Process {
    public static function run(array $args,?string $cwd=null,int $timeout=600): string {
        $pipes=[];$process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd,null,['bypass_shell'=>true]);
        if(!is_resource($process)){throw new \RuntimeException('Cannot start local process.');}
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $output='';$started=time();$exit=-1;
        while(true){
            $output.=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);if(strlen($output)>80000){$output=substr($output,-80000);}
            $state=proc_get_status($process);
            if(!$state['running']){$exit=$state['exitcode'];break;}
            if(time()-$started>$timeout){proc_terminate($process);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);throw new \RuntimeException('Local process timed out.');}
            usleep(100000);
        }
        $output.=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);if($exit===-1){$exit=$closed;}
        if($exit!==0){throw new \RuntimeException('Local process failed: '.substr($output,-2200));}
        return $output;
    }
    public static function binary(string $name): ?string {
        $configured=settings()->get($name.'_path');
        if($configured!==''&&is_file($configured)){return $configured;}
        $ext=PHP_OS_FAMILY==='Windows'?'.exe':'';
        $paths=[ROOT.'/tools/ffmpeg/bin/'.$name.$ext];
        foreach(explode(PATH_SEPARATOR,getenv('PATH')?:'')as$folder){$paths[]=rtrim($folder,'/\\').'/'.$name.$ext;}
        foreach($paths as$path){if(is_file($path)){return $path;}}
        return null;
    }
    public static function php(): string {
        if(PHP_SAPI==='cli'){return PHP_BINARY;}
        foreach([PHP_BINDIR.'/php.exe','C:/xampp/php/php.exe',PHP_BINDIR.'/php']as$path){if(is_file($path)){return $path;}}
        throw new \RuntimeException('PHP CLI was not found.');
    }
    public static function launchWorker(): void {
        if(PHP_OS_FAMILY==='Windows'){
            // Anonymous Windows pipes can remain open in detached descendants and block
            // the web request. Launch PowerShell with file-backed handles instead.
            $pipes=[];
            $process=proc_open(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',ROOT.'/scripts/start-worker.ps1','-PhpPath',self::php()],
                [0=>['file','NUL','r'],1=>['file',storage('logs/launcher.out.log'),'a'],2=>['file',storage('logs/launcher.err.log'),'a']],$pipes,ROOT,null,['bypass_shell'=>true]);
            if(!is_resource($process)||proc_close($process)!==0){throw new \RuntimeException('Could not start the background worker. Run php bin/console.php work from the project folder.');}
        }else{
            exec(escapeshellarg(self::php()).' '.escapeshellarg(ROOT.'/bin/console.php').' work > '.escapeshellarg(storage('logs/worker.log')).' 2>&1 &');
        }
    }
}
