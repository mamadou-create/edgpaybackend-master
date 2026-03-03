<?php

// Configurations spécifiques EDGPAY.
return [
	'credit' => [
		// Mode d'envoi des emails liés aux remboursements soumis.
		// - queue: dispatch vers la queue (nécessite un worker)
		// - sync : exécution immédiate (pratique en local)
		'reimbursement_mail_mode' => in_array(
			strtolower((string) env(
				'CREDIT_REIMBURSEMENT_MAIL_MODE',
				((string) env('APP_ENV', 'production')) === 'local' ? 'sync' : 'queue'
			)),
			['queue', 'sync'],
			true
		)
			? strtolower((string) env(
				'CREDIT_REIMBURSEMENT_MAIL_MODE',
				((string) env('APP_ENV', 'production')) === 'local' ? 'sync' : 'queue'
			))
			: (((string) env('APP_ENV', 'production')) === 'local' ? 'sync' : 'queue'),

		// Liste d'emails séparés par des virgules.
		// Exemple: CREDIT_REIMBURSEMENT_NOTIFY_EMAILS="admin@site.com,finance@site.com"
		'reimbursement_notify_emails' => array_values(
			array_filter(
				array_map(
					static fn ($v) => is_string($v) ? trim($v) : '',
					explode(',', (string) env('CREDIT_REIMBURSEMENT_NOTIFY_EMAILS', ''))
				),
				static fn ($v) => $v !== ''
			)
		),

		// Mode d'envoi des emails de reçu envoyés au client quand un paiement est validé.
		// - queue: nécessite un worker (prod)
		// - sync : exécution immédiate (pratique en local)
		'receipt_mail_mode' => in_array(
			strtolower((string) env(
				'CREDIT_RECEIPT_MAIL_MODE',
				((string) env('APP_ENV', 'production')) === 'local' ? 'sync' : 'queue'
			)),
			['queue', 'sync'],
			true
		)
			? strtolower((string) env(
				'CREDIT_RECEIPT_MAIL_MODE',
				((string) env('APP_ENV', 'production')) === 'local' ? 'sync' : 'queue'
			))
			: (((string) env('APP_ENV', 'production')) === 'local' ? 'sync' : 'queue'),

		// Mode d'envoi des emails envoyés au client quand un paiement/soumission est rejeté.
		// - queue: nécessite un worker (prod)
		// - sync : exécution immédiate (pratique en local)
		'rejection_mail_mode' => in_array(
			strtolower((string) env(
				'CREDIT_REJECTION_MAIL_MODE',
				((string) env('APP_ENV', 'production')) === 'local' ? 'sync' : 'queue'
			)),
			['queue', 'sync'],
			true
		)
			? strtolower((string) env(
				'CREDIT_REJECTION_MAIL_MODE',
				((string) env('APP_ENV', 'production')) === 'local' ? 'sync' : 'queue'
			))
			: (((string) env('APP_ENV', 'production')) === 'local' ? 'sync' : 'queue'),
	],
];
