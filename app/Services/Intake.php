<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\InboxNotification;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Questionnaire;
use App\Models\Requirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Intake
{
    public function open(Opportunity $o): bool
    {
        return $o->active && $o->published && $o->area->active && $o->process->active && $o->process->workflow->published && $o->process->questionnaire->published && $o->owner->active && $o->owner->login_enabled && $o->owner->user->active && $o->owner->role?->active && (! $o->closes_at || $o->closes_at->isFuture()) && $o->organization_id === Organization::where('id', $o->organization_id)->where('active', true)->value('id');
    }

    public function snapshot(Opportunity $o): array
    {
        $reqs = [];
        foreach (array_merge($o->area->requirements, $o->process->requirements) as $r) {
            $definition = Requirement::where('organization_id', $o->organization_id)->where('published', true)->findOrFail($r['id']);
            $reqs[$definition->id] = ['id' => $definition->id, 'name' => $definition->name, 'description' => $definition->description, 'mandatory' => (bool) $r['mandatory']];
        }

        return ['workflow' => $o->process->workflow->definition, 'workflow_id' => $o->process->workflow_id, 'workflow_version' => $o->process->workflow->version, 'questions' => $o->process->questionnaire->questions, 'form_id' => $o->process->questionnaire_id, 'form_version' => $o->process->questionnaire->version, 'requirements' => array_values($reqs), 'tests' => Questionnaire::where('organization_id', $o->organization_id)->where('published', true)->where('is_test', true)->whereIn('id', $o->process->test_ids)->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'questions' => $t->questions])->all(), 'process' => $o->process->name, 'area' => $o->area->name];
    }

    public function submit(Opportunity $o, array $data): Application
    {
        abort_unless($this->open($o), 410, 'Convocatoria no disponible.');
        $s = $this->snapshot($o);
        $a = Definitions::answers($s['questions'], $data['answers'] ?? []);
        validator($data, ['name' => 'required|string|max:160', 'email' => 'required|email|max:254', 'phone' => 'required|string|max:40', 'consent' => 'accepted'])->validate();
        foreach ($s['requirements'] as $r) {
            if ($r['mandatory'] && empty($data['requirements'][$r['id']])) {
                Definitions::fail('Confirma los requisitos obligatorios.');
            }
        }

        return DB::transaction(function () use ($o, $s, $a, $data) {
            $locked = Opportunity::lockForUpdate()->findOrFail($o->id);
            abort_unless($this->open($locked), 410);
            $access = new Access;
            $access->member = $o->owner;
            $access->owner($o->owner_id, $o->area_id);
            $email = Str::lower(trim($data['email']));
            $duplicate = Application::where('organization_id', $o->organization_id)->whereHas('applicant', fn ($q) => $q->where('email', $email))->latest()->first();
            $person = Applicant::create(['organization_id' => $o->organization_id, 'name' => $data['name'], 'email' => $email, 'phone' => $data['phone']]);
            $state = collect($s['workflow']['states'])->firstWhere('id', $s['workflow']['initial']);
            $case = Application::create(['organization_id' => $o->organization_id, 'applicant_id' => $person->id, 'opportunity_id' => $o->id, 'area_id' => $o->area_id, 'owner_id' => $o->owner_id, 'folio' => 'INT-'.now()->format('ymd').'-'.Str::upper(Str::random(8)), 'snapshot' => $s, 'answers' => $a, 'declarations' => $data['requirements'] ?? [], 'state' => $state['id'], 'next_action' => $state['action'] ?? 'Revisar solicitud', 'due_at' => Definitions::due($state), 'state_entered_at' => now(), 'last_activity_at' => now(), 'consent_at' => now(), 'duplicate_of' => $duplicate?->id]);
            Access::event($case, 'intake', 'Solicitud recibida con consentimiento.');
            InboxNotification::create(['organization_id' => $o->organization_id, 'membership_id' => $o->owner_id, 'application_id' => $case->id, 'message' => 'Nueva solicitud '.$case->folio]);

            return $case;
        });
    }
}
