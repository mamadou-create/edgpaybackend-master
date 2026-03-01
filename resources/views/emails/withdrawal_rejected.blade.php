<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demande de retrait rejetée</title>
<style>
    body { font-family: 'Arial', sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
    .email-container { max-width: 600px; margin: 30px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    .header { background-color: #ef4444; color: #ffffff; text-align: center; padding: 20px; }
    .content { padding: 30px; color: #333333; line-height: 1.6; }
    .details { margin-top: 20px; padding: 15px; background: #f7f7f7; border-radius: 6px; }
    .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; background: #ef4444; color: #fff; font-size: 13px; font-weight: bold; }
    .reason-box { margin-top: 15px; padding: 12px 15px; background: #fef2f2; border-left: 4px solid #ef4444; border-radius: 4px; color: #b91c1c; }
    .footer { text-align: center; font-size: 12px; color: #999999; padding: 20px; }
</style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>Demande de retrait rejetée</h1>
    </div>

    <div class="content">
        <p>Bonjour {{ $requester->display_name ?? 'client' }},</p>
        <p>Votre demande de retrait a été <strong>rejetée</strong>.</p>

        <div class="details">
            <p><strong>Montant :</strong> {{ number_format((int)($withdrawalRequest->amount ?? 0), 0, ',', ' ') }} GNF</p>
            @if(!empty($withdrawalRequest->provider))
                <p><strong>Fournisseur :</strong> {{ $withdrawalRequest->provider }}</p>
            @endif
            <p><strong>Statut :</strong> <span class="badge">Rejeté</span></p>
            <p><strong>Date :</strong> {{ $withdrawalRequest->processed_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }}</p>
        </div>

        @if(!empty($reason))
            <div class="reason-box">
                <strong>Motif du rejet :</strong> {{ $reason }}
            </div>
        @endif

        <p style="margin-top: 20px;">Pour toute question, contactez notre support.</p>
    </div>

    <div class="footer">
        &copy; {{ date('Y') }} MDING. Tous droits réservés.
    </div>
</div>
</body>
</html>
