<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{{ trans_message('user_invitations.email.subject') }}</title>
</head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background-color:#f6f6f6;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f6f6f6; padding:30px 0;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;">
                <tr>
                    <td style="background-color:#ff7a00;color:#ffffff;padding:24px 32px;font-size:24px;font-weight:bold;text-align:center;">
                        {{ trans_message('user_invitations.email.title') }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px;color:#333333;font-size:16px;line-height:1.5;">
                        <p style="margin-top:0;">{{ trans_message('user_invitations.email.greeting') }}</p>

                        @if($isTokenInvitation)
                            <p>{{ trans_message('user_invitations.email.body') }}</p>

                            @if($invitation?->organization?->name)
                                <p>
                                    {{ trans_message('user_invitations.email.organization_label') }}
                                    <strong>{{ $invitation->organization->name }}</strong>
                                </p>
                            @endif

                            <p style="text-align:center;margin:28px 0;">
                                <a href="{{ $acceptUrl }}" style="display:inline-block;padding:12px 24px;background-color:#ff7a00;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">{{ trans_message('user_invitations.email.accept_button') }}</a>
                            </p>

                            <p style="font-size:14px;color:#777777;">
                                {{ trans_message('user_invitations.email.expires_at', ['date' => optional($invitation?->expires_at)->format('d.m.Y H:i')]) }}
                            </p>
                        @else
                            <p>{{ trans_message('user_invitations.email.legacy_body') }}</p>

                            <table cellpadding="0" cellspacing="0" style="margin:16px 0 24px 0;">
                                <tr>
                                    <td style="font-weight:bold;padding-right:8px;">{{ trans_message('user_invitations.email.email_label') }}</td>
                                    <td>{{ $email }}</td>
                                </tr>
                                @if($password)
                                    <tr>
                                        <td style="font-weight:bold;padding-right:8px;">{{ trans_message('user_invitations.email.password_label') }}</td>
                                        <td>{{ $password }}</td>
                                    </tr>
                                @endif
                            </table>

                            @php
                                $buttonKey = str_contains($loginUrl, 'disk.yandex')
                                    ? 'user_invitations.email.download_button'
                                    : 'user_invitations.email.login_button';
                            @endphp

                            <p style="text-align:center;">
                                <a href="{{ $loginUrl }}" style="display:inline-block;padding:12px 24px;background-color:#ff7a00;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">{{ trans_message($buttonKey) }}</a>
                            </p>

                            <p style="font-size:14px;color:#777777;">{{ trans_message('user_invitations.email.change_password_hint') }}</p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="background-color:#fafafa;color:#999999;padding:16px 32px;font-size:12px;text-align:center;">
                        {{ trans_message('user_invitations.email.footer', ['year' => date('Y')]) }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
