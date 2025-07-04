<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Приглашение в ProHelper</title>
</head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background-color:#f6f6f6;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f6f6f6; padding:30px 0;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;">
                <tr>
                    <td style="background-color:#ff7a00;color:#ffffff;padding:24px 32px;font-size:24px;font-weight:bold;text-align:center;">
                        Добро пожаловать в ProHelper!
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px;color:#333333;font-size:16px;line-height:1.5;">
                        <p style="margin-top:0;">Вас пригласили присоединиться к системе управления проектами ProHelper.</p>
                        <p>Используйте следующие данные для входа:</p>
                        <table cellpadding="0" cellspacing="0" style="margin:16px 0 24px 0;">
                            <tr>
                                <td style="font-weight:bold;padding-right:8px;">E-mail:</td>
                                <td>{{ $email }}</td>
                            </tr>
                            <tr>
                                <td style="font-weight:bold;padding-right:8px;">Пароль:</td>
                                <td>{{ $password }}</td>
                            </tr>
                        </table>
                        <p style="text-align:center;">
                            <a href="{{ config('app.url') }}" style="display:inline-block;padding:12px 24px;background-color:#ff7a00;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">Войти в систему</a>
                        </p>
                        <p style="font-size:14px;color:#777777;">Рекомендуем изменить пароль после первого входа для безопасности.</p>
                    </td>
                </tr>
                <tr>
                    <td style="background-color:#fafafa;color:#999999;padding:16px 32px;font-size:12px;text-align:center;">
                        © {{ date('Y') }} ProHelper. Все права защищены.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html> 