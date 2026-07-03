@php
    $mostGeneratedAt = $documentGeneratedAt
        ?? ($generated_at ?? now()->format('d.m.Y H:i'));
@endphp
<div class="most-document-note">
    Документ сформирован в системе МОСТ. Данные актуальны на момент формирования: {{ $mostGeneratedAt }}.
</div>
<div class="most-fixed-footer">
    МОСТ • система управления строительством и отчетностью
</div>
