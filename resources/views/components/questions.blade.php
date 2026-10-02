@props(['questions'])
@foreach($questions as $q)
<div class="field"><label for="answer-{{ $q['id'] }}">{{ $q['label'] }}{{ !empty($q['required'])?' *':'' }}</label>
@if($q['type']==='paragraph')
<textarea id="answer-{{ $q['id'] }}" name="answers[{{ $q['id'] }}]" @required(!empty($q['required']))>{{ old('answers.'.$q['id']) }}</textarea>
@elseif($q['type']==='select')
<select id="answer-{{ $q['id'] }}" name="answers[{{ $q['id'] }}]" @required(!empty($q['required']))><option value="">Selecciona</option>
@foreach($q['options'] as $option)
<option @selected(old('answers.'.$q['id'])===$option)>{{ $option }}</option>
@endforeach
</select>
@elseif($q['type']==='multiselect')
@foreach($q['options'] as $option)
<label class="inline"><input type="checkbox" name="answers[{{ $q['id'] }}][]" value="{{ $option }}" @checked(in_array($option,old('answers.'.$q['id'],[])))>{{ $option }}</label>
@endforeach
@elseif($q['type']==='checkbox')
<label class="inline"><input id="answer-{{ $q['id'] }}" type="checkbox" name="answers[{{ $q['id'] }}]" value="1" @checked(old('answers.'.$q['id'])) @required(!empty($q['required']))>Confirmo</label>
@else
<input id="answer-{{ $q['id'] }}" type="{{ $q['type']==='number'?'number':($q['type']==='date'?'date':'text') }}" name="answers[{{ $q['id'] }}]" value="{{ old('answers.'.$q['id']) }}" @required(!empty($q['required']))>
@endif
</div>
@endforeach
