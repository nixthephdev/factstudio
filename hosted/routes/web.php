<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StudioController;
Route::get('/login',fn()=>view('login'))->name('login');
Route::post('/login',[SessionController::class,'login']);
Route::middleware('studio.admin')->group(function(){
    Route::get('/',fn()=>view('studio'))->name('studio');
    Route::post('/logout',[SessionController::class,'logout'])->name('logout');
    Route::get('/state',[StudioController::class,'state'])->name('state');
    Route::post('/commands',[StudioController::class,'command'])->name('commands');
});
Route::match(['GET','HEAD'],'/video/{video}',[StudioController::class,'video'])->middleware('signed')->name('video');
