<?php

namespace App\Models;

class Application extends ScopedModel
{
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'answers' => 'array', 'declarations' => 'array', 'due_at' => 'datetime', 'state_entered_at' => 'datetime', 'last_activity_at' => 'datetime', 'closed_at' => 'datetime', 'pause_started' => 'datetime', 'consent_at' => 'datetime', 'archived_at' => 'datetime', 'integration_date' => 'date'];
    }

    public function applicant()
    {
        return $this->belongsTo(Applicant::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function owner()
    {
        return $this->belongsTo(Membership::class, 'owner_id');
    }

    public function events()
    {
        return $this->hasMany(CaseEvent::class);
    }

    public function tasks()
    {
        return $this->hasMany(PublicTask::class);
    }

    public function interviews()
    {
        return $this->hasMany(Interview::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function stateConfig()
    {
        return collect($this->snapshot['workflow']['states'])->firstWhere('id', $this->state) ?? [];
    }

    public function sla()
    {
        return $this->closed_at ? 'Finalizada' : ($this->pause_started ? 'Pausada' : ($this->due_at->isPast() ? 'Vencida' : ($this->due_at->diffInHours(now(), true) < 24 ? 'Por vencer' : 'En tiempo')));
    }
}
