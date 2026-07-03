@php
    $mostGeneratedAt = $documentGeneratedAt
        ?? ($generated_at ?? now()->format('d.m.Y H:i'));
    $mostMarkPath = public_path('most-icon.png');
    $mostMarkDataUri = is_file($mostMarkPath)
        ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($mostMarkPath))
        : null;
@endphp
<table class="most-brand-card">
    <tr>
        <td style="width: 42px;">
            <div class="most-brand-mark">
                @if ($mostMarkDataUri)
                    <img src="{{ $mostMarkDataUri }}" alt="МОСТ">
                @endif
            </div>
        </td>
        <td>
            <div class="most-brand-name">МОСТ</div>
            <div class="most-brand-subtitle">Система управления строительством и отчетностью</div>
        </td>
        <td style="width: 190px; text-align: right;">
            <div class="most-brand-badge">Сделано в МОСТ</div>
            <div class="most-brand-date">Сформировано: {{ $mostGeneratedAt }}</div>
        </td>
    </tr>
</table>
