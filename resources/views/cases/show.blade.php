<x-layout :title="$case->folio"><x-heading :title="$access->can('personal.read')?$case->applicant->name:'Solicitud '.$case->folio" :subtitle="$case->folio.' · '.$case->snapshot['area']"><a href="{{ route('cases') }}">Volver</a><a class="secondary" href="{{ route('cases.pdf',$case->id) }}">Ficha PDF <i data-lucide="download"></i></a></x-heading><div class="summary-strip"><div><small>Etapa</small><strong>{{ $case->stateConfig()['label'] }}</strong></div><div><small>Seguimiento</small><strong class="{{ $case->sla()==='Vencida'?'danger':'' }}">{{ $case->sla() }}</strong></div><div><small>Responsable</small><strong>{{ $case->owner->user->name }}</strong></div><div><small>Plazo</small><strong>{{ $case->due_at->format('d/m/Y H:i') }}</strong></div></div>
@if($case->duplicate_of)
<div class="alert">Posible duplicado. Revisa el contexto antes de dar seguimiento.
@if($access->cases()->where('id',$case->duplicate_of)->exists())
<a href="{{ route('cases.show',$case->duplicate_of) }}">Abrir solicitud previa</a>
@endif
</div>
@endif
<div x-data="{tab:'profile'}"><nav class="tabs" aria-label="Secciones de solicitud"><button class="quiet" @click="tab='profile'" :class="{'current':tab==='profile'}">Perfil</button><button class="quiet" @click="tab='history'" :class="{'current':tab==='history'}">Historial</button><button class="quiet" @click="tab='tasks'" :class="{'current':tab==='tasks'}">Tareas y archivos</button><button class="quiet" @click="tab='manage'" :class="{'current':tab==='manage'}">Seguimiento</button></nav>
<section x-show="tab==='profile'" class="card"><h2>Perfil de la persona</h2>
@if($access->can('personal.read'))
<dl><dt>Correo</dt><dd>{{ $case->applicant->email }}</dd><dt>Teléfono</dt><dd>{{ $case->applicant->phone }}</dd><dt>Consentimiento</dt><dd>{{ $case->consent_at->format('d/m/Y H:i') }}</dd></dl>
@else
<p class="muted">Tu rol no permite ver datos personales.</p>
@endif
<h3>Cuestionario · v{{ $case->snapshot['form_version'] }}</h3>
@foreach($case->snapshot['questions'] as $q)
@if($access->can('personal.read'))
<div class="answer"><strong>{{ $q['label'] }}{{ !empty($q['sensitive'])?' · sensible':'' }}</strong><p>{{ is_array($case->answers[$q['id']]??null)?implode(', ',$case->answers[$q['id']]):($case->answers[$q['id']]??'Sin respuesta') }}</p></div>
@endif
@endforeach
<h3>Requisitos declarados</h3>
@foreach($case->snapshot['requirements'] as $req)
<div class="answer">{{ $req['name'] }} · {{ !empty($case->declarations[$req['id']])?'Confirmado por la persona':'Sin confirmar' }}</div>
@endforeach
@if($case->integration_date)
<div class="alert">Integrada el {{ $case->integration_date->format('d/m/Y') }} · {{ $case->integration_function }}</div>
@endif
<small>Proceso: {{ $case->snapshot['process'] }} · flujo v{{ $case->snapshot['workflow_version'] }}. Esta solicitud conserva su configuración original.</small></section>
<section x-show="tab==='history'" class="card" x-cloak><h2>Historial</h2>
@foreach($events as $event)
<article class="timeline"><small>{{ $event->created_at->format('d/m/Y H:i') }} · {{ $event->kind }}{{ $event->private?' · privado':'' }}</small><p>{{ $access->can('personal.read')?$event->body:'Actividad registrada' }}</p>
@if($access->can('personal.read')&&!empty($event->metadata['leader_decision']))
<p><strong>Decisión del líder:</strong> {{ $event->metadata['leader_decision'] }}</p>
@endif
</article>
@endforeach
</section><section x-show="tab==='tasks'" class="card" x-cloak><h2>Tareas y archivos privados</h2>
@if($access->can('personal.read'))
@foreach($case->tasks as $task)
@if(!$task->is_test||$access->can('tests.read'))
<article class="listitem"><strong>{{ $task->name }}</strong><small>{{ $task->completed_at?'Respondida':($task->expires_at->isPast()?'Vencida':'Pendiente') }} · {{ $task->expires_at->format('d/m/Y') }}</small>
@if(!$task->completed_at&&$task->expires_at->isFuture()&&!$case->closed_at)
<input aria-label="Enlace personal de tarea" readonly value="{{ route('public.task',$task->token) }}" @click="$el.select();navigator.clipboard?.writeText($el.value)">
@endif
@if($task->completed_at)
@if($task->is_test)
<p>Puntaje orientativo: {{ $task->score }}. La decisión corresponde al equipo.</p>
@endif
@foreach($task->questions as $q)
<p><strong>{{ $q['label'] }}</strong><br>{{ is_array($task->answers[$q['id']]??null)?implode(', ',$task->answers[$q['id']]):($task->answers[$q['id']]??'Sin respuesta') }}</p>
@endforeach
@endif
</article>
@endif
@endforeach
<h3>Entrevistas</h3>
@foreach($case->interviews as $interview)
<article class="listitem"><strong>{{ $interview->scheduled_at->format('d/m/Y H:i') }}</strong><p>{{ $interview->interviewer }} · {{ $interview->location }} · {{ $interview->status }}</p><p>{{ $interview->result }}</p></article>
@endforeach
<h3>Archivos</h3>
@foreach($case->attachments as $file)
<p><a href="{{ route('files',$file->id) }}">{{ $file->name }}</a><small> {{ number_format($file->size/1024) }} KB</small></p>
@endforeach
@else
<p>Tu rol no permite consultar estos datos.</p>
@endif
</section><section x-show="tab==='manage'" x-cloak>
@if($access->can('cases.write')&&!$case->closed_at&&!$case->archived_at)
<div class="columns"><div><section class="card"><h2>Avanzar en el flujo</h2>
@if(!$case->pause_started)
<x-case-form :case="$case" action="transition"><label for="to">Siguiente etapa</label><select id="to" name="to" required><option value="">Selecciona una salida permitida</option>
@foreach($transitions as $t)
<option value="{{ $t['to'] }}">{{ collect($case->snapshot['workflow']['states'])->firstWhere('id',$t['to'])['label'] }}{{ !empty($t['leader_decision'])?' · decisión del líder':'' }}</option>
@endforeach
</select><x-field name="body" label="Motivo y acuerdos" type="textarea" required/><x-field name="leader_decision" label="Decisión del líder (si la salida lo requiere)" type="textarea"/><div class="row"><x-field name="integration_function" label="Función al integrarse"/><x-field name="integration_date" type="date" label="Fecha de integración"/></div><button>Registrar cambio de etapa</button></x-case-form>
@else
<p>Reanuda el seguimiento antes de avanzar.</p>
@endif
</section><section class="card"><h2>Notas y hallazgos</h2><x-case-form :case="$case" action="note"><x-field name="body" label="Nota" type="textarea" required/><label class="inline"><input type="checkbox" name="private" value="1">Nota privada</label><button>Agregar nota</button></x-case-form>
@if($access->can('personal.read'))
<details><summary>Registrar hallazgo personal</summary><x-case-form :case="$case" action="findings"><x-field name="body" label="Hallazgo privado" type="textarea" required/><button>Guardar hallazgo</button></x-case-form></details><details><summary>Registrar revisión de requisitos</summary><x-case-form :case="$case" action="requirements"><x-field name="body" label="Verificación y observaciones del equipo" type="textarea" required/><button>Registrar revisión</button></x-case-form></details>
@endif
</section>
@if($access->can('personal.read'))
<section class="card"><h2>Entrevista</h2><x-case-form :case="$case" action="interview"><x-field name="scheduled_at" type="datetime-local" label="Fecha y hora" required/><x-field name="interviewer" label="Entrevistador" required/><x-field name="location" label="Lugar o enlace" required/><label>Estado</label><select name="status"><option value="scheduled">Programada</option><option value="completed">Realizada</option><option value="cancelled">Cancelada</option></select><x-field name="result" type="textarea" label="Resultado"/><button>Registrar entrevista</button></x-case-form></section>
@endif
</div><div><section class="card"><h2>Próximo paso</h2><x-case-form :case="$case" action="followup"><label>Responsable</label><select name="owner_id" required>
@foreach($owners as $m)
<option value="{{ $m->id }}" @selected($m->id===$case->owner_id)>{{ $m->user->name }}</option>
@endforeach
</select><x-field name="next_action" label="Próxima acción" :value="$case->next_action" required/><x-field name="due_at" label="Plazo" type="datetime-local" :value="$case->due_at->format('Y-m-d\TH:i')" required/><button>Guardar seguimiento</button></x-case-form></section><section class="card"><h2>Pausa</h2>
@if($case->pause_started)
<p>{{ $case->pause_reason }}</p><x-case-form :case="$case" action="resume"><button>Reanudar seguimiento</button></x-case-form>
@else
<x-case-form :case="$case" action="pause"><x-field name="body" label="Motivo de la pausa" type="textarea" required/><button class="secondary">Pausar seguimiento</button></x-case-form>
@endif
</section>
@if($access->can('personal.read'))
<section class="card"><h2>Enviar tarea</h2><x-case-form :case="$case" action="task"><select name="questionnaire_id" aria-label="Cuestionario o prueba">
@foreach($forms as $form)
<option value="{{ $form->id }}">{{ $form->name }} · v{{ $form->version }}{{ $form->is_test?' · prueba':'' }}</option>
@endforeach
</select><button>Crear enlace personal</button></x-case-form><small>Disponible 10 días y de un solo uso.</small></section><section class="card"><h2>Adjuntar documento</h2><x-case-form :case="$case" action="attachment" enctype="multipart/form-data"><input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required aria-label="Archivo"><small>PDF, JPG o PNG · máximo 5 MB · acceso privado.</small><button>Subir archivo</button></x-case-form></section>
@endif
</div></div>
@elseif($case->closed_at&&!$case->archived_at&&$access->can('cases.write'))
<section class="card"><h2>Solicitud finalizada</h2><p>El seguimiento se conserva en el historial.</p><x-case-form :case="$case" action="archive"><button class="secondary">Archivar solicitud</button></x-case-form></section>
@else
<section class="card"><p>Esta solicitud está archivada o tu rol no permite operarla.</p></section>
@endif
</section></div></x-layout>
