<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Retrait approuvé</title>
<style>
    body { font-family: 'Arial', sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
    .email-container { max-width: 600px; margin: 30px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    .header { background-color: #0BA5A4; color: #ffffff; text-align: center; padding: 20px; }
    .content { padding: 30px; color: #333333; line-height: 1.6; }
    .details { margin-top: 20px; padding: 15px; background: #f7f7f7; border-radius: 6px; }
    .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; background: #10b981; color: #fff; font-size: 13px; font-weight: bold; }
    .footer { text-align: center; font-size: 12px; color: #999999; padding: 20px; }
</style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>Votre retrait a été approuvé ✅</h1>
    </div>

    <div class="content">
        <p>Bonjour {{ $requester->display_name ?? 'client' }},</p>
        <p>Bonne nouvelle ! Votre demande de retrait a été <strong>approuvée</strong>.</p>

        <div class="details">
            <p><strong>Montant :</strong> {{ number_format((int)($withdrawalRequest->amount ?? 0), 0, ',', ' ') }} GNF</p>
            @if(!empty($withdrawalRequest->provider))
                <p><strong>Fournisseur :</strong> {{ $withdrawalRequest->provider }}</p>
            @endif
            <p><strong>Statut :</strong> <span class="badge">Approuvé</span></p>
            @if(!empty($approver))
                <p><strong>Traité par :</strong> {{ $approver->display_name ?? $approver->phone ?? $approver->email }}</p>
            @endif
            @if(!empty($withdrawalRequest->processing_notes))
                <p><strong>Note :</strong> {{ $withdrawalRequest->processing_notes }}</p>
            @endif
            <p><strong>Date :</strong> {{ $withdrawalRequest->processed_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }}</p>
        </div>

        <p>Merci d'utiliser MDING.</p>
    </div>

    <div class="footer">
        &copy; {{ date('Y') }} MDING. Tous droits réservés.
    </div>
</div>
</body>
</html>
