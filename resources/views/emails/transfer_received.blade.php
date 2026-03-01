<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transfert reçu</title>
<style>
    body { font-family: 'Arial', sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
    .email-container { max-width: 600px; margin: 30px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    .header { background-color: #16a34a; color: #ffffff; text-align: center; padding: 20px; }
    .content { padding: 30px; color: #333333; line-height: 1.6; }
    .details { margin-top: 20px; padding: 15px; background: #f7f7f7; border-radius: 6px; }
    .amount { font-size: 28px; font-weight: bold; color: #16a34a; text-align: center; padding: 15px 0; }
    .footer { text-align: center; font-size: 12px; color: #999999; padding: 20px; }
</style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>Transfert reçu ↓</h1>
    </div>

    <div class="content">
        <p>Bonjour {{ $receiver->display_name ?? 'utilisateur' }},</p>
        <p>Vous avez reçu un transfert sur votre compte MDING.</p>

        <div class="amount">
            + {{ number_format($amount, 0, ',', ' ') }} GNF
        </div>

        <div class="details">
            <p><strong>Envoyé par :</strong> {{ $sender->display_name ?? $sender->phone ?? '—' }}</p>
            <p><strong>Destinataire :</strong> {{ $receiver->display_name ?? $receiver->phone ?? '—' }}</p>
            <p><strong>Montant reçu :</strong> {{ number_format($amount, 0, ',', ' ') }} GNF</p>
            <p><strong>Date :</strong> {{ now()->format('d/m/Y à H:i') }}</p>
        </div>

        <p style="margin-top: 20px;">Ce montant est maintenant disponible dans votre portefeuille. Connectez-vous à l'application MDING pour en bénéficier.</p>
    </div>

    <div class="footer">
        &copy; {{ date('Y') }} MDING. Tous droits réservés.
    </div>
</div>
</body>
</html>
