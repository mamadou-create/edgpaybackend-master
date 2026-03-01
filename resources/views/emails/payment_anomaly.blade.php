<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anomalie Paiement - MDING</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 620px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header { background: #7c3aed; padding: 28px 32px; text-align: center; }
        .header h1 { color: #fff; margin: 0; font-size: 22px; letter-spacing: 1px; }
        .header p { color: #ddd6fe; margin: 6px 0 0; font-size: 13px; }
        .alert-banner { background: #fef3c7; border-left: 4px solid #d97706; padding: 14px 24px; display: flex; align-items: center; gap: 10px; }
        .alert-banner .icon { font-size: 22px; }
        .alert-banner p { margin: 0; color: #92400e; font-weight: 600; font-size: 15px; }
        .body { padding: 28px 32px; }
        .anomaly-title { font-size: 18px; font-weight: bold; color: #1e293b; margin-bottom: 6px; }
        .anomaly-type { display: inline-block; background: #fde8e8; color: #c0392b; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; margin-bottom: 20px; }
        .amount-box { background: #f8f4ff; border: 2px solid #7c3aed; border-radius: 8px; padding: 16px 20px; text-align: center; margin-bottom: 20px; }
        .amount-box .label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 1px; }
        .amount-box .value { font-size: 30px; font-weight: bold; color: #7c3aed; }
        .detail-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; margin-bottom: 20px; }
        .detail-box table { width: 100%; border-collapse: collapse; }
        .detail-box td { padding: 6px 0; font-size: 14px; color: #374151; vertical-align: top; }
        .detail-box td:first-child { font-weight: 600; color: #6b7280; width: 45%; }
        .details-box { background: #fef3f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 14px 18px; margin-bottom: 22px; }
        .details-box p { margin: 0; color: #991b1b; font-size: 13px; line-height: 1.6; }
        .action-box { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; padding: 14px 18px; margin-bottom: 22px; }
        .action-box p { margin: 0; color: #78350f; font-size: 13px; line-height: 1.7; }
        .footer { background: #1e293b; padding: 18px 32px; text-align: center; }
        .footer p { color: #64748b; font-size: 11px; margin: 0; }
        .footer span { color: #94a3b8; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>⚠️ MDING — Anomalie Détectée</h1>
        <p>Alerte automatique du système de surveillance des paiements</p>
    </div>

    <div class="alert-banner">
        <span class="icon">🚨</span>
        <p>Incohérence détectée sur une transaction {{ $paymentType }}</p>
    </div>

    <div class="body">
        <div class="anomaly-title">Anomalie de paiement</div>
        <div class="anomaly-type">{{ $anomalyType }}</div>

        <div class="amount-box">
            <div class="label">Montant concerné</div>
            <div class="value">{{ number_format($amount, 0, ',', ' ') }} GNF</div>
        </div>

        <div class="detail-box">
            <table>
                <tr>
                    <td>Type de transaction</td>
                    <td>{{ $paymentType }}</td>
                </tr>
                @if($affectedUser)
                <tr>
                    <td>Utilisateur</td>
                    <td>{{ $affectedUser->display_name ?? $affectedUser->phone ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td>Téléphone</td>
                    <td>{{ $affectedUser->phone ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td>Email</td>
                    <td>{{ $affectedUser->email ?? 'N/A' }}</td>
                </tr>
                @endif
                @if($transaction)
                <tr>
                    <td>ID Transaction</td>
                    <td>#{{ $transaction->id }}</td>
                </tr>
                <tr>
                    <td>Compteur (RST)</td>
                    <td>{{ $transaction->rst_value ?? $transaction->rst_code ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td>Statut transaction</td>
                    <td>{{ $transaction->api_status ?? 'N/A' }}</td>
                </tr>
                @endif
                <tr>
                    <td>Heure de détection</td>
                    <td>{{ $detectedAt }}</td>
                </tr>
            </table>
        </div>

        <div class="details-box">
            <p><strong>Détails de l'anomalie :</strong><br>{{ $details }}</p>
        </div>

        <div class="action-box">
            <p>
                <strong>Actions recommandées :</strong><br>
                1. Vérifier l'état du wallet de l'utilisateur concerné.<br>
                2. Vérifier le statut de la transaction côté DML.<br>
                3. Si le wallet a été débité sans que le paiement passe → procéder au remboursement manuel.<br>
                4. Si le paiement DML est passé mais le wallet non débité → régulariser le solde.
            </p>
        </div>
    </div>

    <div class="footer">
        <p>Alerte générée automatiquement par <span>MDING Payment System</span></p>
        <p>{{ $detectedAt }}</p>
    </div>
</div>
</body>
</html>
