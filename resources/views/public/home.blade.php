<x-layout :title="$org->name" public><section class="public-hero"><span class="eyebrow">{{ $org->name }}</span><h1>Tu propósito encuentra<br>un lugar para servir.</h1><p class="muted">Explora las oportunidades abiertas. Te escucharemos y acompañaremos para encontrar tu lugar.</p></section><div class="catalog-grid">
@forelse($opportunities as $o)
<article class="card"><span class="pill">{{ $o->area->name }}</span><h2 class="spaced">{{ $o->name }}</h2><p>{{ $o->description }}</p><a class="button" href="{{ route('public.apply',$o->slug) }}">Quiero participar <i data-lucide="arrow-right"></i></a></article>
@empty
<section class="card"><p>Por ahora no hay convocatorias abiertas.</p></section>
@endforelse
</div></x-layout>
