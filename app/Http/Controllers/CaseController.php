<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\InboxNotification;
use App\Models\Questionnaire;
use App\Services\Access;
use App\Services\CaseWorkflow;
use App\Services\PrivateFiles;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class CaseController extends Controller
{
    public function __construct(public Access $access) {}

    private function query(Request $r)
    {
        $this->access->require('cases.read');
        $q = $this->access->cases()->with('applicant', 'area', 'owner.user');
        $q->when(! $r->boolean('archive'), fn ($q) => $q->whereNull('archived_at'));
        if ($r->filled('q')) {
            $term = '%'.Str::lower(Str::ascii($r->q)).'%';
            $q->where(fn ($q) => $q->whereRaw('LOWER(folio) LIKE ?', [$term])->orWhereHas('applicant', fn ($p) => $p->where('search_text', 'like', $term)));
        }if ($r->filled('area')) {
            $q->where('area_id', $r->area);
        }if ($r->owner === 'me') {
            $q->where('owner_id', $this->access->member->id);
        }if ($r->sla === 'overdue') {
            $q->whereNull('closed_at')->whereNull('pause_started')->where('due_at', '<', now());
        }if ($r->filled('state')) {
            $q->where('state', $r->state);
        }

return $q;
    }

    public function dashboard()
    {
        if (! $this->access->can('cases.read')) {
            return view('dashboard', ['cases' => collect(), 'metrics' => []]);
        }$q = $this->access->cases()->whereNull('archived_at');
        $metrics = ['Abiertas' => (clone $q)->whereNull('closed_at')->count(), 'Por vencer' => (clone $q)->whereNull('closed_at')->whereNull('pause_started')->whereBetween('due_at', [now(), now()->addDay()])->count(), 'Vencidas' => (clone $q)->whereNull('closed_at')->whereNull('pause_started')->where('due_at', '<', now())->count(), 'Integradas' => (clone $q)->whereNotNull('integration_date')->count()];

        return view('dashboard', ['cases' => $q->with('applicant', 'area', 'owner.user')->latest()->limit(8)->get(), 'metrics' => $metrics]);
    }

    public function index(Request $r)
    {
        return view('cases.index', ['records' => $this->query($r)->latest()->paginate(20)->withQueryString(), 'areas' => $this->access->areas()->get()]);
    }

    public function show(int $id)
    {
        $this->access->require('cases.read');
        $case = $this->access->cases()->with('applicant', 'area', 'owner.user', 'tasks', 'attachments', 'interviews')->findOrFail($id);
        $events = $case->events()->when(! $this->access->can('personal.read'), fn ($q) => $q->where('private', false))->latest()->get();
        $transitions = collect($case->snapshot['workflow']['transitions'])->filter(fn ($t) => $t['from'] === $case->state && ($this->access->member->role->code === 'ORG_ADMIN' || in_array($this->access->member->role->code, $t['roles'])));

        return view('cases.show', ['case' => $case, 'events' => $events, 'transitions' => $transitions, 'owners' => $this->access->owners($case->area_id), 'forms' => $this->access->query(Questionnaire::class)->where('published', true)->get()->filter(fn ($q) => ! $q->is_test || ($this->access->can('tests.read') && collect($case->snapshot['tests'])->contains('id', $q->id)))]);
    }

    public function act(Request $r, int $id)
    {
        app(CaseWorkflow::class)->act($this->access->cases()->findOrFail($id), $r, $this->access);

        return back()->with('status', 'Seguimiento guardado.');
    }

    public function file(int $id)
    {
        $this->access->require('personal.read');
        $f = $this->access->query(Attachment::class)->findOrFail($id);
        $this->access->cases()->findOrFail($f->application_id);
        $this->access->audit('file.downloaded', 'attachment:'.$id);

        return response(app(PrivateFiles::class)->get($f->path), 200, ['Content-Type' => $f->mime, 'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $f->name, 'archivo'), 'Cache-Control' => 'no-store']);
    }

    public static function csv(string $name, array $header, array $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, $header, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($f, array_map(function ($v) {
                    $v = (string) $v;

                    return preg_match('/^[\s]*[=+@\-]/u', $v) ? "'".$v : $v;
                }, $row), ',', '"', '');
            }fclose($f);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function export(Request $r)
    {
        $personal = $this->access->can('personal.read');
        $this->access->audit('cases.export', 'applications');

        return self::csv('solicitudes.csv', ['Folio', 'Persona', 'Correo', 'Área', 'Etapa', 'Responsable', 'SLA', 'Próxima acción', 'Plazo'], $this->query($r)->get()->map(fn ($c) => [$c->folio, $personal ? $c->applicant->name : 'Restringido', $personal ? $c->applicant->email : 'Restringido', $c->snapshot['area'], $c->stateConfig()['label'], $c->owner->user->name, $c->sla(), $c->next_action, $c->due_at->toIso8601String()])->all());
    }

    public function pdf(int $id)
    {
        $this->access->require('cases.read');
        $case = $this->access->cases()->with('applicant', 'owner.user')->findOrFail($id);
        $this->access->audit('case.pdf', 'application:'.$id);

        return Pdf::loadView('cases.pdf', ['case' => $case, 'personal' => $this->access->can('personal.read')])->download($case->folio.'.pdf');
    }

    public function reports()
    {
        $this->access->require('reports.read');
        $cases = $this->access->cases()->whereNull('archived_at')->with('owner.user', 'events')->get();
        $phases = [];
        foreach ($cases as $c) {
            $cursor = $c->created_at;
            $current = $c->snapshot['workflow']['initial'];
            foreach ($c->events->where('kind', 'transition')->sortBy('created_at') as $e) {
                $hours = $cursor->diffInHours($e->created_at, true);
                $key = $c->snapshot['workflow_id'].'/'.$current;
                $phases[$key]['label'] = collect($c->snapshot['workflow']['states'])->firstWhere('id', $current)['label'].' · v'.$c->snapshot['workflow_version'];
                $phases[$key]['hours'][] = $hours;
                $phases[$key]['count'] = count($phases[$key]['hours']);
                $cursor = $e->created_at;
                $current = $e->metadata['to'];
            }
        }$closed = $cases->filter(fn ($c) => $c->closed_at);
        $cycles = $closed->map(fn ($c) => $c->created_at->diffInDays($c->closed_at, true))->sort()->values();
        $median = $cycles->count() ? $cycles->median() : 0;
        $areas = $this->access->areas()->get()->map(fn ($a) => ['name' => $a->name, 'capacity' => $a->capacity, 'integrated' => $cases->where('area_id', $a->id)->whereNotNull('integration_date')->count()]);

        return view('reports', ['phases' => $phases, 'cases' => $cases, 'states' => $cases->groupBy(fn ($c) => $c->snapshot['workflow_id'].'/'.$c->state)->map(fn ($cs) => ['label' => $cs->first()->stateConfig()['label'].' · v'.$cs->first()->snapshot['workflow_version'], 'count' => $cs->count()]), 'loads' => $cases->whereNull('closed_at')->groupBy('owner_id'), 'median' => $median, 'areas' => $areas]);
    }

    public function notifications()
    {
        return view('notifications', ['records' => $this->access->query(InboxNotification::class)->where('membership_id', $this->access->member->id)->latest()->paginate(30)]);
    }

    public function read(int $id)
    {
        $this->access->query(InboxNotification::class)->where('membership_id', $this->access->member->id)->findOrFail($id)->update(['read_at' => now()]);

        return back();
    }

    public function audit()
    {
        $this->access->require('audit.read');

        return view('audit', ['records' => $this->access->query(AuditLog::class)->latest()->paginate(30)]);
    }

    public function technical()
    {
        $this->access->require('technical.read');

        return view('technical',['driver' => config('database.default'), 'files' => config('integra.files_driver')]);
    }
}
