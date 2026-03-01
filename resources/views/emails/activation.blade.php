<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Activation de votre compte</title>
<style>
    body {
        font-family: 'Arial', sans-serif;
        background-color: #f4f4f4;
        margin: 0;
        padding: 0;
    }
    .email-container {
        max-width: 600px;
        margin: 30px auto;
        background-color: #ffffff;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }
    .header {
        background-color: #0BA5A4;
        color: #ffffff;
        text-align: center;
        padding: 20px;
    }
    .content {
        padding: 30px;
        color: #333333;
        line-height: 1.6;
    }
    .button {
        display: inline-block;
        background-color: #0BA5A4;
        color: #ffffff !important;
        text-decoration: none;
        padding: 12px 25px;
        border-radius: 6px;
        font-weight: bold;
        margin-top: 20px;
    }
    .footer {
        text-align: center;
        font-size: 12px;
        color: #999999;
        padding: 20px;
    }
</style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>Activation de votre compte</h1>

        <h2 style="color:#0BA5A4;">Bienvenue, {{ $user->display_name }} 👋</h2>
    </div>
    <div class="content">
        <p>Merci de vous être inscrit ! Utilisez le code OTP ci-dessous pour activer votre compte.</p>
        @if(isset($otp))
           <p><strong>Votre code OTP :</strong> <h3>{{ $otp }}</h3></p>
        @endif
        <p>Si vous n'avez pas initié cette action, ignorez cet email.</p>
    </div>
    <div class="footer">
        &copy; {{ date('Y') }} Votre Application. Tous droits réservés.
    </div>
</div>
</body>
</html>
