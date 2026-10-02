<x-layout title="Auditoría"><x-heading title="Auditoría" subtitle="Registro inmutable de operaciones. Los datos personales permanecen en las fichas autorizadas."/><section class="card"><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Acción</th><th>Objeto</th><th>Actor</th><th>Metadatos</th></tr></thead><tbody>
@foreach($records as $e)
<tr><td>{{ $e->created_at->format('d/m/Y H:i') }}</td><td>{{ $e->action }}</td><td>{{ $e->target }}</td><td>{{ $e->actor_id }}</td><td>{{ json_encode($e->metadata,JSON_UNESCAPED_UNICODE) }}</td></tr>
@endforeach
</tbody></table></div>{{ $records->links() }}</section></x-layout>
