# WhatsApp Final Validation Checklist

## Préconditions

- l'URL publique finale répond en HTTPS
- `WHATSAPP_VALIDATE_SIGNATURE=true`
- le worker de queue tourne
- le numéro de test ou de production est bien autorisé côté Meta si l'environnement est restreint

## Validation technique

1. vérifier le challenge Meta sur `GET /api/v1/webhook/whatsapp`
2. exécuter `tools/test_whatsapp_webhook.ps1` contre l'URL publique finale
3. vérifier que le POST signé est accepté
4. vérifier qu'aucun job n'échoue dans `queue:failed`

## Validation fonctionnelle minimum

1. envoyer `bonjour` depuis WhatsApp et vérifier le retour menu
2. envoyer `0` et vérifier le retour au menu principal
3. envoyer `2` et vérifier le démarrage de la liaison de compte
4. terminer une liaison OTP sur un compte de test
5. tester une demande de support
6. tester un envoi d'argent avec PIN sur un compte de test
7. tester un envoi d'argent nécessitant OTP si le seuil est atteint

## Contrôles à observer pendant les tests

- une ligne `inbound` est créée dans `whatsapp_message_logs`
- une ligne `outbound` est créée dans `whatsapp_message_logs`
- l'état de `whatsapp_chat_sessions` évolue comme prévu
- aucun message n'est perdu entre webhook entrant et job sortant
- les réponses visibles dans WhatsApp correspondent aux logs backend

## Critères de sortie

- challenge Meta validé
- webhook signé validé
- envoi sortant validé
- menu validé
- liaison OTP validée
- support validé
- transfert validé
- aucun échec bloquant non expliqué