<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\{Exceptions,Middleware};
return Application::configure(basePath:dirname(__DIR__))
 ->withRouting(web:__DIR__.'/../routes/web.php',commands:__DIR__.'/../routes/console.php',health:'/up')
 ->withMiddleware(function(Middleware $m):void{$m->alias(['organization'=>\App\Http\Middleware\OrganizationContext::class]);$m->append(\App\Http\Middleware\SecurityHeaders::class);})
 ->withExceptions(function(Exceptions $e):void{}) ->create();
