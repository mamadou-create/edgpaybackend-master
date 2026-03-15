<?php

return [
    'allow_otp_fallback' => (bool) env('CHATBOT_ALLOW_OTP_FALLBACK', false),

    'intent_keywords' => [
        'check_balance' => ['solde', 'balance', 'combien j ai', 'quel est mon solde'],
        'send_money' => ['envoie', 'envoyer', 'transfert', 'transferer'],
        'transaction_history' => ['historique', 'transactions', 'mes transactions', 'mes operations'],
        'prepaid_bill' => [
            'facture prepayee',
            'facture prepaye',
            'prepaid',
            'achat energie',
            'acheter de l energie',
            'acheter energie',
            'acheter du courant',
            'acheter courant',
            'recharger mon compteur',
            'recharge compteur',
            'crediter mon compteur',
            'payer mon compteur prepaye',
        ],
        'postpaid_bill' => [
            'facture postpayee',
            'facture postpaye',
            'postpaid',
            'postpayment',
            'payer facture edg',
            'regler facture edg',
            'payer ma facture edg',
            'regler ma facture edg',
            'payer ma facture',
            'regler ma facture',
            'facture edg',
            'payer mon compteur postpaye',
        ],
        'deposit' => ['depot', 'deposer', 'recharger', 'recharge'],
        'withdraw' => ['retrait', 'retirer', 'withdraw'],
        'account_info' => ['mon compte', 'information sur mon compte', 'infos compte', 'profil'],
        'support_help' => ['support', 'agent', 'conseiller', 'aide humaine'],
        'security_help' => ['securite', 'otp', 'pin', 'fraude', 'code'],
    ],
];