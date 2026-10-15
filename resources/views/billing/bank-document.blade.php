<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #172033; line-height: 1.5; }
        h1 { font-size: 18px; margin: 0 0 18px; }
        h2 { font-size: 12px; margin-top: 22px; }
        table { width: 100%; border-collapse: collapse; margin: 14px 0; }
        td, th { border: 1px solid #bac1cb; padding: 8px; text-align: left; }
        .notice { color: #576171; font-size: 9px; margin-top: 24px; }
    </style>
</head>
<body>
<h1>{{ trans_message('bank_billing.document_'.$document->kind) }} № {{ $invoice->number }}{{ $document->kind === 'correction' ? '-'.$document->id : '' }}</h1>
<p>{{ trans_message('bank_billing.document_date') }}: {{ $document->issued_at->format('d.m.Y') }}</p>
<h2>{{ trans_message('bank_billing.seller') }}</h2>
<p>{{ $snapshot['seller']['name'] }} · {{ trans_message('bank_billing.inn') }} {{ $snapshot['seller']['inn'] }}<br>
{{ $snapshot['seller']['registration_number'] }}<br>{{ $snapshot['seller']['address'] }}</p>
<h2>{{ trans_message('bank_billing.buyer') }}</h2>
<p>{{ $snapshot['buyer']['name'] }} · {{ trans_message('bank_billing.inn') }} {{ $snapshot['buyer']['inn'] }}<br>
{{ $snapshot['buyer']['registration_number'] }}<br>{{ $snapshot['buyer']['address'] }}</p>
<table>
    <thead><tr><th>{{ trans_message('bank_billing.description') }}</th><th>{{ trans_message('bank_billing.amount') }}</th></tr></thead>
    <tbody><tr><td>{{ $snapshot['description'] }}<br>
    @if(!empty($snapshot['period_start_at']) && !empty($snapshot['period_end_at']))
        {{ \Carbon\CarbonImmutable::parse($snapshot['period_start_at'])->setTimezone('Europe/Moscow')->format('d.m.Y H:i') }} —
        {{ \Carbon\CarbonImmutable::parse($snapshot['period_end_at'])->setTimezone('Europe/Moscow')->format('d.m.Y H:i') }}
    @endif
    </td><td>{{ \App\Services\Billing\Bank\BankMoney::decimal((int) $snapshot['amount_minor']) }} RUB<br>{{ trans_message('bank_billing.without_vat') }}</td></tr></tbody>
</table>
@if($document->kind === 'invoice')
<h2>{{ trans_message('bank_billing.bank_details') }}</h2>
<p>{{ $snapshot['bank']['name'] }}<br>{{ trans_message('bank_billing.bik') }}: {{ $snapshot['bank']['bik'] }}<br>
{{ trans_message('bank_billing.account') }}: {{ $snapshot['bank']['account'] }}<br>
{{ trans_message('bank_billing.correspondent_account') }}: {{ $snapshot['bank']['correspondent_account'] }}</p>
<p><strong>{{ trans_message('bank_billing.purpose') }}:</strong> {{ $snapshot['purpose'] }}</p>
<p>{{ trans_message('bank_billing.expires') }}: {{ $invoice->expires_at->format('d.m.Y') }}</p>
<p>{{ trans_message('bank_billing.b2b_only') }}</p>
@else
<p>{{ trans_message($document->kind === 'closing' ? 'bank_billing.performance_confirmed' : 'bank_billing.correction_description') }}</p>
<p>{{ trans_message('bank_billing.signatures') }}</p>
<p>________________________ / ________________________</p>
@endif
<p class="notice">{{ trans_message('bank_billing.document_notice') }}<br>
{{ trans_message('bank_billing.offer_version') }}: {{ $snapshot['legal_version'] }}</p>
</body>
</html>
