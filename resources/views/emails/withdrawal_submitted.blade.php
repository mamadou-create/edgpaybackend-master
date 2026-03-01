<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nouvelle demande de retrait</title>
<style>
    body { font-family: 'Arial', sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
    .email-container { max-width: 600px; margin: 30px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    .header { background-color: #0BA5A4; color: #ffffff; text-align: center; padding: 20px; }
    .content { padding: 30px; color: #333333; line-height: 1.6; }
    .details { margin-top: 20px; padding: 15px; background: #f7f7f7; border-radius: 6px; }
    .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; background: #f59e0b; color: #fff; font-size: 13px; font-weight: bold; }
    .footer { text-align: center; font-size: 12px; color: #999999; padding: 20px; }
</style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>Nouvelle demande de retrait</h1>
    </div>

    <div class="content">
        <p>Bonjour,</p>
        <p>Une nouvelle demande de retrait vient d'être soumise et nécessite votre traitement.</p>

        <div class="details">
            <p><strong>Demandeur :</strong> {{ $requester->display_name ?? $requester->phone ?? $requester->email ?? 'Utilisateur' }}</p>
            <p><strong>Email :</strong> {{ $requester->email ?? '-' }}</p>
            <p><strong>Téléphone :</strong> {{ $requester->phone ?? '-' }}</p>
            <p><strong>Montant :</strong> {{ number_format((int)($withdrawalRequest->amount ?? 0), 0, ',', ' ') }} GNF</p>
            @if(!empty($withdrawalRequest->provider))
                <p><strong>Fournisseur :</strong> {{ $withdrawalRequest->provider }}</p>
            @endif
            @if(!empty($withdrawalRequest->description))
                <p><strong>Description :</strong> {{ $withdrawalRequest->description }}</p>
            @endif
            <p><strong>Statut :</strong> <span class="badge">En attente</span></p>
            <p><strong>Date :</strong> {{ $withdrawalRequest->created_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }}</p>
        </div>

        <p>Merci de traiter cette demande depuis le panneau d'administration.</p>
    </div>

    <div class="footer">
        &copy; {{ date('Y') }} MDING. Tous droits réservés.
    </div>
</div>
</body>
</html>
