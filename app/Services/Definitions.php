<?php

namespace App\Services;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Definitions
{
    public const PERMISSIONS = ['cases.read', 'cases.write', 'personal.read', 'reports.read', 'catalog.read', 'catalog.write', 'directory.write', 'audit.read', 'technical.read', 'tests.read'];

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['definition' => $message]);
    }

    public static function defaultFlow(): array
    {
        return ['initial' => 'received', 'states' => [['id' => 'received', 'label' => 'Recibida', 'sla' => 48, 'unit' => 'hours', 'action' => 'Contactar a la persona', 'terminal' => false], ['id' => 'conversation', 'label' => 'Conversación', 'sla' => 5, 'unit' => 'business_days', 'action' => 'Entrevista y orientación', 'terminal' => false], ['id' => 'leader', 'label' => 'Revisión del líder', 'sla' => 3, 'unit' => 'business_days', 'action' => 'Registrar decisión del líder', 'terminal' => false], ['id' => 'integrated', 'label' => 'Integrada', 'sla' => 0, 'unit' => 'hours', 'action' => 'Integración registrada', 'terminal' => true, 'outcome' => 'integrated'], ['id' => 'closed', 'label' => 'Cerrada', 'sla' => 0, 'unit' => 'hours', 'action' => 'Cierre registrado', 'terminal' => true, 'outcome' => 'closed']], 'transitions' => [['from' => 'received', 'to' => 'conversation', 'roles' => ['ORG_ADMIN', 'CASE_MANAGER', 'AREA_MANAGER']], ['from' => 'conversation', 'to' => 'leader', 'roles' => ['ORG_ADMIN', 'CASE_MANAGER', 'AREA_MANAGER']], ['from' => 'leader', 'to' => 'integrated', 'roles' => ['ORG_ADMIN', 'AREA_MANAGER'], 'leader_decision' => true], ['from' => 'leader', 'to' => 'conversation', 'roles' => ['ORG_ADMIN', 'CASE_MANAGER', 'AREA_MANAGER'], 'leader_decision' => true], ['from' => 'leader', 'to' => 'closed', 'roles' => ['ORG_ADMIN', 'CASE_MANAGER', 'AREA_MANAGER'], 'leader_decision' => true]]];
    }

    public static function flow(array $d): array
    {
        if (! is_array($d['states'] ?? null) || ! is_array($d['transitions'] ?? null) || count($d['states']) < 2 || count($d['states']) > 50) {
            self::fail('El flujo necesita entre 2 y 50 etapas.');
        }
        $ids = [];
        $final = false;
        foreach ($d['states'] as $s) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $s['id'] ?? '') || in_array($s['id'], $ids) || ! is_string($s['label'] ?? null) || strlen($s['label']) > 150 || ! trim($s['label'])) {
                self::fail('Identificadores únicos y nombres obligatorios.');
            }$ids[] = $s['id'];
            if (! in_array($s['unit'] ?? '', ['hours', 'business_days']) || ! is_numeric($s['sla'] ?? null) || $s['sla'] < 0 || $s['sla'] > 3650) {
                self::fail('Plazo inválido.');
            }$final |= ! empty($s['terminal']);
            if (! empty($s['terminal']) && ! in_array($s['outcome'] ?? '', ['integrated', 'closed'])) {
                self::fail('Las etapas finales requieren un resultado.');
            }
        }
        $initial = collect($d['states'])->firstWhere('id', $d['initial'] ?? '');
        if (! $initial || ! empty($initial['terminal']) || ! $final) {
            self::fail('Selecciona una etapa inicial y al menos una final.');
        }
        $edges = [];
        foreach ($d['transitions'] as $t) {
            $from = collect($d['states'])->firstWhere('id', $t['from'] ?? '');
            $to = collect($d['states'])->firstWhere('id', $t['to'] ?? '');
            if (! $from || ! $to || $from['id'] === $to['id'] || ! empty($from['terminal']) || empty($t['roles']) || ! is_array($t['roles'])) {
                self::fail('Transición inválida.');
            }if (($to['outcome'] ?? '') === 'integrated' && empty($t['leader_decision'])) {
                self::fail('Integrar exige decisión documentada del líder.');
            }$key = $t['from'].'/'.$t['to'];
            if (isset($edges[$key])) {
                self::fail('Transición repetida.');
            }$edges[$key] = true;
        }
        $reachable = [$d['initial']];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($d['transitions'] as $t) {
                if (in_array($t['from'], $reachable) && ! in_array($t['to'], $reachable)) {
                    $reachable[] = $t['to'];
                }
            }
        }foreach ($d['states'] as $s) {
            if (! in_array($s['id'], $reachable)) {
                self::fail('Todas las etapas deben ser alcanzables.');
            }if (empty($s['terminal']) && ! collect($d['transitions'])->contains('from', $s['id'])) {
                self::fail('Cada etapa abierta necesita una salida.');
            }
        }

return $d;
    }

    public static function questions(array $questions): array
    {
        if (count($questions) > 100) {
            self::fail('Máximo 100 preguntas.');
        }$ids = [];
        foreach ($questions as $q) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $q['id'] ?? '') || in_array($q['id'], $ids) || ! trim($q['label'] ?? '') || strlen($q['label']) > 500 || ! in_array($q['type'] ?? '', ['text', 'paragraph', 'select', 'multiselect', 'number', 'date', 'checkbox'])) {
                self::fail('Pregunta inválida.');
            }$ids[] = $q['id'];
            if (in_array($q['type'], ['select', 'multiselect']) && (empty($q['options']) || count($q['options']) !== count(array_unique($q['options'])))) {
                self::fail('Opciones únicas obligatorias.');
            }foreach ($q['scores'] ?? [] as $value) {
                if (! is_numeric($value) || abs($value) > 1000) {
                    self::fail('Puntaje inválido.');
                }
            }
        }

return $questions;
    }

    public static function answers(array $qs, array $answers): array
    {
        $rules = [];
        foreach ($qs as $q) {
            $rules[$q['id']] = [! empty($q['required']) ? 'required' : 'nullable'];
            $rules[$q['id']][] = match ($q['type']) {
                'number' => 'numeric','date' => 'date','checkbox' => 'accepted','multiselect' => 'array',default => 'string'
            };
            if (in_array($q['type'], ['select', 'multiselect'])) {
                if ($q['type'] === 'select') {
                    $rules[$q['id']][] = Rule::in($q['options']);
                } else {
                    $rules[$q['id'].'.*'] = [Rule::in($q['options'])];
                }
            }if (in_array($q['type'], ['text', 'paragraph'])) {
                $rules[$q['id']][] = 'max:10000';
            }
        }
        if (array_diff(array_keys($answers), array_column($qs, 'id'))) {
            self::fail('Respuesta desconocida.');
        }

return validator($answers, $rules)->validate();
    }

    public static function due(array $state)
    {
        $date = now();
        $n = (int) ($state['sla'] ?? 48);
        if (($state['unit'] ?? 'hours') === 'business_days') {
            for ($i = 0; $i < $n; $i++) {
                do {
                    $date->addDay();
                } while ($date->isWeekend());
            }

return $date;
        }

return $date->addHours($n);
    }

    public static function score(array $qs, array $answers): int
    {
        $score = 0;
        foreach ($qs as $q) {
            foreach ((array) ($answers[$q['id']] ?? []) as $a) {
                $score += (int) ($q['scores'][(string) $a] ?? 0);
            }
        }

return $score;
    }
}
