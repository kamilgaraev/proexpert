<x-mail::message>
# {{ $greeting ?: trans_message($level === 'error' ? 'mail.error_greeting' : 'mail.greeting', [], 'ru') }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<x-mail::button :url="$actionUrl" :color="in_array($level, ['success', 'error'], true) ? $level : 'primary'">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($salutation))
{{ $salutation }}
@else
{{ trans_message('mail.salutation', [], 'ru') }}<br>
{{ trans_message('mail.team', [], 'ru') }}
@endif

@isset($actionText)
<x-slot:subcopy>
{{ trans_message('mail.fallback', ['action' => $actionText], 'ru') }}
<span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
