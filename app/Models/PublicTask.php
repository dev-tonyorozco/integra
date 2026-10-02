<?php

namespace App\Models;

class PublicTask extends ScopedModel
{
    protected function casts(): array
    {
        return ['questions' => 'array', 'answers' => 'array', 'is_test' => 'boolean', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
