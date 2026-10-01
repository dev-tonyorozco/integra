<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Role;

class OrganizationSetup
{
    public function create(string $name, string $slug): Organization
    {
        $org = Organization::create(['name' => $name, 'slug' => $slug, 'privacy_notice' => 'Usaremos tus datos para orientar y dar seguimiento a tu solicitud de servicio. Contacta a la coordinación para ejercer tus derechos de acceso, rectificación o cancelación.']);
        $roles = ['ORG_ADMIN' => ['Administración', Definitions::PERMISSIONS, false], 'PROCESS_MANAGER' => ['Gestión de procesos', ['catalog.read', 'catalog.write'], false], 'CASE_MANAGER' => ['Gestión de solicitudes', ['cases.read', 'cases.write', 'personal.read', 'reports.read', 'catalog.read', 'tests.read'], false], 'REVIEWER' => ['Revisión', ['cases.read', 'personal.read', 'reports.read', 'catalog.read', 'tests.read'], false], 'AREA_MANAGER' => ['Responsable de área', ['cases.read', 'cases.write', 'personal.read', 'reports.read', 'catalog.read', 'tests.read'], true], 'TECHNICAL' => ['Soporte técnico', ['audit.read', 'technical.read'], false]];
        foreach ($roles as $code => [$label,$permissions,$scope]) {
            Role::create(['organization_id' => $org->id, 'code' => $code, 'name' => $label, 'permissions' => $permissions, 'area_scope' => $scope]);
        }

return $org;
    }
}
