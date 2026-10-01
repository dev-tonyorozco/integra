<?php

namespace App\Models;

class Opportunity extends ScopedModel
{
    protected function casts(): array
    {
        return ['published' => 'boolean', 'active' => 'boolean', 'closes_at' => 'datetime'];
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function process()
    {
        return $this->belongsTo(Process::class);
    }

    public function owner()
    {
        return $this->belongsTo(Membership::class, 'owner_id');
    }
}
