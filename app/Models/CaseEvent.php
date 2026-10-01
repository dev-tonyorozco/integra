<?php
namespace App\Models;
class CaseEvent extends ScopedModel {
protected function casts():array {return ['metadata'=>'array','private'=>'boolean'];}
 protected static function booted():void {static::updating(fn()=>abort(403,"Historial inmutable"));static::deleting(fn()=>abort(403,"Historial inmutable"));}
}
