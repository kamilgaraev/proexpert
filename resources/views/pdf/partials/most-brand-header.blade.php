@php
    $mostGeneratedAt = $documentGeneratedAt
        ?? ($generated_at ?? now()->format('d.m.Y H:i'));
    $mostMarkPath = public_path('most-icon.svg');
    $mostMarkSvg = is_file($mostMarkPath)
        ? (string) file_get_contents($mostMarkPath)
        : '';
@endphp
<table class="most-brand-card">
    <tr>
        <td style="width: 42px;">
            <div class="most-brand-mark">
                @if ($mostMarkSvg !== '')
                    {!! $mostMarkSvg !!}
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
