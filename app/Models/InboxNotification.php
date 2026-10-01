<?php
namespace App\Models;
class InboxNotification extends ScopedModel {
protected function casts():array {return ['read_at'=>'datetime'];}

}
