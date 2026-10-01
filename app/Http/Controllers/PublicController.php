<?php

namespace App\Http\Controllers;

use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\PublicTask;
use App\Services\CaseWorkflow;
use App\Services\Intake;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    public function home(string $slug)
    {
        $org = Organization::where('slug', $slug)->where('active', true)->firstOrFail();
        $opportunities = Opportunity::with('area', 'process.workflow', 'process.questionnaire', 'owner.role', 'owner.user')->where('organization_id', $org->id)->where('active', true)->where('published', true)->get()->filter(fn ($o) => app(Intake::class)->open($o));

        return view('public.home', compact('org', 'opportunities'));
    }

    public function apply(string $slug)
    {
        $o = Opportunity::where('slug', $slug)->firstOrFail();
        abort_unless(app(Intake::class)->open($o), 410);
        $org = Organization::findOrFail($o->organization_id);
        $snapshot = app(Intake::class)->snapshot($o);

        return view('public.apply', compact('o', 'org', 'snapshot'));
    }

    public function submit(Request $r, string $slug)
    {
        $case = app(Intake::class)->submit(Opportunity::where('slug', $slug)->firstOrFail(), $r->all());
        $r->session()->flash('folio', $case->folio);

        return redirect()->route('receipt');
    }

    public function receipt(Request $r)
    {
        abort_unless($r->session()->has('folio'), 404);

        return view('public.receipt', ['folio' => $r->session()->get('folio')]);
    }

    public function task(string $token)
    {
        $task = PublicTask::where('token', $token)->firstOrFail();
        abort_if($task->completed_at || $task->expires_at->isPast() || $task->application->closed_at || $task->application->archived_at, 410);

        return view('public.task', compact('task'));
    }

    public function answer(Request $r, string $token)
    {
        app(CaseWorkflow::class)->answer(PublicTask::where('token', $token)->firstOrFail(), $r->input('answers',[]));

        return view('public.complete');
    }
}
