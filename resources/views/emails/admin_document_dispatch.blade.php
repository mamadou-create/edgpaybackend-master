<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $documentTitle }} {{ $documentNumber }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    <p>Bonjour{{ !empty($recipientName) ? ' ' . $recipientName : '' }},</p>

    <p>{{ $messageText !== '' ? $messageText : 'Veuillez trouver ci-joint votre document.' }}</p>

    <p>
        <strong>Document :</strong> {{ $documentTitle }}<br>
        <strong>Référence :</strong> {{ $documentNumber }}
    </p>

    <p>Cordialement,<br>{{ $senderName }}</p>
</body>
</html>