<?php

use App\Models\Application;
use App\Models\InboxNotification;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationSetup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Process\Process;

Artisan::command('integra:bootstrap {--organization=Comunidad} {--slug=comunidad} {--name=Administrador} {--email=}', function () {
    $email = $this->option('email');
    $password = env('INTEGRA_ADMIN_PASSWORD') ?: $this->secret('Contraseña del administrador (12 caracteres, mayúscula, minúscula y número)');
    validator(['email' => $email, 'password' => $password], ['email' => 'required|email', 'password' => ['required', Password::min(12)->mixedCase()->numbers()]])->validate();
    DB::transaction(function () use ($email, $password) {
        $org = app(OrganizationSetup::class)->create($this->option('organization'), $this->option('slug'));
        $u = User::firstOrCreate(['email' => strtolower(trim($email))], ['name' => $this->option('name'), 'password' => $password]);
        $role = $org->id ? Role::where('organization_id', $org->id)->where('code', 'ORG_ADMIN')->firstOrFail() : null;
        Membership::create(['organization_id' => $org->id, 'user_id' => $u->id, 'role_id' => $role->id, 'area_ids' => [], 'login_enabled' => true]);
    });
    $this->info('Organización y administrador creados.');
})->purpose('Crear organización y administrador, sin datos de demostración');
Artisan::command('integra:database-prepare', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->info('SQLite no requiere esquema.');

        return;
    }$schema = config('database.connections.pgsql.search_path');
    if (! preg_match('/^[a-z][a-z0-9_]*$/', $schema) || in_array($schema, ['public', 'auth', 'storage', 'realtime'])) {
        throw new RuntimeException('Usa un esquema privado propio.');
    }DB::statement('CREATE SCHEMA IF NOT EXISTS "'.$schema.'"');
    DB::statement('REVOKE ALL ON SCHEMA "'.$schema.'" FROM PUBLIC');
    foreach (['anon', 'authenticated'] as $role) {
        if (DB::selectOne('SELECT 1 FROM pg_roles WHERE rolname=?', [$role])) {
            DB::statement('REVOKE ALL ON SCHEMA "'.$schema.'" FROM "'.$role.'"');
        }
    }$this->info('Esquema privado preparado.');
})->purpose('Preparar el esquema PostgreSQL privado');
Artisan::command('integra:supabase-check', function () {
    if (DB::getDriverName() !== 'pgsql') {
        throw new RuntimeException('Configura PostgreSQL primero.');
    }$schema = config('database.connections.pgsql.search_path');
    $actual = DB::selectOne('SELECT current_schema() AS name')->name;
    if ($actual !== $schema) {
        throw new RuntimeException('El esquema actual no coincide.');
    }$missing = DB::select('SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=? AND c.relkind=\'r\' AND c.relname<>\'migrations\' AND NOT c.relrowsecurity', [$schema]);
    if ($missing) {
        throw new RuntimeException('Hay tablas sin RLS.');
    }$this->info('Conexión, esquema y RLS correctos.');
    if (config('integra.files_driver') === 'supabase') {
        $key = config('integra.supabase_key');
        $response = Http::withHeaders(['apikey' => $key])->get(rtrim(config('integra.supabase_url'), '/').'/storage/v1/bucket/'.config('integra.bucket'))->throw();
        if ($response->json('public')) {
            throw new RuntimeException('El bucket debe ser privado.');
        }$this->info('Bucket privado confirmado.');
    }
})->purpose('Verificar PostgreSQL, RLS y bucket privado sin mostrar credenciales');
Artisan::command('integra:reminders', function () {
    Application::whereNull('closed_at')->whereNull('archived_at')->whereNull('pause_started')->where('due_at', '<=', now()->addDay())->chunkById(100, function ($cases) {
        foreach ($cases as $c) {
            $ids = [$c->owner_id];
            if ($c->due_at->lt(now()->subHours(48))) {
                $ids = array_merge($ids, Membership::where('organization_id', $c->organization_id)->where('active', true)->where('login_enabled', true)->whereHas('role', fn ($q) => $q->where('code', 'ORG_ADMIN')->where('active', true))->pluck('id')->all());
            }foreach (array_unique($ids) as $id) {
                InboxNotification::firstOrCreate(['dedupe_key' => $c->id.'-'.$id.'-'.$c->revision.'-'.now()->format('Ymd').'-'.($c->due_at->isPast() ? 'late' : 'soon')], ['organization_id' => $c->organization_id, 'membership_id' => $id, 'application_id' => $c->id, 'message' => $c->folio.': '.($c->due_at->isPast() ? 'plazo vencido' : 'plazo próximo')]);
            }
        }
    });
    $this->info('Recordatorios preparados.');
})->purpose('Avisos SLA idempotentes y escalamiento a administración');
Schedule::command('integra:reminders')->hourly()->withoutOverlapping();
Artisan::command('integra:backup', function () {
    Storage::disk('local')->makeDirectory('backups');
    $file = storage_path('app/private/backups/integra-'.now()->format('Ymd-His').'.'.(DB::getDriverName() === 'pgsql' ? 'dump' : 'sqlite'));
    if (DB::getDriverName() === 'sqlite') {
        $source = new SQLite3(config('database.connections.sqlite.database'));
        $dest = new SQLite3($file);
        if (! $source->backup($dest)) {
            throw new RuntimeException('No se pudo respaldar.');
        }$source->close();
        $dest->close();
    } else {
        $config = DB::connection()->getConfig();
        $p = new Process(['pg_dump', '--host='.$config['host'], '--port='.$config['port'], '--username='.$config['username'], '--dbname='.$config['database'], '--schema='.$config['search_path'], '--format=custom', '--no-owner', '--no-acl', '--file='.$file], null, ['PGPASSWORD' => $config['password'], 'PGSSLMODE' => $config['sslmode']]);
        $p->setTimeout(300)->mustRun();
    }$this->info('Respaldo creado en almacenamiento privado. Respalda adjuntos por separado.');
})->purpose('Respaldo privado del esquema de INTEGRA');
