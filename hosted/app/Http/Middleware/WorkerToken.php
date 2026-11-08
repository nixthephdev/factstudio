<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class WorkerToken {
    public function handle(Request $request,Closure $next){
        $expected=(string)config('studio.worker_token_hash');$token=$request->bearerToken();
        abort_unless(strlen($expected)===64&&is_string($token)&&hash_equals($expected,hash('sha256',$token)),401);
        return $next($request);
    }
}
