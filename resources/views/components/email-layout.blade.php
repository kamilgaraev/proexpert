@props(['title'])
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $title }} — МОСТ</title>
<style>
@media only screen and (max-width: 600px) {
.email-card { width: 100% !important; }
.email-cell { padding: 20px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background-color:#F4F5F6;color:#252C32;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;-webkit-text-size-adjust:100%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F4F5F6;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" class="email-card" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;">
<tr><td style="padding:0 0 24px;text-align:center;font-size:20px;font-weight:bold;color:#252C32;">МОСТ</td></tr>
<tr><td class="email-cell" style="padding:28px;background-color:#FFFFFF;border:1px solid #DBDFE2;border-radius:8px;overflow-wrap:anywhere;word-break:break-word;">
<h1 style="margin:0 0 24px;color:#252C32;font-size:22px;line-height:1.35;font-weight:bold;">{{ $title }}</h1>
{{ $slot }}
</td></tr>
<tr><td style="padding:24px 12px;text-align:center;font-size:12px;color:#58636B;">© {{ date('Y') }} МОСТ. Все права защищены.</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
