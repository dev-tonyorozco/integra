<x-layout title="Avisos"><x-heading title="Avisos" subtitle="Recordatorios y actividad dirigidos a tu membresía."/><section class="card">
@forelse($records as $n)
<article class="listitem"><strong>{{ $n->message }}</strong><small>{{ $n->created_at->format('d/m/Y H:i') }}</small>
@if($n->application_id&&$access->can('cases.read')&&$access->cases()->where('id',$n->application_id)->exists())
<a href="{{ route('cases.show',$n->application_id) }}">Abrir solicitud</a>
@endif
@if(!$n->read_at)
<form method="post" action="{{ route('notifications.read',$n->id) }}">@csrf<button class="quiet small">Marcar leído</button></form>
@endif
</article>
@empty
<p class="empty">No tienes avisos.</p>
@endforelse
{{ $records->links() }}</section></x-layout>
