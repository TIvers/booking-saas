<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Подтверждение электронной почты</title>
</head>
<body>
    <p>Подтвердите адрес электронной почты для аккаунта Booking.</p>

    @if ($channel === 'code')
        <p>Введите этот код в приложении:</p>
        <p style="font-size: 28px; font-weight: bold; letter-spacing: 8px">{{ $code }}</p>
        <p>Код действует 15 минут.</p>
    @else
        <p><a href="{{ $verificationUrl }}">Подтвердить адрес электронной почты</a></p>
        <p>Ссылка действует 15 минут.</p>
    @endif
</body>
</html>
