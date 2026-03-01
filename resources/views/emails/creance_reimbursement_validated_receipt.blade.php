<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reçu MDING</title>
</head>
<body style="margin:0;padding:0;background:#f6f7fb;">

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7fb;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:640px;max-width:92vw;background:#ffffff;border-radius:14px;overflow:hidden;">
                <tr>
                    <td style="padding:22px 24px;background:#111827;color:#ffffff;font-family:Arial, Helvetica, sans-serif;">
                        <div style="font-size:14px;letter-spacing:2px;opacity:.9;">MDING</div>
                        <div style="font-size:22px;font-weight:700;margin-top:6px;">Votre paiement a été validé</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:22px 24px;font-family:Arial, Helvetica, sans-serif;color:#111827;">
                        <div style="font-size:14px;line-height:20px;">
                            Bonjour {{ $client->display_name ?? 'Client' }},<br>
                            Votre soumission de paiement a été validée. Vous trouverez votre reçu PDF en pièce jointe.
                        </div>

                        <div style="height:14px;"></div>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
                            <tr>
                                <td style="padding:14px 16px;background:#f9fafb;font-size:13px;color:#374151;">Numéro de reçu</td>
                                <td style="padding:14px 16px;background:#ffffff;font-size:13px;color:#111827;font-weight:700;">{{ $transaction->receipt_number ?? '—' }}</td>
                            </tr>
                            <tr>
                                <td style="padding:14px 16px;background:#f9fafb;font-size:13px;color:#374151;">Référence créance</td>
                                <td style="padding:14px 16px;background:#ffffff;font-size:13px;color:#111827;">{{ $creance->reference ?? '—' }}</td>
                            </tr>
                            <tr>
                                <td style="padding:14px 16px;background:#f9fafb;font-size:13px;color:#374151;">Montant validé</td>
                                <td style="padding:14px 16px;background:#ffffff;font-size:13px;color:#111827;">{{ number_format((float)($transaction->montant ?? 0), 0, '.', ' ') }} GNF</td>
                            </tr>
                            <tr>
                                <td style="padding:14px 16px;background:#f9fafb;font-size:13px;color:#374151;">Date</td>
                                <td style="padding:14px 16px;background:#ffffff;font-size:13px;color:#111827;">{{ optional($transaction->receipt_issued_at ?? $transaction->valide_at)->format('d/m/Y H:i') }}</td>
                            </tr>
                        </table>

                        <div style="height:16px;"></div>

                        <div style="font-size:12px;line-height:18px;color:#6b7280;">
                            Si vous n’êtes pas à l’origine de cette soumission, contactez le support.
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:14px 24px;background:#f9fafb;font-family:Arial, Helvetica, sans-serif;color:#6b7280;font-size:12px;">
                        © {{ date('Y') }} MDING. Tous droits réservés.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>
