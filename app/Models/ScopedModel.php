<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ScopedModel extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function ($m) {
            if (in_array(class_basename($m), ['Role', 'Area', 'Workflow', 'Questionnaire', 'Requirement', 'Process', 'Opportunity', 'Applicant'])) {
                $m->search_text = Str::lower(Str::ascii($m->name.' '.($m->description ?? '')));
            }
        });
    }
}
