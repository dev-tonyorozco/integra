<x-layout title="Acceso inactivo" public><section class="card formcard"><h1>Acceso no disponible</h1><p>Tu membresía o rol está inactivo. Contacta al administrador o cambia de organización.</p><form method="post" action="{{ route('switch') }}">@csrf<select name="membership_id">
@foreach($members as $m)
<option value="{{ $m->id }}">{{ $m->organization->name }}</option>
@endforeach
</select><button>Cambiar organización</button></form><form method="post" action="{{ route('logout') }}">@csrf<button class="quiet">Cerrar sesión</button></form></section></x-layout>
