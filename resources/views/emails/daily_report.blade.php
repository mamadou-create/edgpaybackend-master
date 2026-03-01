<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rapport journalier MDING</title>
<style>
    body { font-family: 'Arial', sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
    .email-container { max-width: 650px; margin: 30px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    .header { background-color: #0BA5A4; color: #ffffff; text-align: center; padding: 25px 20px; }
    .header h1 { margin: 0; font-size: 22px; }
    .header p  { margin: 6px 0 0; opacity: .85; font-size: 14px; }
    .content { padding: 30px; color: #333333; line-height: 1.6; }
    .kpi-row { display: flex; gap: 12px; margin-bottom: 24px; }
    .kpi { flex: 1; text-align: center; background: #f0fafa; border: 1px solid #c6eeee; border-radius: 8px; padding: 14px 8px; }
    .kpi .val { font-size: 26px; font-weight: bold; color: #0BA5A4; }
    .kpi .lbl { font-size: 11px; color: #666; margin-top: 4px; }
    .section-title { font-size: 14px; font-weight: bold; color: #0BA5A4; border-bottom: 2px solid #e5f7f7; padding-bottom: 6px; margin: 24px 0 12px; text-transform: uppercase; letter-spacing: .5px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { background: #f0fafa; color: #0BA5A4; text-align: left; padding: 8px 10px; border-bottom: 2px solid #c6eeee; }
    td { padding: 7px 10px; border-bottom: 1px solid #f0f0f0; }
    tr:last-child td { border-bottom: none; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
    .badge-green  { background:#d1fae5; color:#065f46; }
    .badge-orange { background:#ffedd5; color:#92400e; }
    .badge-red    { background:#fee2e2; color:#991b1b; }
    .stat-grid { display: flex; gap: 10px; flex-wrap: wrap; }
    .stat-box { flex: 1; min-width: 120px; background: #f7f7f7; border-radius: 6px; padding: 10px 14px; }
    .stat-box .val { font-weight: bold; font-size: 18px; }
    .stat-box .lbl { font-size: 11px; color: #888; }
    .footer { text-align: center; font-size: 11px; color: #999999; padding: 20px; background: #fafafa; border-top: 1px solid #eee; }
</style>
</head>
<body>
<div class="email-container">

    <div class="header">
        <h1>📊 Rapport journalier MDING</h1>
        <p>Période : {{ $date }}</p>
    </div>

    <div class="content">

        {{-- KPIs principaux --}}
        <div class="kpi-row">
            <div class="kpi">
                <div class="val">{{ number_format($stats['total_transactions'], 0, ',', ' ') }}</div>
                <div class="lbl">Transactions</div>
            </div>
            <div class="kpi">
                <div class="val">{{ number_format($stats['total_volume'], 0, ',', ' ') }}</div>
                <div class="lbl">Volume (GNF)</div>
            </div>
            <div class="kpi">
                <div class="val">{{ $stats['new_users'] }}</div>
                <div class="lbl">Nouveaux comptes</div>
            </div>
        </div>

        {{-- Demandes de recharge --}}
        @if(!empty($stats['topup']))
        <div class="section-title">Demandes de recharge</div>
        <div class="stat-grid">
            <div class="stat-box"><div class="val">{{ $stats['topup']['total'] }}</div><div class="lbl">Total</div></div>
            <div class="stat-box"><div class="val">{{ $stats['topup']['approved'] }}</div><div class="lbl">Approuvées</div></div>
            <div class="stat-box"><div class="val">{{ $stats['topup']['pending'] }}</div><div class="lbl">En attente</div></div>
            <div class="stat-box"><div class="val">{{ $stats['topup']['rejected'] }}</div><div class="lbl">Rejetées</div></div>
            <div class="stat-box"><div class="val">{{ number_format($stats['topup']['volume'], 0, ',', ' ') }}</div><div class="lbl">Volume approuvé (GNF)</div></div>
        </div>
        @endif

        {{-- Demandes de retrait --}}
        @if(!empty($stats['withdrawal']))
        <div class="section-title">Demandes de retrait</div>
        <div class="stat-grid">
            <div class="stat-box"><div class="val">{{ $stats['withdrawal']['total'] }}</div><div class="lbl">Total</div></div>
            <div class="stat-box"><div class="val">{{ $stats['withdrawal']['approved'] }}</div><div class="lbl">Approuvés</div></div>
            <div class="stat-box"><div class="val">{{ $stats['withdrawal']['pending'] }}</div><div class="lbl">En attente</div></div>
            <div class="stat-box"><div class="val">{{ $stats['withdrawal']['rejected'] }}</div><div class="lbl">Rejetés</div></div>
            <div class="stat-box"><div class="val">{{ number_format($stats['withdrawal']['volume'], 0, ',', ' ') }}</div><div class="lbl">Volume approuvé (GNF)</div></div>
        </div>
        @endif

        {{-- Ventilation transactions par type --}}
        @if(!empty($stats['by_type']))
        <div class="section-title">Transactions par type</div>
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Nombre</th>
                    <th>Volume (GNF)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($stats['by_type'] as $type => $data)
                <tr>
                    <td>{{ $type }}</td>
                    <td>{{ $data['count'] }}</td>
                    <td>{{ number_format($data['volume'], 0, ',', ' ') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        {{-- Top 5 transactions --}}
        @if(!empty($stats['top_transactions']))
        <div class="section-title">Top 5 transactions du jour</div>
        <table>
            <thead>
                <tr>
                    <th>Utilisateur</th>
                    <th>Type</th>
                    <th>Montant (GNF)</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                @foreach($stats['top_transactions'] as $tx)
                <tr>
                    <td>{{ $tx['user'] }}</td>
                    <td>{{ $tx['type'] }}</td>
                    <td><strong>{{ number_format($tx['amount'], 0, ',', ' ') }}</strong></td>
                    <td style="font-size:11px;color:#888;">{{ Str::limit($tx['description'] ?? '', 50) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        @if($stats['total_transactions'] === 0)
        <p style="text-align:center;color:#aaa;padding:20px 0;">Aucune transaction enregistrée ce jour.</p>
        @endif

    </div>

    <div class="footer">
        Rapport généré automatiquement le {{ now()->format('d/m/Y à H:i') }}<br>
        &copy; {{ date('Y') }} MDING. Tous droits réservés.
    </div>

</div>
</body>
</html>
