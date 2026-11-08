<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class AdminSession {
    public function handle(Request $request,Closure $next){
        if(!$request->session()->get('studio_admin')){
            return $request->expectsJson()?response()->json(['message'=>'Sign in to your studio.'],401):redirect()->route('login');
        }
        $response=$next($request);$response->headers->set('Cache-Control','no-store');$response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('X-Frame-Options','DENY');$response->headers->set('Referrer-Policy','no-referrer');return $response;
    }
}
