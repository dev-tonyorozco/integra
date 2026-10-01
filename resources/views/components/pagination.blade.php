
@if($paginator->hasPages())
<nav class="pagination" aria-label="Paginación">
@if($paginator->onFirstPage())
<span>Anterior</span>
@else
<a href="{{ $paginator->previousPageUrl() }}">Anterior</a>
@endif
<span>{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
@if($paginator->hasMorePages())
<a href="{{ $paginator->nextPageUrl() }}">Siguiente</a>
@else
<span>Siguiente</span>
@endif
</nav>
@endif
