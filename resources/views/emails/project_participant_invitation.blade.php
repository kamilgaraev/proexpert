<x-email-layout :title="trans_message('project_invitations.email.title')">
<p style="margin-top:0;">{{ trans_message('project_invitations.email.greeting') }}</p>

<p>{{ trans_message('project_invitations.email.body', ['project' => $projectName, 'role' => $roleLabel, 'organization' => $organizationName]) }}</p>

@if(filled($customMessage))
    <div style="padding:16px;background-color:#F4F5F6;margin:20px 0;">
        <p style="margin:0 0 8px;font-weight:bold;">{{ trans_message('project_invitations.email.message_label') }}</p>
        <p style="margin:0;">{{ $customMessage }}</p>
    </div>
@endif

<p style="text-align:center;margin:28px 0;">
    <a href="{{ $acceptUrl }}" style="display:inline-block;padding:12px 24px;background-color:#B45309;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">{{ trans_message('project_invitations.email.accept_button') }}</a>
</p>

@if($expiresAt)
    <p style="font-size:14px;color:#58636B;">
        {{ trans_message('project_invitations.email.expires_at', ['date' => $expiresAt->format('d.m.Y H:i')]) }}
    </p>
@endif
</x-email-layout>
