<?php
return ['driver'=>'file','lifetime'=>120,'expire_on_close'=>false,'encrypt'=>true,'files'=>storage_path('framework/sessions'),
    'connection'=>null,'table'=>'sessions','store'=>null,'lottery'=>[2,100],'cookie'=>'fact_control_room',
    'path'=>parse_url(env('APP_URL','http://localhost:8095'),PHP_URL_PATH)?:'/', 'domain'=>null,
    'secure'=>(bool)env('SESSION_SECURE_COOKIE',true),'http_only'=>true,'same_site'=>'lax','partitioned'=>false];
