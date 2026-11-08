<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
class AppServiceProvider extends ServiceProvider {
    public function boot(): void {
        if(str_starts_with((string)config('app.url'),'https://')){URL::forceScheme('https');}
    }
}
