<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use App\Services\Access;
class AppServiceProvider extends ServiceProvider {
 public function register():void{$this->app->scoped(Access::class,fn()=>new Access);}
 public function boot():void{\Illuminate\Pagination\Paginator::defaultView('components.pagination');}
}
