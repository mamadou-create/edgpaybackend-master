<?php

// Configurations spécifiques EDGPAY.
return [
	'credit' => [
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
	],
];
