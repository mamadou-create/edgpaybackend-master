<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nouvelle demande de recharge</title>
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
        <h1>Nouvelle demande de recharge</h1>
    </div>

    <div class="content">
        <p>Bonjour,</p>
        <p>Une nouvelle demande de recharge vient d'être soumise.</p>

        <div class="details">
            <p><strong>Demandeur :</strong> {{ $requester->display_name ?? $requester->phone ?? $requester->email ?? 'Utilisateur' }}</p>
            <p><strong>Montant :</strong> {{ number_format((int)($topupRequest->amount ?? 0), 0, ',', ' ') }} GNF</p>
            <p><strong>Référence :</strong> {{ $topupRequest->idempotency_key ?? $topupRequest->id }}</p>
            <p><strong>Statut :</strong> {{ $topupRequest->status ?? 'PENDING' }}</p>
            @if(!empty($topupRequest->kind))
                <p><strong>Type :</strong> {{ $topupRequest->kind }}</p>
            @endif
            @if(!empty($topupRequest->note))
                <p><strong>Note :</strong> {{ $topupRequest->note }}</p>
            @endif
        </div>

        <p>Merci de traiter cette demande depuis le panneau d'administration.</p>
    </div>

    <div class="footer">
        &copy; {{ date('Y') }} MDING. Tous droits réservés.
    </div>
</div>
</body>
</html>
