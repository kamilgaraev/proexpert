@php
    $prohelperGeneratedAt = $documentGeneratedAt
        ?? ($generated_at ?? now()->format('d.m.Y H:i'));
@endphp
<table class="prohelper-brand-card">
    <tr>
        <td style="width: 42px;">
            <div class="prohelper-brand-mark">PH</div>
        </td>
        <td>
            <div class="prohelper-brand-name">ProHelper</div>
            <div class="prohelper-brand-subtitle">Система управления строительством и отчетностью</div>
        </td>
        <td style="width: 190px; text-align: right;">
            <div class="prohelper-brand-badge">Сделано в ProHelper</div>
            <div class="prohelper-brand-date">Сформировано: {{ $prohelperGeneratedAt }}</div>
        </td>
    </tr>
</table>
