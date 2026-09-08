<x-email-layout :title="trans_message('support.email.title')">
<p style="margin-top:0;font-size:15px;">
                                <strong>{{ trans_message('support.email.sender_label') }}</strong>
                                {{ $senderName }} &lt;{{ $senderEmail }}&gt;
                            </p>

                            @if($userId)
                                <p style="font-size:14px;color:#58636B;">
                                    <strong>{{ trans_message('support.email.user_id_label') }}</strong> {{ $userId }}
                                </p>
                            @endif

                            <p style="font-size:15px;">
                                <strong>{{ trans_message('support.email.request_subject_label') }}</strong>
                                {{ $subjectText }}
                            </p>

                            <div style="margin-top:20px;padding:18px;border-left:4px solid #B45309;background-color:#F4F5F6;white-space:pre-wrap;line-height:1.6;">{{ $messageText }}</div>
</x-email-layout>
