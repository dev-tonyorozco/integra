<?php

namespace App\Models;

class Attachment extends ScopedModel
{
    protected function casts(): array
    {
        return [];
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
