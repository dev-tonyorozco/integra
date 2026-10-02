@props(['case','action'])
<form method="post" action="{{ route('cases.show',$case->id) }}" {{ $attributes }}>@csrf<input type="hidden" name="revision" value="{{ $case->revision }}"><input type="hidden" name="action" value="{{ $action }}">{{ $slot }}</form>
