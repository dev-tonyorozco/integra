<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
class User extends Authenticatable {
 use Notifiable;
 protected $guarded=['id']; protected $hidden=['password','remember_token'];
 protected function casts():array{return ['password'=>'hashed','active'=>'boolean','email_verified_at'=>'datetime'];}
 protected static function booted():void{static::saving(function($m){$m->email=$m->email?Str::lower(trim($m->email)):null;$m->search_text=Str::lower(Str::ascii($m->name.' '.($m->email??'')));});}
 public function memberships(){return $this->hasMany(Membership::class);}
}
