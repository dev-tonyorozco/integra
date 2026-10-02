<x-layout title="Reportes"><x-heading title="Reportes" subtitle="Indicadores calculados sobre las solicitudes de tu ámbito."><a class="secondary" href="{{ route('cases.export') }}">Exportar solicitudes</a></x-heading><div class="stats"><article><small>Total</small><strong>{{ $cases->count() }}</strong></article><article><small>Integradas</small><strong>{{ $cases->whereNotNull('integration_date')->count() }}</strong></article><article><small>Ciclo mediano</small><strong>{{ number_format($median,1) }}</strong><small>Días naturales en solicitudes finalizadas</small></article><article><small>Sin actividad 14 días</small><strong>{{ $cases->whereNull('closed_at')->filter(fn($c)=>$c->last_activity_at->lt(now()->subDays(14)))->count() }}</strong></article></div><div class="columns"><section class="card"><h2>Distribución por etapa y versión</h2>
@foreach($states as $state)
<div class="bar-row"><span>{{ $state['label'] }}</span><div class="bar"><span style="width:{{ $cases->count()?$state['count']/$cases->count()*100:0 }}%"></span></div><strong>{{ $state['count'] }}</strong></div>
@endforeach
</section><section class="card"><h2>Carga de trabajo</h2>
@foreach($loads as $group)
<div class="listitem"><strong>{{ $group->first()->owner->user->name }}</strong><p>{{ $group->count() }} abiertas · {{ $group->filter(fn($c)=>$c->sla()==='Vencida')->count() }} vencidas · {{ $group->filter(fn($c)=>$c->pause_started)->count() }} pausadas</p></div>
@endforeach
</section></div><section class="card"><h2>Tiempo por etapa completada</h2><div class="table-wrap"><table><thead><tr><th>Etapa y versión</th><th>Salidas registradas</th><th>Promedio en horas</th><th>Mediana en horas</th></tr></thead><tbody>
@forelse($phases as $phase)
<tr><td>{{ $phase['label'] }}</td><td>{{ $phase['count'] }}</td><td>{{ number_format(collect($phase['hours'])->avg(),1) }}</td><td>{{ number_format(collect($phase['hours'])->median(),1) }}</td></tr>
@empty
<tr><td colspan="4">No hay etapas completadas aún.</td></tr>
@endforelse
</tbody></table></div><small>Duración natural entre ingreso y salida; incluye pausas.</small></section><section class="card"><h2>Capacidad e integración</h2><div class="table-wrap"><table><thead><tr><th>Área</th><th>Capacidad registrada</th><th>Integradas</th><th>Disponibilidad orientativa</th></tr></thead><tbody>
@foreach($areas as $a)
<tr><td>{{ $a['name'] }}</td><td>{{ $a['capacity'] }}</td><td>{{ $a['integrated'] }}</td><td>{{ max(0,$a['capacity']-$a['integrated']) }}</td></tr>
@endforeach
</tbody></table></div><small>La capacidad corresponde a esta instalación; no sustituye la revisión del líder. Los ciclos incluyen pausas y no descuentan festivos.</small></section></x-layout>
