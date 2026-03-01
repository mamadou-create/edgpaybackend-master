<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Échec de paiement</title>
<style>
    body { font-family: 'Arial', sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
    .email-container { max-width: 600px; margin: 30px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    .header { background-color: #ef4444; color: #ffffff; text-align: center; padding: 24px 20px; }
    .header h1 { margin: 0; font-size: 22px; }
    .content { padding: 30px; color: #333333; line-height: 1.6; }
    .details { margin-top: 20px; padding: 15px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; }
    .details p { margin: 6px 0; }
    .amount { font-size: 26px; font-weight: bold; color: #ef4444; text-align: center; padding: 12px 0; }
    .error-box { margin-top: 20px; padding: 12px 15px; background: #fff1f1; border-left: 4px solid #ef4444; border-radius: 4px; font-size: 13px; color: #7f1d1d; word-break: break-word; }
    .action-note { margin-top: 24px; padding: 14px 16px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; font-size: 13px; color: #92400e; }
    .footer { text-align: center; font-size: 12px; color: #999999; padding: 20px; }
</style>
</head>
<body>
<div class="email-container">

    <div class="header">
        <h1>⚠️ Échec de paiement</h1>
    </div>

    <div class="content">
        <p>Bonjour <strong>{{ $userName }}</strong>,</p>
        <p>Votre paiement <strong>{{ $paymentType }}</strong> n'a pas pu être traité.</p>

        <div class="amount">
            {{ number_format($amount, 0, ',', ' ') }} GNF
        </div>

        <div class="details">
            <p><strong>Utilisateur :</strong> {{ $userName }}</p>
            <p><strong>Montant :</strong> {{ number_format($amount, 0, ',', ' ') }} GNF</p>
            <p><strong>N° Compteur / Référence :</strong> {{ $compteur }}</p>
            <p><strong>Type de paiement :</strong> {{ $paymentType }}</p>
            <p><strong>Date :</strong> {{ now()->format('d/m/Y à H:i') }}</p>
        </div>

        <div class="error-box">
            <strong>Raison :</strong> {{ $errorMessage }}
        </div>

        <div class="action-note">
            Si un montant a été débité de votre compte, il vous sera remboursé automatiquement. Pour toute question, contactez votre administrateur.
        </div>
    </div>

    <div class="footer">
        &copy; {{ date('Y') }} MDING. Tous droits réservés.
    </div>

</div>
</body>
</html>
