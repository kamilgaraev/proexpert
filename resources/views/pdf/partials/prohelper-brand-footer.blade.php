@php
    $prohelperGeneratedAt = $documentGeneratedAt
        ?? ($generated_at ?? now()->format('d.m.Y H:i'));
@endphp
<div class="prohelper-document-note">
    Документ сформирован в системе ProHelper. Данные актуальны на момент формирования: {{ $prohelperGeneratedAt }}.
</div>
<div class="prohelper-fixed-footer">
    ProHelper • система управления строительством и отчетностью
</div>
