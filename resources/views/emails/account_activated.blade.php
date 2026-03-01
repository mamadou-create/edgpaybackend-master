<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Compte activé</title>
<style>
    body { font-family: 'Arial', sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
    .email-container { max-width: 600px; margin: 30px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    .header { background-color: #0BA5A4; color: #ffffff; text-align: center; padding: 20px; }
    .content { padding: 30px; color: #333333; line-height: 1.6; }
    .details { margin-top: 20px; padding: 15px; background: #f7f7f7; border-radius: 6px; }
    .badge { display: inline-block; background-color: #16a34a; color: #fff; padding: 4px 12px; border-radius: 4px; font-weight: bold; }
    .footer { text-align: center; font-size: 12px; color: #999999; padding: 20px; }
</style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>Votre compte est activé ✓</h1>
    </div>

    <div class="content">
        <p>Bonjour {{ $user->display_name ?? 'utilisateur' }},</p>
        <p>Bonne nouvelle ! Votre compte MDING vient d'être <strong>activé</strong> par un administrateur. Vous pouvez maintenant accéder à toutes les fonctionnalités de l'application.</p>

        <div class="details">
            <p><strong>Nom :</strong> {{ $user->display_name ?? '—' }}</p>
            <p><strong>Téléphone :</strong> {{ $user->phone ?? '—' }}</p>
            @if(!empty($user->email))
                <p><strong>Email :</strong> {{ $user->email }}</p>
            @endif
            <p><strong>Statut :</strong> <span class="badge">Actif</span></p>
        </div>

        <p style="margin-top:20px;">Connectez-vous dès maintenant à l'application MDING avec votre numéro de téléphone.</p>
        <p>Merci pour votre confiance.</p>
    </div>

    <div class="footer">
        &copy; {{ date('Y') }} MDING. Tous droits réservés.
    </div>
</div>
</body>
</html>
