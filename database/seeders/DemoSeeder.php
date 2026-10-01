<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Process;
use App\Models\Questionnaire;
use App\Models\Requirement;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Definitions;
use App\Services\Intake;
use App\Services\OrganizationSetup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('No generar demostración en producción.');
        }
        if (Organization::where('slug', 'comunidad-demo')->exists()) {
            return;
        }
        $org = app(OrganizationSetup::class)->create('Comunidad Demo', 'comunidad-demo');
        $members = [];
        foreach (['admin' => ['Ana Torres', 'ORG_ADMIN'], 'gestor' => ['Luis Mendoza', 'CASE_MANAGER'], 'procesos' => ['Diego Ramos', 'PROCESS_MANAGER'], 'consulta' => ['Carla Pérez', 'AREA_MANAGER'], 'tecnico' => ['Marco Díaz', 'TECHNICAL']] as $email => [$name,$code]) {
            $u = User::create(['name' => $name, 'email' => $email.'@integra.test', 'phone' => '5550000000', 'password' => 'IntegraDemo2026!']);
            $members[$email] = Membership::create(['organization_id' => $org->id, 'user_id' => $u->id, 'role_id' => Role::where('organization_id', $org->id)->where('code', $code)->firstOrFail()->id, 'area_ids' => [], 'login_enabled' => true]);
        }
        $contact = User::create(['name' => 'Marta Jiménez', 'email' => 'marta@integra.test', 'password' => Str::random(64)]);
        $leader = Membership::create(['organization_id' => $org->id, 'user_id' => $contact->id, 'area_ids' => [], 'login_enabled' => false]);
        $req = Requirement::create(['organization_id' => $org->id, 'family_id' => Str::uuid(), 'name' => 'Compromiso de participación', 'description' => 'Declaro mi disposición para participar en la orientación y formación del equipo.', 'published' => true]);
        $flow = Workflow::create(['organization_id' => $org->id, 'family_id' => Str::uuid(), 'name' => 'Integración acompañada', 'definition' => Definitions::defaultFlow(), 'published' => true]);
        $form = Questionnaire::create(['organization_id' => $org->id, 'family_id' => Str::uuid(), 'name' => 'Perfil inicial', 'questions' => [['id' => 'motivation', 'label' => '¿Qué te motiva a participar?', 'type' => 'paragraph', 'required' => true, 'sensitive' => false], ['id' => 'availability', 'label' => 'Disponibilidad', 'type' => 'select', 'required' => true, 'options' => ['Entre semana', 'Fin de semana', 'Flexible'], 'sensitive' => false], ['id' => 'personal_context', 'label' => '¿Hay algo personal que debamos considerar al acompañarte?', 'type' => 'paragraph', 'required' => false, 'sensitive' => true]], 'published' => true]);
        $test = Questionnaire::create(['organization_id' => $org->id, 'family_id' => Str::uuid(), 'name' => 'Preparación para servir', 'is_test' => true, 'questions' => [['id' => 'teamwork', 'label' => '¿Cómo prefieres aprender en equipo?', 'type' => 'select', 'options' => ['Con práctica', 'Con orientación', 'Ambas'], 'scores' => ['Con práctica' => 1, 'Con orientación' => 1, 'Ambas' => 2], 'required' => true, 'sensitive' => false]], 'published' => true]);
        $process = Process::create(['organization_id' => $org->id, 'name' => 'Primeros pasos de servicio', 'description' => 'Escucha, orientación e integración con decisión del líder.', 'workflow_id' => $flow->id, 'questionnaire_id' => $form->id, 'requirements' => [], 'test_ids' => [$test->id]]);
        $areas = [];
        $opps = [];
        foreach (['Bienvenida' => ['Recibe y acompaña a quienes llegan por primera vez.', 'bienvenida'], 'Música y alabanza' => ['Comparte tus habilidades musicales con el equipo.', 'musica'], 'Cocina y comunidad' => ['Sirve a través de alimentos y encuentros de comunidad.', 'cocina']] as $name => [$description,$key]) {
            $a = Area::create(['organization_id' => $org->id, 'name' => $name, 'description' => $description, 'normalized_name' => Str::lower(Str::ascii($name)), 'capacity' => 12, 'requirements' => [['id' => $req->id, 'mandatory' => true]]]);
            $a->contacts()->attach($leader->id, ['kind' => 'leader']);
            $areas[] = $a;
            $opps[] = Opportunity::create(['organization_id' => $org->id, 'slug' => Str::uuid(), 'name' => 'Únete al equipo de '.$name, 'description' => $description, 'area_id' => $a->id, 'process_id' => $process->id, 'owner_id' => $members['gestor']->id, 'published' => true]);
        }
        $members['consulta']->update(['area_ids' => [$areas[0]->id]]);
        foreach (['Daniel Herrera', 'Mariana López', 'Carlos Ruiz', 'Sofía Gómez', 'Jorge Castillo', 'Lucía Flores', 'Andrés Vega', 'Paola Moreno', 'Elena García'] as $i => $name) {
            $c = app(Intake::class)->submit($opps[$i % 3]->fresh(), ['name' => $name, 'email' => 'persona'.($i + 1).'@example.test', 'phone' => '5551234567', 'answers' => ['motivation' => 'Deseo crecer y contribuir al equipo.', 'availability' => 'Flexible'], 'requirements' => [$req->id => 1], 'consent' => 1]);
            if ($i < 3) {
                $c->update(['due_at' => now()->subHours(8 + $i * 24)]);
            }
        }
        $other = app(OrganizationSetup::class)->create('Comunidad Norte', 'comunidad-norte');
        Membership::create(['organization_id' => $other->id, 'user_id' => $members['admin']->user_id, 'role_id' => Role::where('organization_id', $other->id)->where('code', 'ORG_ADMIN')->firstOrFail()->id, 'area_ids' => [], 'login_enabled' => true]);
        Membership::create(['organization_id' => $other->id, 'user_id' => $leader->user_id, 'area_ids' => [], 'login_enabled' => false]);
    }
}
