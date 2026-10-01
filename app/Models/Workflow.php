<?php
namespace App\Models;
class Workflow extends ScopedModel {
protected function casts():array {return ['definition'=>'array','published'=>'boolean','active'=>'boolean'];}
 protected static function booted():void {parent::booted();static::updating(function($m){if($m->getOriginal("published"))throw \Illuminate\Validation\ValidationException::withMessages(["name"=>"Una versión publicada no se modifica. Duplica para crear otra versión."]);}); static::deleting(fn()=>abort(403));}
}
