@props(['name','label','type'=>'text','value'=>'','required'=>false,'help'=>''])
<div class="field"><label for="{{ $name }}">{{ $label }}
@if($required) <span class="danger">*</span> 
@endif</label>
@if($type==='textarea')
<textarea id="{{ $name }}" name="{{ $name }}" {{ $attributes }} @required($required)>{{ old($name,$value) }}</textarea>
@else
<input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name,$value) }}" {{ $attributes }} @required($required)>
@endif
@if($help)<small>{{ $help }}</small>
@endif
</div>
