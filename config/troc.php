<?php

return [
    'reference_to_gnf_rate' => (int) env('TROC_REFERENCE_TO_GNF_RATE', 8700),
    'display_currency' => 'GNF',

    // Politique de reprise pour préserver la rentabilite du revendeur.
    'pricing' => [
        // Prix de revente probable apres remise en etat (1.0 = meme prix que la reference).
        'resale_factor' => (float) env('TROC_RESALE_FACTOR', 1.0),

        // Frais operationnels (diagnostic, logistique, SAV, garantie).
        'operational_cost_percent' => (float) env('TROC_OPERATIONAL_COST_PERCENT', 0.05),

        // Marge minimale visee sur la revente.
        'min_margin_percent' => (float) env('TROC_MIN_MARGIN_PERCENT', 0.10),

        // Marge minimale fixe (en devise de reference, avant conversion GNF).
        'min_margin_fixed' => (float) env('TROC_MIN_MARGIN_FIXED', 8),

        // Seuil plancher commercial de reprise (fraction du prix de reference).
        'buyback_floor_percent' => (float) env('TROC_BUYBACK_FLOOR_PERCENT', 0.35),
    ],
];