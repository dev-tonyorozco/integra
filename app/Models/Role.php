<?php

namespace App\Models;

class Role extends ScopedModel
{
    protected function casts(): array
    {
        return ['permissions' => 'array', 'active' => 'boolean', 'area_scope' => 'boolean'];
    }
}
