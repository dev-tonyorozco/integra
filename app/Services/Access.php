<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Area;
use App\Models\AuditLog;
use App\Models\CaseEvent;
use App\Models\Membership;

class Access
{
    public ?Membership $member = null;

    public function can(string $permission): bool
    {
        return $this->member && $this->member->role?->active && ($this->member->role->code === 'ORG_ADMIN' || in_array($permission, $this->member->role->permissions));
    }

    public function require(string $permission): void
    {
        abort_unless($this->can($permission), 403);
    }

    public function query(string $model)
    {
        return $model::where('organization_id', $this->member->organization_id);
    }

    public function areaIds(): ?array
    {
        if ($this->member->role?->code === 'ORG_ADMIN') {
            return null;
        }

return $this->member->role?->area_scope || ($this->member->role?->code === 'CASE_MANAGER' && $this->member->area_ids) ? $this->member->area_ids : null;
    }

    public function areas()
    {
        return $this->query(Area::class)->when($this->areaIds() !== null, fn ($q) => $q->whereIn('id', $this->areaIds()));
    }

    public function cases()
    {
        return $this->query(Application::class)->when($this->areaIds() !== null, fn ($q) => $q->whereIn('area_id', $this->areaIds()))->when($this->member->role?->code === 'REVIEWER', fn ($q) => $q->where('owner_id', $this->member->id));
    }

    public function owners(int $area)
    {
        return $this->query(Membership::class)->with('user', 'role')->where('active', true)->where('login_enabled', true)->whereHas('user', fn ($q) => $q->where('active', true))->whereHas('role', fn ($q) => $q->where('active', true))->get()->filter(fn ($m) => ($m->role->code === 'ORG_ADMIN' || in_array('cases.write', $m->role->permissions)) && (! $m->role->area_scope && ($m->role->code !== 'CASE_MANAGER' || ! $m->area_ids) || in_array($area, $m->area_ids)));
    }

    public function owner(int $id, int $area)
    {
        return $this->owners($area)->firstWhere('id', $id) ?? abort(422, 'Responsable no disponible para esta área.');
    }

    public function audit(string $action, string $target, array $metadata = []): void
    {
        AuditLog::create(['organization_id' => $this->member->organization_id, 'actor_id' => auth()->id(), 'action' => $action, 'target' => $target, 'metadata' => $metadata]);
    }

    public static function event(Application $case, string $kind, string $body, array $metadata = [], bool $private = false): void
    {
        CaseEvent::create(['organization_id' => $case->organization_id, 'application_id' => $case->id, 'actor_id' => auth()->id(), 'kind' => $kind, 'body' => $body, 'metadata' => $metadata, 'private' => $private]);
    }
}
