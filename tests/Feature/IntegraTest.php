<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Area;
use App\Models\Attachment;
use App\Models\CaseEvent;
use App\Models\InboxNotification;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\Process;
use App\Models\PublicTask;
use App\Models\Questionnaire;
use App\Models\Requirement;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Definitions;
use App\Services\Intake;
use App\Services\PrivateFiles;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IntegraTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->admin = User::where('email', 'admin@integra.test')->firstOrFail();
    }

    private function login(string $email = 'admin@integra.test'): User
    {
        $u = User::where('email', $email)->firstOrFail();
        $this->actingAs($u);
        $this->withSession(['membership_id' => $u->memberships()->where('login_enabled', true)->orderBy('id')->firstOrFail()->id, 'password_hash_web' => $u->getAuthPassword()]);

        return $u;
    }

    private function case(): Application
    {
        return Application::firstOrFail();
    }

    private function intakeData(): array
    {
        return ['name' => 'Persona de prueba', 'email' => 'nueva@example.test', 'phone' => '5551234567', 'answers' => ['motivation' => 'Deseo participar', 'availability' => 'Flexible'], 'requirements' => [Requirement::first()->id => 1], 'consent' => 1];
    }

    private function action(string $action, array $data = [], ?Application $c = null)
    {
        $c ??= $this->case();

        return $this->post('/app/cases/'.$c->id, array_merge(['action' => $action, 'revision' => $c->revision], $data));
    }

    public function test_public_portal_and_application_render(): void
    {
        $this->get('/c/comunidad-demo')->assertOk()->assertSee('Bienvenida');
        $this->get('/apply/'.Opportunity::first()->slug)->assertOk()->assertSee('Uso de tus datos');
    }

    public function test_every_admin_page_and_catalog_form_renders(): void
    {
        $this->login();
        foreach (['/app', '/app/cases', '/app/users', '/app/users/new', '/app/reports', '/app/notifications', '/app/audit', '/app/technical'] as $url) {
            $this->get($url)->assertOk();
        }foreach (['roles', 'areas', 'workflows', 'questionnaires', 'tests', 'requirements', 'processes', 'opportunities'] as $catalog) {
            $this->get('/app/catalog/'.$catalog)->assertOk();
            $this->get('/app/catalog/'.$catalog.'/new')->assertOk();
        }
    }

    public function test_intake_persists_consent_and_snapshot(): void
    {
        $o = Opportunity::first();
        $this->post('/apply/'.$o->slug, $this->intakeData())->assertRedirect('/receipt');
        $c = Application::latest('id')->first();
        $this->assertSame('received', $c->state);
        $this->assertNotNull($c->consent_at);
        $this->assertSame(1, $c->snapshot['form_version']);
        $this->get('/receipt')->assertOk()->assertSee($c->folio);
    }

    public function test_intake_requires_consent_and_mandatory_requirements(): void
    {
        $d = $this->intakeData();
        unset($d['consent']);
        $this->post('/apply/'.Opportunity::first()->slug, $d)->assertSessionHasErrors('consent');
        $d = $this->intakeData();
        $d['requirements'] = [];
        $this->post('/apply/'.Opportunity::first()->slug, $d)->assertSessionHasErrors('definition');
        $this->assertSame(9, Application::count());
    }

    public function test_choices_and_unknown_answers_are_rejected(): void
    {
        $d = $this->intakeData();
        $d['answers']['availability'] = 'Nunca';
        $this->post('/apply/'.Opportunity::first()->slug, $d)->assertSessionHasErrors();
        $d = $this->intakeData();
        $d['answers']['admin'] = true;
        $this->post('/apply/'.Opportunity::first()->slug, $d)->assertSessionHasErrors();
    }

    public function test_closed_opportunity_and_inactive_owner_reject_intake(): void
    {
        $o = Opportunity::first();
        $o->update(['closes_at' => now()->subMinute()]);
        $this->get('/apply/'.$o->slug)->assertGone();
        $o->update(['closes_at' => null]);
        $o->owner->update(['login_enabled' => false]);
        $this->post('/apply/'.$o->slug, $this->intakeData())->assertGone();
    }

    public function test_duplicate_is_scoped_to_organization(): void
    {
        $d = $this->intakeData();
        $o = Opportunity::first();
        $a = app(Intake::class)->submit($o, $d);
        $b = app(Intake::class)->submit($o, $d);
        $this->assertSame($a->id, $b->duplicate_of);
    }

    public function test_login_works_and_contact_cannot_login(): void
    {
        $this->post('/login', ['email' => 'admin@integra.test', 'password' => 'IntegraDemo2026!'])->assertRedirect('/app');
        $this->assertAuthenticatedAs($this->admin);
        $this->post('/logout');
        $this->post('/login', ['email' => 'marta@integra.test', 'password' => 'IntegraDemo2026!'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_native_password_reset_requires_enabled_access(): void
    {
        Notification::fake();
        $this->post('/password/email', ['email' => 'admin@integra.test'])->assertRedirect();
        Notification::assertSentTo($this->admin, ResetPassword::class);
        $this->post('/password/email', ['email' => 'marta@integra.test']);
        Notification::assertNotSentTo(User::where('email', 'marta@integra.test')->first(), ResetPassword::class);
    }

    public function test_organization_switch_is_authorized_and_isolated(): void
    {
        $this->login();
        $north = $this->admin->memberships()->whereHas('organization', fn ($q) => $q->where('slug', 'comunidad-norte'))->first();
        $this->post('/switch', ['membership_id' => $north->id])->assertRedirect();
        $this->get('/app/cases')->assertOk()->assertDontSee($this->case()->folio);
        $this->get('/app/cases/'.$this->case()->id)->assertNotFound();
        $this->post('/switch', ['membership_id' => Membership::where('login_enabled', false)->first()->id])->assertNotFound();
    }

    public function test_area_scope_restricts_cases_and_reports(): void
    {
        $this->login('consulta@integra.test');
        $allowed = $this->case();
        $denied = Application::where('area_id', '!=', $allowed->area_id)->first();
        $this->get('/app/cases')->assertOk()->assertSee($allowed->folio)->assertDontSee($denied->folio);
        $this->get('/app/cases/'.$denied->id)->assertNotFound();
        $this->get('/app/reports')->assertOk()->assertDontSee('Música y alabanza');
    }

    public function test_technical_role_has_no_personal_or_catalog_access(): void
    {
        $this->login('tecnico@integra.test');
        $this->get('/app/technical')->assertOk();
        $this->get('/app/audit')->assertOk();
        foreach (['/app/cases', '/app/users', '/app/reports', '/app/catalog/areas'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_inactive_role_blocks_operations_but_allows_logout(): void
    {
        $this->login('gestor@integra.test')->memberships()->first()->role->update(['active' => false]);
        $this->get('/app/cases')->assertForbidden()->assertSee('Acceso no disponible');
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_contact_without_email_has_no_login_or_role(): void
    {
        $this->login();
        $this->post('/app/users/new', ['name' => 'Contacto local', 'phone' => '555', 'active' => 1])->assertRedirect('/app/users');
        $m = Membership::latest('id')->first();
        $this->assertFalse($m->login_enabled);
        $this->assertNull($m->role_id);
        $this->assertNull($m->user->email);
    }

    public function test_login_requires_email_and_area_scope(): void
    {
        $this->login();
        $role = Role::where('code', 'AREA_MANAGER')->first();
        $this->post('/app/users/new', ['name' => 'Persona', 'active' => 1, 'login_enabled' => 1, 'role_id' => $role->id])->assertSessionHasErrors('email');
        $this->post('/app/users/new', ['name' => 'Persona', 'email' => 'scope@example.test', 'active' => 1, 'login_enabled' => 1, 'role_id' => $role->id, 'area_ids' => []])->assertSessionHasErrors('area_ids');
    }

    public function test_disabling_access_preserves_role_and_areas(): void
    {
        $this->login();
        $m = User::where('email', 'consulta@integra.test')->first()->memberships()->first();
        $this->post('/app/users/'.$m->id, ['name' => $m->user->name, 'email' => $m->user->email, 'role_id' => $m->role_id, 'area_ids' => $m->area_ids, 'active' => 1])->assertRedirect();
        $new = $m->fresh();
        $this->assertFalse($new->login_enabled);
        $this->assertSame($m->role_id, $new->role_id);
        $this->assertSame($m->area_ids, $new->area_ids);
    }

    public function test_global_identity_reuse_does_not_grant_login_or_change_name(): void
    {
        $this->login();
        $north = $this->admin->memberships()->whereHas('organization', fn ($q) => $q->where('slug', 'comunidad-norte'))->first();
        $this->post('/switch', ['membership_id' => $north->id]);
        $this->post('/app/users/new', ['name' => 'Nombre alterado', 'email' => 'gestor@integra.test', 'active' => 1, 'login_enabled' => 1, 'role_id' => $north->role_id])->assertRedirect();
        $u = User::where('email', 'gestor@integra.test')->first();
        $m = $u->memberships()->where('organization_id', $north->organization_id)->first();
        $this->assertSame('Luis Mendoza', $u->name);
        $this->assertFalse($m->login_enabled);
        $this->assertNull($m->role_id);
    }

    public function test_last_active_administrator_is_protected(): void
    {
        $this->login();
        $m = $this->admin->memberships()->first();
        $this->post('/app/users/'.$m->id, ['name' => $this->admin->name, 'email' => $this->admin->email, 'role_id' => $m->role_id, 'area_ids' => [], 'active' => 1])->assertSessionHasErrors('role_id');
        $this->assertTrue($m->fresh()->login_enabled);
    }

    public function test_access_filter_and_export_share_rows(): void
    {
        $this->login();
        $this->get('/app/users?access=no')->assertOk()->assertSee('Marta Jiménez')->assertDontSee('Luis Mendoza');
        $r = $this->get('/app/users/export?access=no')->assertOk();
        $csv = $r->streamedContent();
        $this->assertStringContainsString('Marta Jiménez', $csv);
        $this->assertStringNotContainsString('Luis Mendoza', $csv);
    }

    public function test_search_is_accent_insensitive_and_folio_case_insensitive(): void
    {
        $this->login();
        $this->get('/app/catalog/areas?q=MUSICA')->assertOk()->assertSee('Música y alabanza')->assertDontSee('Cocina y comunidad');
        $c = $this->case();
        $this->get('/app/cases?q='.strtolower($c->folio))->assertOk()->assertSee($c->folio);
        $r = $this->get('/app/catalog/areas/export?q=MUSICA');
        $this->assertStringContainsString('Música y alabanza', $r->streamedContent());
    }

    public function test_areas_limit_two_leaders_and_normalize_name_uniqueness(): void
    {
        $this->login();
        $a = Area::first();
        $m = Membership::where('organization_id', $a->organization_id)->limit(3)->pluck('id')->all();
        $this->post('/app/catalog/areas/'.$a->id, ['name' => $a->name, 'capacity' => 10, 'active' => 1, 'leaders' => $m])->assertSessionHasErrors('leaders');
        $this->post('/app/catalog/areas/new', ['name' => 'MUSICA Y ALABANZA', 'capacity' => 10, 'active' => 1])->assertSessionHasErrors('name');
    }

    public function test_published_definitions_are_immutable_and_duplicate_creates_version(): void
    {
        $this->login();
        $flow = Workflow::first();
        $this->post('/app/catalog/workflows/'.$flow->id, ['name' => 'Cambio', 'definition' => json_encode(Definitions::defaultFlow())])->assertUnprocessable();
        $this->post('/app/catalog/workflows/'.$flow->id.'/duplicate')->assertRedirect();
        $new = Workflow::latest('id')->first();
        $this->assertSame(2, $new->version);
        $this->assertFalse($new->published);
        $this->assertSame($flow->family_id, $new->family_id);
        $this->assertSame(1, $this->case()->snapshot['workflow_version']);
    }

    public function test_invalid_flow_and_integration_without_leader_are_rejected(): void
    {
        $this->login();
        $d = Definitions::defaultFlow();
        $d['transitions'][2]['leader_decision'] = false;
        $this->post('/app/catalog/workflows/new', ['name' => 'Inválido', 'active' => 1, 'definition' => json_encode($d)])->assertSessionHasErrors('definition');
        $d = Definitions::defaultFlow();
        $d['initial'] = 'missing';
        $this->post('/app/catalog/workflows/new', ['name' => 'Inválido', 'definition' => json_encode($d)])->assertSessionHasErrors();
    }

    public function test_transition_checks_current_origin_and_revision(): void
    {
        $this->login();
        $c = $this->case();
        $this->action('transition', ['to' => 'integrated', 'body' => 'No permitido'], $c)->assertForbidden();
        $this->action('transition', ['to' => 'conversation', 'body' => 'Contacto realizado'], $c)->assertRedirect();
        $this->assertSame('conversation', $c->fresh()->state);
        $this->action('note', ['body' => 'Edición vieja'], $c)->assertStatus(409);
    }

    public function test_integration_requires_documented_leader_function_and_date(): void
    {
        $this->login();
        $c = $this->case();
        $c->update(['state' => 'leader']);
        $this->action('transition', ['to' => 'integrated', 'body' => 'Acuerdo'], $c)->assertSessionHasErrors('leader_decision');
        $this->action('transition', ['to' => 'integrated', 'body' => 'Acuerdo', 'leader_decision' => 'Acepta'], $c)->assertSessionHasErrors('integration_function');
        $this->action('transition', ['to' => 'integrated', 'body' => 'Acuerdo', 'leader_decision' => 'Acepta', 'integration_function' => 'Recepción', 'integration_date' => '2026-10-02'], $c)->assertRedirect();
        $this->assertNotNull($c->fresh()->closed_at);
        $this->assertSame('integrated', $c->fresh()->state);
    }

    public function test_reorientation_keeps_case_open(): void
    {
        $this->login();
        $c = $this->case();
        $c->update(['state' => 'leader']);
        $this->action('transition', ['to' => 'conversation', 'body' => 'Explorar otro servicio', 'leader_decision' => 'Reorientar'], $c)->assertRedirect();
        $this->assertNull($c->fresh()->closed_at);
        $this->assertSame('conversation', $c->fresh()->state);
    }

    public function test_pause_resume_compensates_deadline(): void
    {
        $this->login();
        $c = $this->case();
        $old = $c->due_at;
        $this->action('pause', ['body' => 'Disponibilidad personal'], $c)->assertRedirect();
        $this->travel(2)->hours();
        $this->action('transition', ['to' => 'conversation', 'body' => 'Prueba'], $c->fresh())->assertUnprocessable();
        $this->action('resume', [], $c->fresh())->assertRedirect();
        $this->assertGreaterThanOrEqual(7199, $old->diffInSeconds($c->fresh()->due_at));
    }

    public function test_owner_must_have_active_operational_access(): void
    {
        $this->login();
        $contact = Membership::where('login_enabled', false)->first();
        $this->action('followup', ['owner_id' => $contact->id, 'next_action' => 'Llamar', 'due_at' => now()->addDay()->toIso8601String()])->assertUnprocessable();
    }

    public function test_task_link_single_use_and_score_does_not_advance_case(): void
    {
        $this->login();
        $c = $this->case();
        $test = Questionnaire::where('is_test', true)->first();
        $this->action('task', ['questionnaire_id' => $test->id], $c)->assertRedirect();
        $task = PublicTask::first();
        $this->post('/tasks/'.$task->token, ['answers' => ['teamwork' => 'Ambas']])->assertOk();
        $this->assertSame(2, $task->fresh()->score);
        $this->assertSame('received', $c->fresh()->state);
        $this->post('/tasks/'.$task->token, ['answers' => ['teamwork' => 'Ambas']])->assertGone();
    }

    public function test_expired_task_rejects_read_and_submit(): void
    {
        $c = $this->case();
        $t = PublicTask::create(['organization_id' => $c->organization_id, 'application_id' => $c->id, 'token' => Str::uuid(), 'name' => 'Vencida', 'questions' => [], 'expires_at' => now()->subMinute()]);
        $this->get('/tasks/'.$t->token)->assertGone();
        $this->post('/tasks/'.$t->token, ['answers' => []])->assertGone();
    }

    public function test_interviews_findings_and_upload_are_private(): void
    {
        $this->login();
        $c = $this->case();
        $this->action('findings', ['body' => 'Hallazgo confidencial'], $c)->assertRedirect();
        $this->action('interview', ['scheduled_at' => now()->addDay()->toIso8601String(), 'interviewer' => 'Ana', 'location' => 'Sala 1', 'status' => 'scheduled', 'result' => 'Resultado privado'], $c->fresh())->assertRedirect();
        $this->action('attachment', ['file' => UploadedFile::fake()->create('ficha.pdf', 20, 'application/pdf')], $c->fresh())->assertRedirect();
        $f = Attachment::first();
        $this->get('/app/files/'.$f->id)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->login('tecnico@integra.test');
        $this->get('/app/files/'.$f->id)->assertForbidden();
    }

    public function test_upload_rejects_invalid_format_and_size(): void
    {
        $this->login();
        $this->action('attachment', ['file' => UploadedFile::fake()->create('script.php', 1, 'text/plain')])->assertSessionHasErrors('file');
        $this->action('attachment', ['file' => UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf')])->assertSessionHasErrors('file');
    }

    public function test_supabase_private_storage_uses_server_key_and_authenticated_path(): void
    {
        config(['integra.files_driver' => 'supabase', 'integra.supabase_url' => 'https://example.supabase.co', 'integra.supabase_key' => 'sb_secret_testing']);
        Http::fake(['*' => Http::response('bytes', 200)]);
        $files = app(PrivateFiles::class);
        $files->put('1/2/file.pdf', 'bytes', 'application/pdf');
        $this->assertSame('bytes', $files->get('1/2/file.pdf'));
        Http::assertSent(fn ($r) => $r->hasHeader('apikey', 'sb_secret_testing') && str_contains($r->url(), '/object/authenticated/integra-private/1/2/file.pdf'));
        $this->get('/login')->assertDontSee('sb_secret_testing');
    }

    public function test_reminders_are_idempotent_and_skip_paused_cases(): void
    {
        $c = $this->case();
        $c->update(['pause_started' => now()]);
        $this->artisan('integra:reminders')->assertSuccessful();
        $n = InboxNotification::count();
        $this->artisan('integra:reminders')->assertSuccessful();
        $this->assertSame($n, InboxNotification::count());
        $this->assertFalse(InboxNotification::where('application_id', $c->id)->whereNotNull('dedupe_key')->exists());
    }

    public function test_business_day_deadline_skips_weekend(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 10:00:00'));
        $this->assertSame('2026-10-05', Definitions::due(['sla' => 1, 'unit' => 'business_days'])->format('Y-m-d'));
    }

    public function test_history_cannot_be_edited(): void
    {
        $event = CaseEvent::first();
        $this->expectException(HttpException::class);
        $event->update(['body' => 'Manipulado']);
    }

    public function test_csv_neutralizes_formulas_and_does_not_export_answers(): void
    {
        $this->login();
        $c = $this->case();
        $c->applicant->update(['name' => '=HYPERLINK("evil")']);
        $csv = $this->get('/app/cases/export')->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Deseo crecer y contribuir', $csv);
    }

    public function test_pdf_and_qr_downloads_are_real_and_scoped(): void
    {
        $this->login();
        $this->get('/app/cases/'.$this->case()->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/app/catalog/opportunities/'.Opportunity::first()->id.'/qr')->assertOk()->assertSee('<svg', false);
    }

    public function test_closed_case_allows_only_archive(): void
    {
        $this->login();
        $c = $this->case();
        $c->update(['closed_at' => now(), 'state' => 'closed']);
        $this->action('note', ['body' => 'No'], $c)->assertUnprocessable();
        $this->action('archive', [], $c)->assertRedirect();
        $this->assertNotNull($c->fresh()->archived_at);
    }

    public function test_private_pages_have_security_headers(): void
    {
        $this->login();
        $this->get('/app')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_non_admin_transition_roles_are_enforced(): void
    {
        $this->login('gestor@integra.test');
        $c = $this->case();
        $c->update(['state' => 'leader']);
        $this->action('transition', ['to' => 'integrated', 'body' => 'Prueba', 'leader_decision' => 'Sí', 'integration_function' => 'Servicio', 'integration_date' => '2026-10-02'], $c)->assertForbidden();
    }

    public function test_reviewer_reads_only_assigned_cases_and_cannot_edit(): void
    {
        $u = $this->login('consulta@integra.test');
        $m = $u->memberships()->first();
        $m->update(['role_id' => Role::where('organization_id', $m->organization_id)->where('code', 'REVIEWER')->first()->id, 'area_ids' => []]);
        $c = $this->case();
        $c->update(['owner_id' => $m->id]);
        $this->get('/app/cases/'.$c->id)->assertOk();
        $this->get('/app/cases/'.Application::where('id', '!=', $c->id)->first()->id)->assertNotFound();
        $this->action('note', ['body' => 'No permitido'], $c)->assertForbidden();
    }

    public function test_no_personal_permission_masks_profile_pdf_and_csv(): void
    {
        $u = $this->login('gestor@integra.test');
        $role = $u->memberships()->first()->role;
        $role->update(['permissions' => ['cases.read', 'reports.read']]);
        $c = $this->case();
        $this->get('/app/cases/'.$c->id)->assertOk()->assertDontSee($c->applicant->email)->assertDontSee($c->applicant->name);
        $csv = $this->get('/app/cases/export')->streamedContent();
        $this->assertStringNotContainsString($c->applicant->email, $csv);
        $this->assertStringContainsString('Restringido', $csv);
    }

    public function test_scope_restricts_private_file_downloads(): void
    {
        $this->login();
        $c = Application::where('area_id', '!=', Area::first()->id)->first();
        $this->action('attachment', ['file' => UploadedFile::fake()->create('privado.pdf', 20, 'application/pdf')], $c)->assertRedirect();
        $f = Attachment::first();
        $this->login('consulta@integra.test');
        $this->get('/app/files/'.$f->id)->assertNotFound();
    }

    public function test_contact_assignment_does_not_enable_login(): void
    {
        $this->login();
        $a = Area::first();
        $contact = Membership::where('login_enabled', false)->first();
        $this->post('/app/catalog/areas/'.$a->id, ['name' => $a->name, 'capacity' => 12, 'active' => 1, 'leaders' => [$contact->id], 'contacts' => [$contact->id]])->assertRedirect();
        $this->assertFalse($contact->fresh()->login_enabled);
        $this->assertNull($contact->fresh()->role_id);
        $this->assertSame(2, $a->contacts()->count());
    }

    public function test_old_questionnaire_snapshot_survives_new_publication(): void
    {
        $this->login();
        $old = Questionnaire::where('is_test', false)->first();
        $c = $this->case();
        $this->post('/app/catalog/questionnaires/'.$old->id.'/duplicate')->assertRedirect();
        $new = Questionnaire::where('family_id', $old->family_id)->where('version', 2)->firstOrFail();
        $this->assertFalse($new->published);
        $new->update(['questions' => [['id' => 'new_question', 'label' => 'Pregunta nueva', 'type' => 'text', 'required' => true]], 'published' => true]);
        $process = Process::first();
        $process->update(['questionnaire_id' => $new->id]);
        $this->assertSame($old->id, $c->fresh()->snapshot['form_id']);
        $this->assertSame('motivation', $c->fresh()->snapshot['questions'][0]['id']);
    }

    public function test_editing_membership_does_not_reactivate_global_identity(): void
    {
        $this->login();
        $user = User::where('email', 'marta@integra.test')->firstOrFail();
        $user->update(['active' => false]);
        $member = $user->memberships()->first();
        $this->post('/app/users/'.$member->id, ['name' => $user->name, 'email' => $user->email, 'active' => 1, 'area_ids' => []])->assertRedirect();
        $this->assertFalse($user->fresh()->active);
    }
}
