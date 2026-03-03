<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Soumission rejetée</title>
</head>
<body>
    <p>Bonjour {{ $client->name ?? $client->prenom ?? 'Client' }},</p>

    <p>
        Votre soumission de remboursement a été rejetée.
    </p>

    <p>
        <strong>Nombre de transactions :</strong> {{ is_array($transactions) ? count($transactions) : 0 }}<br>
        <strong>Montant total :</strong> {{ $totalAmount }}<br>
        <strong>Motif :</strong> {{ $motif ?: '—' }}
    </p>

    <p>
        Référence soumission: <strong>{{ $batchKey }}</strong>
    </p>

    <p>— MDING</p>
</body>
</html>
