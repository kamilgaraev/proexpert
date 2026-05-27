<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{{ trans_message('support.email.reply_subject', ['subject' => $requestSubject]) }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f6f6f6;font-family:Arial,sans-serif;color:#222222;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f6f6f6;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background-color:#ffffff;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px 28px;background-color:#ff7a00;color:#ffffff;font-size:20px;font-weight:bold;">
                            {{ trans_message('support.email.reply_title') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin-top:0;font-size:16px;">
                                {{ trans_message('support.email.reply_greeting', ['name' => $recipientName]) }}
                            </p>

                            <p style="font-size:16px;">
                                <strong>{{ trans_message('support.email.request_subject_label') }}</strong>
                                {{ $requestSubject }}
                            </p>

                            <div style="margin-top:20px;padding:18px;border-left:4px solid #ff7a00;background-color:#fff8f1;white-space:pre-wrap;line-height:1.5;">{{ $bodyText }}</div>

                            <p style="margin-bottom:0;margin-top:24px;font-size:14px;color:#666666;">
                                {{ trans_message('support.email.reply_signature', ['operator' => $operatorName]) }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
