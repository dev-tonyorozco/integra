<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Attachment;
use App\Models\InboxNotification;
use App\Models\Interview;
use App\Models\PublicTask;
use App\Models\Questionnaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CaseWorkflow
{
    public function act(Application $case, Request $r, Access $access): void
    {
        $access->require('cases.write');
        $r->validate(['revision' => 'required|integer', 'action' => 'required|string']);
        DB::transaction(function () use ($case, $r, $access) {
            $c = $access->cases()->lockForUpdate()->findOrFail($case->id);
            abort_if($c->revision !== (int) $r->revision, 409, 'La solicitud cambió. Recarga antes de guardar.');
            $action = $r->action;
            if ($action === 'archive') {
                abort_unless($c->closed_at && ! $c->archived_at, 422);
                $c->archived_at = now();
                Access::event($c, 'archive', 'Solicitud archivada.');
            } else {
                abort_if($c->closed_at || $c->archived_at, 422, 'Esta solicitud ya está finalizada.');
                switch ($action) {
                    case 'transition':
                        abort_if($c->pause_started, 422, 'Reanuda antes de avanzar.');
                        $r->validate(['to' => 'required|string', 'body' => 'required|string|max:5000']);
                        $t = collect($c->snapshot['workflow']['transitions'])->first(fn ($t) => $t['from'] === $c->state && $t['to'] === $r->to);
                        abort_unless($t && ($access->member->role->code === 'ORG_ADMIN' || in_array($access->member->role->code, $t['roles'])), 403);
                        if (! empty($t['leader_decision'])) {
                            $r->validate(['leader_decision' => 'required|string|max:5000']);
                        }$state = collect($c->snapshot['workflow']['states'])->firstWhere('id', $r->to);
                        if (($state['outcome'] ?? '') === 'integrated') {
                            $r->validate(['integration_function' => 'required|string|max:160', 'integration_date' => 'required|date']);
                            $c->integration_function = $r->integration_function;
                            $c->integration_date = $r->integration_date;
                        }$from = $c->state;
                        $c->state = $state['id'];
                        $c->state_entered_at = now();
                        $c->due_at = Definitions::due($state);
                        $c->next_action = $state['action'] ?? 'Dar seguimiento';
                        if (! empty($state['terminal'])) {
                            $c->closed_at = now();
                        }Access::event($c, 'transition', $r->body, ['from' => $from, 'to' => $c->state, 'leader_decision' => $r->leader_decision]);
                        break;
                    case 'note':case 'findings':case 'requirements':
                        if ($action !== 'note') {
                            $access->require('personal.read');
                        }$r->validate(['body' => 'required|string|max:10000']);
                        Access::event($c, $action, $r->body, [], ($action === 'findings' || $r->boolean('private')));
                        break;
                    case 'followup':
                        $r->validate(['owner_id' => 'required|integer', 'next_action' => 'required|string|max:240', 'due_at' => 'required|date']);
                        $access->owner((int) $r->owner_id, $c->area_id);
                        $c->owner_id = $r->owner_id;
                        $c->next_action = $r->next_action;
                        $c->due_at = $r->due_at;
                        Access::event($c, 'followup', 'Responsable y seguimiento actualizados.');
                        break;
                    case 'pause':$r->validate(['body' => 'required|string|max:5000']);
                        abort_if($c->pause_started, 422);
                        $c->pause_started = now();
                        $c->pause_reason = $r->body;
                        Access::event($c, 'pause', $r->body);
                        break;
                    case 'resume':abort_unless($c->pause_started, 422);
                        $c->due_at = $c->due_at->addSeconds((int) $c->pause_started->diffInSeconds(now(), true));
                        $c->pause_started = null;
                        $c->pause_reason = null;
                        Access::event($c, 'resume', 'Seguimiento reanudado; plazo compensado.');
                        break;
                    case 'interview':$access->require('personal.read');
                        $v = $r->validate(['scheduled_at' => 'required|date', 'interviewer' => 'required|string|max:160', 'location' => 'required|string|max:240', 'result' => 'nullable|string|max:10000', 'status' => 'required|in:scheduled,completed,cancelled']);
                        Interview::create(array_merge($v, ['organization_id' => $c->organization_id, 'application_id' => $c->id]));
                        Access::event($c, 'interview', 'Entrevista registrada.', [], true);
                        break;
                    case 'task':$access->require('personal.read');
                        $r->validate(['questionnaire_id' => 'required|integer']);
                        $q = $access->query(Questionnaire::class)->where('published', true)->findOrFail($r->questionnaire_id);
                        if ($q->is_test) {
                            $access->require('tests.read');
                            abort_unless(collect($c->snapshot['tests'])->contains('id', $q->id), 422);
                        }$task = PublicTask::create(['organization_id' => $c->organization_id, 'application_id' => $c->id, 'token' => Str::uuid(), 'name' => $q->name, 'questions' => $q->questions, 'is_test' => $q->is_test, 'expires_at' => now()->addDays(10)]);
                        Access::event($c, 'task', 'Tarea creada.', ['task_id' => $task->id]);
                        break;
                    case 'attachment':$access->require('personal.read');
                        $r->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120']);
                        $f = $r->file('file');
                        $path = $c->organization_id.'/'.$c->id.'/'.Str::uuid().'.'.$f->extension();
                        app(PrivateFiles::class)->put($path, $f->getContent(), $f->getMimeType());
                        Attachment::create(['organization_id' => $c->organization_id, 'application_id' => $c->id, 'name' => Str::limit(basename($f->getClientOriginalName()), 200, ''), 'path' => $path, 'mime' => $f->getMimeType(), 'size' => $f->getSize()]);
                        Access::event($c, 'attachment', 'Archivo privado añadido.', [], true);
                        break;
                    default:abort(422, 'Acción desconocida.');
                }
            }$c->revision++;
            $c->last_activity_at = now();
            $c->save();
            $access->audit('case.'.$action, 'application:'.$c->id, ['revision' => $c->revision]);
        });
    }

    public function answer(PublicTask $task, array $answers): void
    {
        DB::transaction(function () use ($task, $answers) {
            $t = PublicTask::lockForUpdate()->findOrFail($task->id);
            abort_if($t->completed_at || $t->expires_at->isPast() || $t->application->closed_at || $t->application->archived_at, 410);
            $a = Definitions::answers($t->questions, $answers);
            $t->answers = $a;
            $t->score = $t->is_test ? Definitions::score($t->questions, $a) : null;
            $t->completed_at = now();
            $t->save();
            $c = Application::lockForUpdate()->findOrFail($t->application_id);
            $c->last_activity_at = now();
            $c->revision++;
            $c->save();
            Access::event($c,'task_completed','Tarea respondida; resultado orientativo.',['task_id' => $t->id]);
            InboxNotification::create(['organization_id' => $c->organization_id, 'membership_id' => $c->owner_id, 'application_id' => $c->id, 'message' => 'Tarea respondida '.$c->folio]);
        });
    }
}
