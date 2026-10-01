<?php
namespace App\Models;
class Organization extends ScopedModel {
protected function casts():array {return ['active'=>'boolean'];}
public function memberships(){return $this->hasMany(Membership::class);}
}
