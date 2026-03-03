<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paiement rejeté</title>
</head>
<body>
    <p>Bonjour {{ $client->name ?? $client->prenom ?? 'Client' }},</p>

    <p>
        Votre paiement a été rejeté par l'administrateur.
    </p>

    <p>
        <strong>Montant :</strong> {{ $transaction->montant }}<br>
        <strong>Motif :</strong> {{ $transaction->motif_rejet ?? '—' }}
    </p>

    <p>
        Si vous pensez qu'il s'agit d'une erreur, vous pouvez soumettre à nouveau avec une preuve valide.
    </p>

    <p>— MDING</p>
</body>
</html>
