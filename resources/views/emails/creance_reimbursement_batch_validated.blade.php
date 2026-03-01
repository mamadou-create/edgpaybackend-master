<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Paiement validé (reçu)</title>
  </head>
  <body style="font-family: Arial, sans-serif; line-height: 1.45;">
    <h2>Paiement validé (reçu)</h2>

    <p>Votre paiement a été validé par un administrateur.</p>

    <h3>Client</h3>
    <ul>
      <li><strong>ID :</strong> {{ $client->id }}</li>
      <li><strong>Nom :</strong> {{ $client->display_name ?? ($client->name ?? '-') }}</li>
      <li><strong>Téléphone :</strong> {{ $client->phone ?? '-' }}</li>
      <li><strong>Email :</strong> {{ $client->email ?? '-' }}</li>
    </ul>

    <h3>Validation</h3>
    <ul>
      <li><strong>Batch Key :</strong> {{ $batchKey }}</li>
      <li><strong>Nombre de transactions :</strong> {{ count($transactions) }}</li>
      <li><strong>Montant total validé :</strong> {{ $totalValidated }}</li>
      <li><strong>Validé le :</strong> {{ $validatedAt ?? '-' }}</li>
    </ul>

    <h3>Détails</h3>
    <table cellpadding="6" cellspacing="0" border="1" style="border-collapse: collapse; width: 100%; max-width: 900px;">
      <thead>
        <tr>
          <th align="left">Créance</th>
          <th align="left">Transaction</th>
          <th align="left">Type</th>
          <th align="left">Montant</th>
          <th align="left">Reçu</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($transactions as $t)
          <tr>
            <td>
              {{ $t['creance_reference'] ?? '-' }}<br/>
              <small style="color:#666;">ID: {{ $t['creance_id'] ?? '-' }}</small>
            </td>
            <td>
              {{ $t['transaction_id'] ?? '-' }}<br/>
              <small style="color:#666;">{{ $t['created_at'] ?? '-' }}</small>
            </td>
            <td>{{ $t['type'] ?? '-' }}</td>
            <td>{{ $t['montant'] ?? '-' }}</td>
            <td>{{ $t['receipt_number'] ?? '-' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <p style="color:#666; font-size: 12px;">MDING / EDGPAY</p>
  </body>
</html>
