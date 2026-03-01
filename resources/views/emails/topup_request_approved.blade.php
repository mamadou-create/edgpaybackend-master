<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recharge validée</title>
<style>
    body { font-family: 'Arial', sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
    .email-container { max-width: 600px; margin: 30px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    .header { background-color: #0BA5A4; color: #ffffff; text-align: center; padding: 20px; }
    .content { padding: 30px; color: #333333; line-height: 1.6; }
    .details { margin-top: 20px; padding: 15px; background: #f7f7f7; border-radius: 6px; }
    .footer { text-align: center; font-size: 12px; color: #999999; padding: 20px; }
</style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>Votre recharge a été validée</h1>
    </div>

    <div class="content">
        <p>Bonjour {{ $requester->display_name ?? 'client' }},</p>
        <p>Votre demande de recharge a été approuvée.</p>

        <div class="details">
            <p><strong>Montant :</strong> {{ number_format((int)($topupRequest->amount ?? 0), 0, ',', ' ') }} GNF</p>
            <p><strong>Référence :</strong> {{ $topupRequest->idempotency_key ?? $topupRequest->id }}</p>
            <p><strong>Statut :</strong> {{ $topupRequest->status ?? 'APPROVED' }}</p>
            @if(!empty($approver))
                <p><strong>Validé par :</strong> {{ $approver->display_name ?? $approver->phone ?? $approver->email }}</p>
            @endif
        </div>

        <p>Merci d'utiliser MDING.</p>
    </div>

    <div class="footer">
        &copy; {{ date('Y') }} MDING. Tous droits réservés.
    </div>
</div>
</body>
</html>
