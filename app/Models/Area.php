<?php

namespace App\Models;

class Area extends ScopedModel
{
    protected function casts(): array
    {
        return ['requirements' => 'array', 'active' => 'boolean'];
    }

    public function contacts()
    {
        return $this->belongsToMany(Membership::class, 'area_contacts')->withPivot('kind');
    }
}
