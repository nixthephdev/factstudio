<?php
return ['name'=>env('APP_NAME','FACT Studio'),'env'=>env('APP_ENV','production'),'debug'=>(bool)env('APP_DEBUG',false),
    'url'=>env('APP_URL','http://localhost:8095'),'asset_url'=>env('ASSET_URL'),'timezone'=>env('APP_TIMEZONE','Asia/Singapore'),
    'locale'=>'en','fallback_locale'=>'en','faker_locale'=>'en_US','key'=>env('APP_KEY'),'cipher'=>'AES-256-CBC',
    'maintenance'=>['driver'=>'file']];
