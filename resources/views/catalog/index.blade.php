<x-layout :title="$title"><x-heading :title="$title" subtitle="Configuración de tu organización."><a class="secondary" href="{{ route('catalog.export',array_merge(['catalog'=>$catalog],request()->query())) }}">Exportar CSV</a>
@if($access->can('catalog.write'))
<a class="button" href="{{ route('catalog.new',$catalog) }}"><i data-lucide="plus"></i>Agregar</a>
@endif
</x-heading><form class="filters"><input name="q" aria-label="Buscar" placeholder="Buscar por nombre o descripción" value="{{ request('q') }}"><select name="status" aria-label="Estado"><option value="">Todos los estados</option><option value="active" @selected(request('status')==='active')>Activos</option><option value="inactive" @selected(request('status')==='inactive')>Inactivos</option></select><button class="secondary">Filtrar</button></form><div class="catalog-grid">
@forelse($records as $record)
<article class="card"><div class="sectionhead"><h2>{{ $record->name }}</h2><span class="pill">{{ isset($record->version)?'v'.$record->version:($record->active?'Activo':'Inactivo') }}</span></div><p class="muted">{{ \Illuminate\Support\Str::limit($record->description,220) }}</p>
@if(isset($record->published))
<small>{{ $record->published?'Publicada':'Borrador' }}{{ $record->active?'':' · inactiva' }}</small>
@endif
@if($catalog==='areas')
<small>Capacidad: {{ $record->capacity }} personas</small>
@endif
@if($catalog==='opportunities')
<small>{{ $record->area->name }} · {{ $record->process->name }}</small>
@if($record->published)
<a class="public-link" href="{{ route('public.apply',$record->slug) }}" target="_blank" rel="noopener">Abrir formulario <i data-lucide="external-link"></i></a><input aria-label="Enlace público" readonly value="{{ route('public.apply',$record->slug) }}" @click="$el.select();navigator.clipboard?.writeText($el.value)"><small>Pulsa el enlace para copiarlo.</small><a href="{{ route('catalog.qr',$record->id) }}">Descargar QR</a>
@endif
@endif
@if($access->can('catalog.write'))
<div class="actions"><a href="{{ route('catalog.edit',[$catalog,$record->id]) }}">{{ $record->published&&isset($record->version)?'Ver versión':'Editar' }}</a>
@if(isset($record->version))
<form method="post" action="{{ route('catalog.duplicate',[$catalog,$record->id]) }}">@csrf<button class="quiet small">Nueva versión</button></form>
@endif
</div>
@endif
</article>
@empty
<p class="empty">No hay registros con estos filtros.</p>
@endforelse
</div>{{ $records->links() }}</x-layout>
