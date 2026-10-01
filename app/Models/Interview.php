<?php
namespace App\Models;
class Interview extends ScopedModel {
protected function casts():array {return ['scheduled_at'=>'datetime'];}

}
