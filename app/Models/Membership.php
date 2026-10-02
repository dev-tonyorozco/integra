<?php

namespace App\Models;

class Membership extends ScopedModel
{
    protected function casts(): array
    {
        return ['area_ids' => 'array', 'active' => 'boolean', 'login_enabled' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
