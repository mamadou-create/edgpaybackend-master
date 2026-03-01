<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Nouveau remboursement soumis</title>
  </head>
  <body style="font-family: Arial, sans-serif; line-height: 1.45;">
    <h2>Nouveau remboursement soumis</h2>

    <p>Un client vient de soumettre un remboursement (en attente de validation).</p>

    <h3>Client</h3>
    <ul>
      <li><strong>ID :</strong> {{ $client->id }}</li>
      <li><strong>Nom :</strong> {{ $client->name ?? '-' }}</li>
      <li><strong>Téléphone :</strong> {{ $client->phone ?? '-' }}</li>
      <li><strong>Email :</strong> {{ $client->email ?? '-' }}</li>
    </ul>

    <h3>Créance</h3>
    <ul>
      <li><strong>ID :</strong> {{ $creance->id }}</li>
      <li><strong>Référence :</strong> {{ $creance->reference ?? '-' }}</li>
      <li><strong>Montant total :</strong> {{ $creance->montant_total ?? '-' }}</li>
    </ul>

    <h3>Remboursement</h3>
    <ul>
      <li><strong>Transaction ID :</strong> {{ $transaction->id }}</li>
      <li><strong>Type :</strong> {{ $transaction->type ?? '-' }}</li>
      <li><strong>Montant :</strong> {{ $transaction->montant ?? '-' }}</li>
      <li><strong>Statut :</strong> {{ $transaction->statut ?? '-' }}</li>
      <li><strong>Soumis le :</strong> {{ $transaction->created_at ?? '-' }}</li>
      <li><strong>Pièce jointe :</strong> {{ !empty($transaction->preuve_fichier) ? 'Oui' : 'Non' }}</li>
    </ul>

    @if (!empty($transaction->notes))
      <h3>Notes</h3>
      <p>{{ $transaction->notes }}</p>
    @endif

    <p style="color:#666; font-size: 12px;">MDING / EDGPAY</p>
  </body>
</html>
