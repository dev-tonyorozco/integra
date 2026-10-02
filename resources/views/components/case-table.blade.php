@props(['records'])
<div class="table-wrap"><table><thead><tr><th>Solicitud</th><th>Área y etapa</th><th>Responsable</th><th>Seguimiento</th></tr></thead><tbody>
@forelse($records as $c)
<tr><td><a class="folio" href="{{ route('cases.show',$c->id) }}">{{ $c->folio }}</a><strong>{{ $access->can('personal.read')?$c->applicant->name:'Datos restringidos' }}</strong><small>{{ $c->created_at->format('d/m/Y') }}</small></td><td>{{ $c->snapshot['area'] }}<span class="pill">{{ $c->stateConfig()['label'] }}</span></td><td>{{ $c->owner->user->name }}</td><td><span class="status {{ $c->sla()==='Vencida'?'danger':'' }}">{{ $c->sla() }}</span><small>{{ $c->next_action }}</small><small>{{ $c->due_at->format('d/m/Y H:i') }}</small></td></tr>
@empty
<tr><td colspan="4" class="empty">No hay solicitudes con estos filtros.</td></tr>
@endforelse
</tbody></table></div>
