<x-layout :title="$o->name" public><x-heading :title="$o->name" :subtitle="$org->name.' · '.$o->area->name"/><section class="card formcard"><p>{{ $o->description }}</p><form method="post">@csrf<x-field name="name" label="Nombre completo" required autocomplete="name"/><x-field name="email" label="Correo" type="email" required autocomplete="email"/><x-field name="phone" label="Teléfono" required autocomplete="tel"/><x-questions :questions="$snapshot['questions']"/>
@if($snapshot['requirements'])
<fieldset><legend>Requisitos del área</legend>
@foreach($snapshot['requirements'] as $req)
<label class="inline"><input type="checkbox" name="requirements[{{ $req['id'] }}]" value="1" @checked(old('requirements.'.$req['id'])) @required($req['mandatory'])>{{ $req['name'] }}{{ $req['mandatory']?' *':'' }}</label><small>{{ $req['description'] }}</small>
@endforeach
</fieldset>
@endif
<div class="privacy"><h3>Uso de tus datos</h3><p>{{ $org->privacy_notice }}</p><label class="inline"><input type="checkbox" name="consent" value="1" required @checked(old('consent'))>Acepto el uso de mis datos para esta solicitud.</label></div><button class="full">Enviar solicitud <i data-lucide="send"></i></button></form></section></x-layout>
