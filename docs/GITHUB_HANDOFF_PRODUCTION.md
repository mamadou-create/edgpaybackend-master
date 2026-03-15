# GitHub Handoff Production

## Objectif

Ce dépôt contient une base de travail exploitable pour reprendre la mise en production du chatbot fintech et du canal WhatsApp NIMBA.

## Ce qui est déjà en place

- API chatbot applicative via `POST /api/v1/chat/message`
- webhook WhatsApp Meta via `GET/POST /api/v1/webhook/whatsapp`
- endpoints publics WhatsApp sous `POST/GET /api/v1/whatsapp/*`
- moteur conversationnel WhatsApp dans `app/Services/WhatsApp/`
- logs et sessions persistants via les tables `whatsapp_chat_sessions` et `whatsapp_message_logs`
- liaison de compte existant par OTP
- envoi sortant WhatsApp via queue avec `SendWhatsAppTextMessageJob`
- validation de signature `X-Hub-Signature-256`
- scripts Windows pour vérifier l'environnement, tester le webhook et lancer le worker

## Fichiers clés à reprendre

- `app/Services/WhatsApp/WhatsAppFintechService.php`
- `app/Services/WhatsApp/WhatsAppCloudApiService.php`
- `app/Http/Controllers/API/WhatsAppWebhookController.php`
- `config/whatsapp.php`
- `routes/api.php`
- `tests/Feature/WhatsApp/WhatsAppFintechTest.php`
- `docs/WHATSAPP_WEBHOOK_SETUP.md`
- `docs/WHATSAPP_WINDOWS_WORKER.md`

## Vérifications déjà validées

- `php artisan test tests\Feature\WhatsApp\WhatsAppFintechTest.php`
- validation locale du webhook signé via `tools/test_whatsapp_webhook.ps1`
- envoi direct Meta validé pendant les tests manuels

## Important avant push

- ne pas versionner `.env`
- ne pas copier de vraies valeurs Meta dans un fichier suivi
- conserver uniquement des placeholders dans `.env.example`

## Ce qu'il reste à faire pour la production

1. remplacer l'URL de tunnel temporaire par un domaine HTTPS stable
2. configurer les vraies variables de production dans l'environnement serveur
3. utiliser un token Meta durable et vérifier la rotation des secrets
4. faire tourner un worker supervisé en permanence pour la queue `whatsapp`
5. désactiver toute aide de développement autour de l'exposition OTP avant ouverture réelle
6. revalider le flux entrant réel WhatsApp depuis Meta sur l'URL publique finale
7. vérifier la stratégie de logs, monitoring et relance en cas d'échec queue/webhook

## Commandes utiles pour reprise

```powershell
php artisan migrate
php artisan test tests\Feature\WhatsApp\WhatsAppFintechTest.php
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\check_whatsapp_env.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\test_whatsapp_webhook.ps1 -BaseUrl "https://votre-url-publique"
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\start_whatsapp_worker.ps1
```

## Risque connu au moment du handoff

Le socle backend fonctionne, mais les tests manuels ont montré un comportement intermittent sur certains messages entrants WhatsApp tapés depuis le téléphone. Ce point doit être revalidé sur l'infrastructure de production ou préproduction, sans tunnel local.