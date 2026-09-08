<x-email-layout :title="trans_message('user_invitations.email.title')">
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
                                <a href="{{ $acceptUrl }}" style="display:inline-block;padding:12px 24px;background-color:#B45309;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">{{ trans_message('user_invitations.email.accept_button') }}</a>
                            </p>

                            <p style="font-size:14px;color:#58636B;">
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
                                <a href="{{ $loginUrl }}" style="display:inline-block;padding:12px 24px;background-color:#B45309;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">{{ trans_message($buttonKey) }}</a>
                            </p>

                            <p style="font-size:14px;color:#58636B;">{{ trans_message('user_invitations.email.change_password_hint') }}</p>
                        @endif
</x-email-layout>
