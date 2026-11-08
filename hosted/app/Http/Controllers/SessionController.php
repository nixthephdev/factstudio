<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
class SessionController {
    public function login(Request $request){
        $input=$request->validate(['username'=>'required|string|max:100','password'=>'required|string|max:300']);
        $key='studio-login:'.$request->ip();
        if(RateLimiter::tooManyAttempts($key,5)){return back()->withErrors(['login'=>'Too many attempts. Try again in a minute.']);}
        RateLimiter::hit($key,60);
        $hash=(string)config('studio.admin_password_hash');
        if($hash===''||!hash_equals((string)config('studio.admin_user'),$input['username'])||!password_verify($input['password'],$hash)){
            return back()->withErrors(['login'=>'Check your username and password.']);
        }
        RateLimiter::clear($key);$request->session()->regenerate();$request->session()->put('studio_admin',true);
        return redirect()->route('studio');
    }
    public function logout(Request $request){$request->session()->invalidate();$request->session()->regenerateToken();return redirect()->route('login');}
}
