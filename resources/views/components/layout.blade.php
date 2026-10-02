@props(['title'=>'INTEGRA','public'=>false])
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $title }} · INTEGRA</title><link rel="icon" href="/icon.svg">@vite(['resources/css/app.css','resources/js/app.js'])</head><body x-data="shell" :class="{'nav-open':menu}"><a class="skip" href="#main">Saltar al contenido</a>
@if(isset($access) && !$public)
<aside class="sidebar" :class="{'open':menu}" id="sidebar"><x-brand/><nav aria-label="Navegación principal">
@php($nav=[['dashboard','Resumen','layout-dashboard','cases.read'],['cases','Solicitudes','inbox','cases.read'],['reports','Reportes','chart-no-axes-combined','reports.read'],['directory','Personas y acceso','users','directory.write']])
@foreach($nav as [$route,$label,$icon,$permission])
@if($access->can($permission))
<a class="{{ request()->routeIs($route.'*')?'current':'' }}" href="{{ route($route) }}"><i data-lucide="{{ $icon }}"></i>{{ $label }}</a>
@endif
@endforeach
@if($access->can('catalog.read'))
<span class="navgroup">CONFIGURACIÓN</span>
@foreach(\App\Http\Controllers\CatalogController::TYPES as $key=>[$model,$label])
<a class="{{ request()->route('catalog')===$key?'current':'' }}" href="{{ route('catalog',$key) }}"><i data-lucide="{{ ['areas'=>'building-2','workflows'=>'workflow','questionnaires'=>'clipboard-list','tests'=>'list-checks','requirements'=>'badge-check','processes'=>'git-branch','opportunities'=>'megaphone','roles'=>'shield-check'][$key] }}"></i>{{ $label }}</a>
@endforeach
@endif
<span class="navgroup">ADMINISTRACIÓN</span><a href="{{ route('notifications') }}"><i data-lucide="bell"></i>Avisos</a>
@if($access->can('audit.read'))
<a href="{{ route('audit') }}"><i data-lucide="history"></i>Auditoría</a>
@endif
@if($access->can('technical.read'))
<a href="{{ route('technical') }}"><i data-lucide="settings"></i>Estado técnico</a>
@endif
</nav><div class="sidebar-foot"><strong>{{ auth()->user()->name }}</strong><small>{{ $access->member->role->name }}</small><form method="post" action="{{ route('logout') }}">@csrf<button class="quiet full"><i data-lucide="log-out"></i>Cerrar sesión</button></form></div></aside><button class="scrim" x-show="menu" @click="menu=false" aria-label="Cerrar menú" x-cloak></button>
@endif
<div class="workspace {{ !isset($access)||$public?'public-workspace':'' }}"><header class="topbar">
@if(isset($access)&&!$public)
<button type="button" class="quiet mobile-nav" @click="menu=!menu" :aria-expanded="menu" aria-controls="sidebar" aria-label="Abrir menú"><i data-lucide="menu"></i></button><form method="post" action="{{ route('switch') }}" class="org-switch">@csrf<select aria-label="Organización" name="membership_id" @change="$el.form.requestSubmit()">
@foreach($members as $m)
<option value="{{ $m->id }}" @selected($m->id===$access->member->id)>{{ $m->organization->name }}</option>
@endforeach
</select><noscript><button>Cambiar</button></noscript></form><span class="header-role">{{ $access->member->role->name }}</span>
@else
<x-brand/><a href="{{ route('login') }}" class="quiet">Acceso al equipo</a>
@endif
<button class="quiet theme" @click="toggleTheme" aria-label="Cambiar tema"><i data-lucide="sun-moon"></i></button></header><main id="main">
@if(session('status'))
<div class="alert" role="status">{{ session('status') }}</div>
@endif
@if($errors->any())
<div class="alert error" role="alert"><strong>Revisa los datos</strong><ul>
@foreach($errors->all() as $error)
<li>{{ $error }}</li>
@endforeach
</ul></div>
@endif
{{ $slot }}</main><footer>INTEGRA <span>Orientamos, conectamos y acompañamos.</span></footer></div></body></html>
