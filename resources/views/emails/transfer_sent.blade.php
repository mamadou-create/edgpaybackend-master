<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transfert effectué</title>
<style>
    body { font-family: 'Arial', sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
    .email-container { max-width: 600px; margin: 30px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    .header { background-color: #0BA5A4; color: #ffffff; text-align: center; padding: 20px; }
    .content { padding: 30px; color: #333333; line-height: 1.6; }
    .details { margin-top: 20px; padding: 15px; background: #f7f7f7; border-radius: 6px; }
    .amount { font-size: 28px; font-weight: bold; color: #0BA5A4; text-align: center; padding: 15px 0; }
    .footer { text-align: center; font-size: 12px; color: #999999; padding: 20px; }
</style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>Transfert effectué ✓</h1>
    </div>

    <div class="content">
        <p>Bonjour {{ $sender->display_name ?? 'utilisateur' }},</p>
        <p>Votre transfert a été effectué avec succès.</p>

        <div class="amount">
            {{ number_format($amount, 0, ',', ' ') }} GNF
        </div>

        <div class="details">
            <p><strong>Expéditeur :</strong> {{ $sender->display_name ?? $sender->phone ?? '—' }}</p>
            <p><strong>Destinataire :</strong> {{ $receiver->display_name ?? $receiver->phone ?? '—' }}</p>
            <p><strong>Montant envoyé :</strong> {{ number_format($amount, 0, ',', ' ') }} GNF</p>
            <p><strong>Date :</strong> {{ now()->format('d/m/Y à H:i') }}</p>
        </div>

        <p style="margin-top: 20px;">Si vous n'êtes pas à l'origine de cette opération, veuillez contacter immédiatement votre administrateur.</p>
    </div>

    <div class="footer">
        &copy; {{ date('Y') }} MDING. Tous droits réservés.
    </div>
</div>
</body>
</html>
