<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{{ trans_message('procurement.supplier_requests.email.title') }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f6f6f6;font-family:Arial,Helvetica,sans-serif;color:#222222;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f6f6f6;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background-color:#ffffff;border-radius:8px;overflow:hidden;">
                <tr>
                    <td style="padding:24px 32px;background-color:#ff7a00;color:#ffffff;font-size:22px;font-weight:bold;">
                        {{ trans_message('procurement.supplier_requests.email.title') }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px 32px;font-size:16px;line-height:1.5;">
                        <p style="margin-top:0;">
                            {{ trans_message('procurement.supplier_requests.email.greeting', ['supplier' => $supplierName]) }}
                        </p>

                        <p>
                            {{ trans_message('procurement.supplier_requests.email.body', [
                                'organization' => $organization?->name ?? config('app.name'),
                            ]) }}
                        </p>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:20px 0;background-color:#fff8f1;border-left:4px solid #ff7a00;">
                            <tr>
                                <td style="padding:16px 18px;">
                                    <div style="font-size:14px;color:#666666;">
                                        {{ trans_message('procurement.supplier_requests.email.request_number_label') }}
                                    </div>
                                    <div style="font-size:18px;font-weight:bold;color:#222222;">
                                        {{ $supplierRequest->request_number }}
                                    </div>
                                    @if($purchaseRequest?->request_number)
                                        <div style="margin-top:12px;font-size:14px;color:#666666;">
                                            {{ trans_message('procurement.supplier_requests.email.purchase_request_number_label') }}
                                        </div>
                                        <div style="font-size:16px;color:#222222;">
                                            {{ $purchaseRequest->request_number }}
                                        </div>
                                    @endif
                                    @if($supplierRequest->public_token_expires_at)
                                        <div style="margin-top:12px;font-size:14px;color:#666666;">
                                            {{ trans_message('procurement.supplier_requests.email.expires_at_label') }}
                                        </div>
                                        <div style="font-size:16px;color:#222222;">
                                            {{ $supplierRequest->public_token_expires_at->format('d.m.Y H:i') }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        </table>

                        @if($lines->count() > 0)
                            <p style="margin:24px 0 10px 0;font-weight:bold;">
                                {{ trans_message('procurement.supplier_requests.email.lines_title') }}
                            </p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-bottom:24px;">
                                <thead>
                                    <tr>
                                        <th align="left" style="border:1px solid #dddddd;padding:10px;background-color:#fafafa;font-size:14px;">
                                            {{ trans_message('procurement.supplier_requests.email.item_label') }}
                                        </th>
                                        <th align="right" style="border:1px solid #dddddd;padding:10px;background-color:#fafafa;font-size:14px;width:120px;">
                                            {{ trans_message('procurement.supplier_requests.email.quantity_label') }}
                                        </th>
                                        <th align="left" style="border:1px solid #dddddd;padding:10px;background-color:#fafafa;font-size:14px;width:80px;">
                                            {{ trans_message('procurement.supplier_requests.email.unit_label') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach($lines as $line)
                                    <tr>
                                        <td style="border:1px solid #dddddd;padding:10px;font-size:14px;">
                                            {{ $line->name }}
                                        </td>
                                        <td align="right" style="border:1px solid #dddddd;padding:10px;font-size:14px;">
                                            {{ number_format((float) $line->quantity, 2, ',', ' ') }}
                                        </td>
                                        <td style="border:1px solid #dddddd;padding:10px;font-size:14px;">
                                            {{ $line->unit }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endif

                        <p style="text-align:center;margin:28px 0;">
                            <a href="{{ $publicUrl }}" style="display:inline-block;padding:12px 24px;background-color:#ff7a00;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">
                                {{ trans_message('procurement.supplier_requests.email.button') }}
                            </a>
                        </p>

                        <p style="font-size:14px;color:#666666;">
                            {{ trans_message('procurement.supplier_requests.email.fallback_link') }}<br>
                            <a href="{{ $publicUrl }}" style="color:#d86500;word-break:break-all;">{{ $publicUrl }}</a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="background-color:#fafafa;color:#888888;padding:16px 32px;font-size:12px;text-align:center;">
                        {{ trans_message('procurement.supplier_requests.email.footer') }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
