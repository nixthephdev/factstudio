<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WorkerController;
Route::middleware(['studio.worker','throttle:120,1'])->prefix('worker')->group(function(){
    Route::post('/poll',[WorkerController::class,'poll']);
    Route::post('/ack',[WorkerController::class,'acknowledge']);
    Route::post('/upload',[WorkerController::class,'upload']);
});
