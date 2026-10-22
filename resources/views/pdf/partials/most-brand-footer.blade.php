@php
    $mostGeneratedAt = $documentGeneratedAt
        ?? ($generated_at ?? now()->format('d.m.Y H:i'));
    $mostLogo = base64_encode(file_get_contents(public_path('most-icon.svg')));
@endphp
<div class="most-document-marker">
    Сформировано: {{ $mostGeneratedAt }}
    <img src="data:image/svg+xml;base64,{{ $mostLogo }}" alt="МОСТ">
</div>
