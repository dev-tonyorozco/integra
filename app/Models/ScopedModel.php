<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class ScopedModel extends Model {
 protected $guarded=['id'];
 protected static function booted():void {static::saving(function($m){if(array_key_exists('name',$m->getAttributes()))$m->search_text=Str::lower(Str::ascii($m->name.' '.($m->description??'')));});}
}
