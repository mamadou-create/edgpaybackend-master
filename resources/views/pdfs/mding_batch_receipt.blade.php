<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px; size: A4 portrait; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #111827; }
        .header { border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 14px; }
        .brand { font-weight: 700; letter-spacing: 2px; font-size: 16px; }
        .muted { color: #6B7280; font-size: 11px; }
        .row { margin: 4px 0; }
        .label { color: #374151; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #E5E7EB; padding: 8px; vertical-align: top; }
        th { background: #F3F4F6; text-align: left; font-size: 11px; }
        .right { text-align: right; }
        .total { margin-top: 12px; font-weight: 700; }
        .logo { height: 32px; width: auto; vertical-align: middle; }
        .cachet { position: fixed; right: 18px; bottom: 18px; width: 120px; height: auto; opacity: 0.35; }
    </style>
</head>
<body>

<div class="header">
    <div class="row">
        <span class="brand">MDING</span>
    </div>
    <div class="row muted">REÇU RÉCAPITULATIF — Paiement validé</div>
</div>

<div class="row"><span class="label">Client:</span> {{ $client->display_name ?? ($client->name ?? '-') }} ({{ $client->phone ?? '-' }})</div>
<div class="row"><span class="label">Email:</span> {{ $client->email ?? '-' }}</div>
<div class="row"><span class="label">Batch Key:</span> {{ $batch_key }}</div>
<div class="row"><span class="label">Validé le:</span> {{ $validated_at ?? '-' }}</div>

@php
    $total = 0;
@endphp

<table>
    <thead>
        <tr>
            <th style="width: 18%">Créance</th>
            <th style="width: 20%">Transaction</th>
            <th style="width: 10%">Type</th>
            <th style="width: 12%" class="right">Montant</th>
            <th style="width: 20%">Reçu</th>
            <th style="width: 20%">Date</th>
        </tr>
    </thead>
    <tbody>
        @foreach($transactions as $tx)
            @php $total += (float) ($tx->montant ?? 0); @endphp
            <tr>
                <td>
                    {{ $tx->creance?->reference ?? '-' }}
                    <div class="muted">ID: {{ $tx->creance_id ?? '-' }}</div>
                </td>
                <td>
                    {{ $tx->id ?? '-' }}
                    <div class="muted">Statut: {{ $tx->statut ?? '-' }}</div>
                </td>
                <td>{{ $tx->type ?? '-' }}</td>
                <td class="right">{{ $tx->montant ?? '-' }}</td>
                <td>{{ $tx->receipt_number ?? '-' }}</td>
                <td>{{ optional($tx->valide_at)->toDateTimeString() ?? optional($tx->created_at)->toDateTimeString() ?? '-' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="total right">TOTAL VALIDÉ: {{ $total }}</div>

<div class="row muted" style="margin-top:16px;">MDING / EDGPAY — Document généré automatiquement</div>

</body>
</html>
