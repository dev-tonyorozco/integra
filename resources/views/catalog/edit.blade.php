<x-layout :title="$title"><x-heading :title="($record?'Editar ':'Agregar ').$title" subtitle="Las versiones publicadas se conservan; duplica para cambiar su contenido."/>
@if($record?->published&&isset($record->version))
<div class="alert">Esta versión es inmutable. Crea una nueva versión desde el catálogo.</div>
@endif
<form method="post" class="card"><fieldset @disabled($record?->published&&isset($record->version))>@csrf<x-field name="name" label="Nombre" :value="$record?->name??''" required/><x-field name="description" label="Descripción / instrucciones" type="textarea" :value="$record?->description??''"/><label class="inline"><input type="checkbox" name="active" value="1" @checked(old('active',$record?->active??true))>Activo</label>
@if(in_array($catalog,['workflows','questionnaires','tests','requirements','opportunities']))
<label class="inline"><input type="checkbox" name="published" value="1" @checked(old('published',$record?->published??false))>Publicar</label>
@endif
@if($catalog==='roles')
<h2>Permisos</h2>
@foreach(\App\Services\Definitions::PERMISSIONS as $permission)
<label class="inline"><input type="checkbox" name="permissions[]" value="{{ $permission }}" @checked(in_array($permission,old('permissions',$record?->permissions??[])))>{{ ['cases.read'=>'Consultar solicitudes','cases.write'=>'Operar solicitudes','personal.read'=>'Ver datos personales y hallazgos','reports.read'=>'Consultar reportes','catalog.read'=>'Consultar catálogos','catalog.write'=>'Configurar catálogos','directory.write'=>'Administrar personas y acceso','audit.read'=>'Consultar auditoría','technical.read'=>'Ver estado técnico','tests.read'=>'Consultar pruebas'][$permission] }}</label>
@endforeach
<label class="inline"><input type="checkbox" name="area_scope" value="1" @checked(old('area_scope',$record?->area_scope??false))>Limitar a las áreas seleccionadas de cada membresía</label>
@endif
@if($catalog==='areas')
<x-field name="capacity" label="Capacidad" type="number" :value="$record?->capacity??10" min="0" required/>
@foreach(['leaders'=>'Líderes (máximo dos)','contacts'=>'Contactos'] as $kind=>$label)
<fieldset><legend>{{ $label }}</legend>
@foreach($choices['members'] as $m)
@php($selected=$record?->contacts()->wherePivot('kind',$kind==='leaders'?'leader':'contact')->pluck('membership_id')->all()??[])
@if($m->active&&$m->user->active||in_array($m->id,$selected))
<label class="inline"><input type="checkbox" name="{{ $kind }}[]" value="{{ $m->id }}" @checked(in_array($m->id,old($kind,$selected)))>{{ $m->user->name }}{{ $m->user->active&&$m->active?'':' · inactivo' }} · {{ $m->login_enabled?'con acceso':'sin acceso' }}</label>
@endif
@endforeach
</fieldset>
@endforeach
@endif
@if(in_array($catalog,['areas','processes']))
<fieldset><legend>Requisitos</legend><p class="muted">Declaraciones del solicitante; la revisión del equipo se registra en su seguimiento.</p>
@foreach($choices['requirements'] as $req)
@php($saved=collect($record?->requirements??[])->keyBy('id'))
<div class="requirement-row"><label class="inline"><input type="checkbox" name="requirement_ids[]" value="{{ $req->id }}" @checked(in_array($req->id,old('requirement_ids',$saved->keys()->all())))>{{ $req->name }} · v{{ $req->version }}</label><label class="inline"><input type="checkbox" name="mandatory_ids[]" value="{{ $req->id }}" @checked(in_array($req->id,old('mandatory_ids',$saved->filter(fn($r)=>$r['mandatory'])->keys()->all())))>Obligatorio</label></div>
@endforeach
</fieldset>
@endif
@if($catalog==='processes')
@foreach(['workflow_id'=>['Flujo publicado','workflows'],'questionnaire_id'=>['Cuestionario publicado','questionnaires']] as $name=>[$label,$key])
<label for="{{ $name }}">{{ $label }}</label><select id="{{ $name }}" name="{{ $name }}" required><option value="">Selecciona</option>
@foreach($choices[$key] as $option)
<option value="{{ $option->id }}" @selected(old($name,$record?->$name)==$option->id)>{{ $option->name }} · v{{ $option->version }}</option>
@endforeach
</select>
@endforeach
<fieldset><legend>Pruebas disponibles</legend>
@foreach($choices['tests'] as $test)
<label class="inline"><input type="checkbox" name="test_ids[]" value="{{ $test->id }}" @checked(in_array($test->id,old('test_ids',$record?->test_ids??[])))>{{ $test->name }} · v{{ $test->version }}</label>
@endforeach
</fieldset>
@endif
@if($catalog==='opportunities')
@foreach(['area_id'=>['Área','areas'],'process_id'=>['Proceso','processes'],'owner_id'=>['Responsable inicial','members']] as $name=>[$label,$key])
<label for="{{ $name }}">{{ $label }}</label><select id="{{ $name }}" name="{{ $name }}" required><option value="">Selecciona</option>
@foreach($choices[$key] as $option)
@if($key!=='members'||$option->login_enabled&&$option->role?->active&&($option->role->code==='ORG_ADMIN'||in_array('cases.write',$option->role->permissions)))
<option value="{{ $option->id }}" @selected(old($name,$record?->$name)==$option->id)>{{ $key==='members'?$option->user->name:$option->name }}</option>
@endif
@endforeach
</select>
@endforeach
<x-field name="closes_at" label="Fecha y hora de cierre (opcional)" type="datetime-local" :value="$record?->closes_at?->format('Y-m-d\TH:i')??''"/>
@endif
@if($catalog==='workflows')
@include('catalog.workflow-editor')
@endif
@if(in_array($catalog,['questionnaires','tests']))
@include('catalog.question-editor')
@endif
<div class="actions"><button>Guardar configuración</button><a href="{{ route('catalog',$catalog) }}">Cancelar</a></div></fieldset></form></x-layout>
