<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class SecurityHeaders {
    public function handle(Request $request,Closure $next){
        $response=$next($request);
        $response->headers->set('X-Content-Type-Options','nosniff');$response->headers->set('X-Frame-Options','DENY');
        $response->headers->set('Referrer-Policy','no-referrer');$response->headers->set('Cache-Control','no-store');
        $response->headers->set('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; media-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        return $response;
    }
}
