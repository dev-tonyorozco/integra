<!doctype html><html lang="es"><head><meta charset="utf-8"><style>body{font:12px DejaVu Sans;color:#292d32}h1{color:#c8102e;font-size:23px}h2{font-size:16px}small{color:#59636c}.row{padding:9px 0;border-bottom:1px solid #e4dfdc}</style></head><body><h1>INTEGRA · Ficha de solicitud</h1><p>{{ $case->folio }} · {{ $case->created_at->format('d/m/Y') }}</p><h2>{{ $personal?$case->applicant->name:'Datos personales restringidos' }}</h2>
@if($personal)
<p>{{ $case->applicant->email }} · {{ $case->applicant->phone }}</p>
@endif
<p>Área: {{ $case->snapshot['area'] }} · {{ $case->stateConfig()['label'] }}</p><p>Responsable: {{ $case->owner->user->name }}</p><p>Próxima acción: {{ $case->next_action }}</p><p>Plazo: {{ $case->due_at->format('d/m/Y H:i') }} · {{ $case->sla() }}</p>
@if($personal)
@foreach($case->snapshot['questions'] as $q)
@if(empty($q['sensitive']))
<div class="row"><strong>{{ $q['label'] }}</strong><p>{{ is_array($case->answers[$q['id']]??null)?implode(', ',$case->answers[$q['id']]):($case->answers[$q['id']]??'Sin respuesta') }}</p></div>
@endif
@endforeach
@endif
@if($case->integration_date)
<p>Integración: {{ $case->integration_date->format('d/m/Y') }} · {{ $case->integration_function }}</p>
@endif
<p><small>Documento interno. Comparte sólo con personas autorizadas. Flujo v{{ $case->snapshot['workflow_version'] }}, cuestionario v{{ $case->snapshot['form_version'] }}.</small></p></body></html>
