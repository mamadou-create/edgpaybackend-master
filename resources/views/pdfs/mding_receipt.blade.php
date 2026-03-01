<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        /* ─── A5 page setup ─── */
        @page {
            margin: 0;
            size: A5 portrait;
        }
        body {
            font-family: Helvetica, Arial, sans-serif;
            color: #1E293B;
            font-size: 12px;
            margin: 0;
            padding: 0;
            background: #ffffff;
        }

        /* ─── Header banner ─── */
        .header-banner {
            background: #094487;
            padding: 18px 24px 14px 24px;
            color: #ffffff;
        }
        .header-banner table { width: 100%; }
        .header-banner td { vertical-align: middle; }
        .logo-img { height: 40px; width: auto; }
        .brand-name {
            font-size: 17px;
            font-weight: 700;
            letter-spacing: 3px;
            color: #ffffff;
            margin-left: 10px;
        }
        .receipt-title {
            font-size: 18px;
            font-weight: 700;
            color: #ffffff;
            margin: 0;
            text-align: right;
        }
        .receipt-subtitle {
            font-size: 9px;
            color: #A8C8F0;
            text-align: right;
            margin-top: 3px;
        }

        /* ─── Red accent bar ─── */
        .accent-bar {
            height: 4px;
            background: linear-gradient(90deg, #DC2626 0%, #F8CB3C 100%);
        }
        /* Dompdf fallback (no gradients) */
        .accent-bar-fallback {
            height: 4px;
            background: #DC2626;
        }

        /* ─── Receipt number strip ─── */
        .receipt-strip {
            background: #F8FAFC;
            border-bottom: 1px solid #E2E8F0;
            padding: 10px 24px;
        }
        .receipt-strip table { width: 100%; }
        .receipt-strip td { vertical-align: middle; }
        .receipt-number {
            display: inline-block;
            background: #094487;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            padding: 6px 16px;
            border-radius: 4px;
        }
        .receipt-date {
            font-size: 12px;
            color: #64748B;
            text-align: right;
        }

        /* ─── Main content area ─── */
        .content {
            padding: 18px 24px 14px 24px;
        }

        /* ─── Section heading ─── */
        .section-heading {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #094487;
            border-bottom: 2px solid #094487;
            padding-bottom: 4px;
            margin-bottom: 10px;
            margin-top: 16px;
        }
        .section-heading:first-child { margin-top: 0; }

        /* ─── Info card ─── */
        .info-card {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 8px;
        }
        .info-card table { width: 100%; }
        .info-card td { vertical-align: top; padding: 4px 0; }

        .field-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: #64748B;
            margin-bottom: 3px;
        }
        .field-value {
            font-size: 11px;
            font-weight: 600;
            color: #1E293B;
        }
        .field-value-muted {
            font-size: 12px;
            color: #64748B;
            margin-top: 2px;
        }

        /* ─── Amount highlight ─── */
        .amount-box {
            background: #094487;
            border-radius: 8px;
            padding: 14px 18px;
            text-align: center;
            margin: 14px 0;
        }
        .amount-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #A8C8F0;
        }
        .amount-value {
            font-size: 22px;
            font-weight: 700;
            color: #ffffff;
            margin-top: 4px;
        }
        .amount-currency {
            font-size: 14px;
            font-weight: 600;
            color: #F8CB3C;
            margin-left: 6px;
        }

        /* ─── Divider ─── */
        .divider {
            border: none;
            height: 1px;
            background: #E2E8F0;
            margin: 16px 0;
        }

        /* ─── Transaction details table ─── */
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .details-table td {
            padding: 7px 10px;
            font-size: 12px;
            border-bottom: 1px solid #E2E8F0;
            vertical-align: top;
        }
        .details-table .dt-label {
            width: 40%;
            color: #64748B;
            font-weight: 600;
            font-size: 11px;
        }
        .details-table .dt-value {
            width: 60%;
            color: #1E293B;
            font-weight: 600;
        }
        .details-table tr:last-child td {
            border-bottom: none;
        }

        /* ─── Watermark / stamp ─── */
        .stamp {
            text-align: center;
            margin: 18px 0 8px 0;
        }
        .stamp-badge {
            display: inline-block;
            border: 2px solid #2E7D32;
            border-radius: 6px;
            padding: 6px 18px;
            color: #2E7D32;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
        }

        /* ─── Footer ─── */
        .footer-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #062E5F;
            padding: 10px 24px;
            color: #A8C8F0;
            font-size: 9px;
        }
        .footer-bar table { width: 100%; }
        .footer-bar td { vertical-align: middle; }
        .footer-left { text-align: left; }
        .footer-right { text-align: right; color: #F8CB3C; font-weight: 600; }
    </style>
</head>
<body>

    {{-- ═══════════════ HEADER BANNER ═══════════════ --}}
    <div class="header-banner">
        <table cellspacing="0" cellpadding="0">
            <tr>
                <td style="width: 60%;">
                    @if(!empty($logo_base64))
                        <img src="data:image/png;base64,{{ $logo_base64 }}" class="logo-img" alt="MDING">
                    @endif
                    <span class="brand-name">MDING</span>
                </td>
                <td style="width: 40%;">
                    <div class="receipt-title">REÇU DE PAIEMENT</div>
                    <div class="receipt-subtitle">Document officiel généré automatiquement</div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ═══════════════ RED ACCENT BAR ═══════════════ --}}
    <div class="accent-bar-fallback"></div>

    {{-- ═══════════════ RECEIPT NUMBER STRIP ═══════════════ --}}
    <div class="receipt-strip">
        <table cellspacing="0" cellpadding="0">
            <tr>
                <td>
                    <span class="receipt-number">REÇU N° {{ $tx->receipt_number ?? '—' }}</span>
                </td>
                <td>
                    <div class="receipt-date">
                        Date : <strong>{{ optional($tx->receipt_issued_at ?? $tx->valide_at)->format('d/m/Y') }}</strong>
                        &nbsp;&middot;&nbsp;
                        {{ optional($tx->receipt_issued_at ?? $tx->valide_at)->format('H:i') }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ═══════════════ MAIN CONTENT ═══════════════ --}}
    <div class="content">

        {{-- ─── AMOUNT HIGHLIGHT BOX ─── --}}
        <div class="amount-box">
            <div class="amount-label">Montant validé</div>
            <div class="amount-value">
                {{ number_format((float)($tx->montant ?? 0), 0, '.', ' ') }}
                <span class="amount-currency">GNF</span>
            </div>
        </div>

        {{-- ─── CLIENT INFORMATION ─── --}}
        <div class="section-heading">Informations du client</div>
        <div class="info-card">
            <table cellspacing="0" cellpadding="0">
                <tr>
                    <td style="width: 50%; padding-right: 16px;">
                        <div class="field-label">Nom du client</div>
                        <div class="field-value">{{ $client->display_name ?? '—' }}</div>
                    </td>
                    <td style="width: 50%; padding-left: 16px;">
                        <div class="field-label">E-mail</div>
                        <div class="field-value">{{ $client->email ?? '—' }}</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding-right: 16px; padding-top: 10px;">
                        <div class="field-label">Téléphone</div>
                        <div class="field-value">{{ $client->phone ?? $client->telephone ?? '—' }}</div>
                    </td>
                    <td style="padding-left: 16px; padding-top: 10px;">
                        <div class="field-label">ID Client</div>
                        <div class="field-value-muted">{{ $client->id ?? '—' }}</div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- ─── CRÉANCE INFORMATION ─── --}}
        <div class="section-heading">Détails de la créance</div>
        <div class="info-card">
            <table cellspacing="0" cellpadding="0">
                <tr>
                    <td style="width: 50%; padding-right: 16px;">
                        <div class="field-label">Référence</div>
                        <div class="field-value">{{ $creance->reference ?? '—' }}</div>
                    </td>
                    <td style="width: 50%; padding-left: 16px;">
                        <div class="field-label">Statut</div>
                        <div class="field-value">{{ ucfirst($creance->statut ?? '—') }}</div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- ─── TRANSACTION DETAILS ─── --}}
        <div class="section-heading">Détails de la transaction</div>
        <table class="details-table">
            <tr>
                <td class="dt-label">Transaction ID</td>
                <td class="dt-value">{{ $tx->id }}</td>
            </tr>
            <tr>
                <td class="dt-label">Type de transaction</td>
                <td class="dt-value">{{ ucfirst($tx->type ?? '—') }}</td>
            </tr>
            <tr>
                <td class="dt-label">Montant</td>
                <td class="dt-value">{{ number_format((float)($tx->montant ?? 0), 0, '.', ' ') }} GNF</td>
            </tr>
            <tr>
                <td class="dt-label">Validé par</td>
                <td class="dt-value">{{ $validateur->display_name ?? '—' }}</td>
            </tr>
            <tr>
                <td class="dt-label">Date de validation</td>
                <td class="dt-value">{{ optional($tx->valide_at)->format('d/m/Y à H:i') ?? '—' }}</td>
            </tr>
        </table>

        {{-- ─── VALIDATION STAMP ─── --}}
        <div class="stamp">
            <div class="stamp-badge">✓ &nbsp; PAIEMENT VALIDÉ</div>
        </div>

    </div>

    {{-- ═══════════════ FOOTER ═══════════════ --}}
    <div class="footer-bar">
        <table cellspacing="0" cellpadding="0">
            <tr>
                <td class="footer-left">
                    MDING &mdash; Système de gestion de créances &mdash; Reçu {{ $tx->receipt_number ?? $tx->id }}
                </td>
                <td class="footer-right">
                    www.mding.app
                </td>
            </tr>
        </table>
    </div>

</body>
</html>
