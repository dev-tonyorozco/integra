<x-layout title="Resumen"><x-heading title="Resumen" subtitle="Una mirada al seguimiento de tu organización."><a href="{{ route('public.home',$access->member->organization->slug) }}" class="secondary" target="_blank" rel="noopener">Ver portal público <i data-lucide="external-link"></i></a></x-heading><div class="stats">
@foreach($metrics as $label=>$value)
<article><small>{{ $label }}</small><strong>{{ $value }}</strong><span class="muted">Solicitudes de tu ámbito</span></article>
@endforeach
</div><div class="columns"><section class="card"><div class="sectionhead"><h2>Actividad reciente</h2>
@if($access->can('cases.read'))
<a href="{{ route('cases') }}">Ver solicitudes</a>
@endif
</div>
@if($metrics)<x-case-table :records="$cases"/>
@else
<p class="muted">Usa el menú para gestionar las herramientas disponibles en tu rol.</p>
@endif
</section><section class="card"><h2>Personas con propósito</h2><p class="muted">Cada solicitud es una oportunidad para escuchar, orientar y acompañar.</p><div class="pillar"><i data-lucide="heart-handshake"></i><div><strong>Orientar</strong><small>Escucha y conoce su perfil.</small></div></div><div class="pillar"><i data-lucide="network"></i><div><strong>Conectar</strong><small>Un área y un equipo para servir.</small></div></div><div class="pillar"><i data-lucide="route"></i><div><strong>Acompañar</strong><small>Acuerdos claros y seguimiento humano.</small></div></div></section></div></x-layout>
