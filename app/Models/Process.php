<?php

namespace App\Models;

class Process extends ScopedModel
{
    protected function casts(): array
    {
        return ['requirements' => 'array', 'test_ids' => 'array', 'active' => 'boolean'];
    }

    public function workflow()
    {
        return $this->belongsTo(Workflow::class);
    }

    public function questionnaire()
    {
        return $this->belongsTo(Questionnaire::class);
    }
}
