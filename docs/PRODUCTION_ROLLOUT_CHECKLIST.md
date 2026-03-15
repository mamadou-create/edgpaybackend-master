# Production Rollout Checklist

## 1. Infrastructure

- provisionner une URL publique HTTPS stable pour le backend
- pointer `APP_URL` sur l'URL backend finale
- ouvrir l'accès entrant Meta vers `GET/POST /api/v1/webhook/whatsapp`
- prévoir un stockage persistant pour la base et la queue

## 2. Variables d'environnement

- partir de `.env.production.example`
- renseigner les vraies valeurs Meta côté serveur uniquement
- laisser `CHATBOT_ALLOW_OTP_FALLBACK=false`
- laisser `WHATSAPP_VALIDATE_SIGNATURE=true`
- laisser `APP_ENV=production` et `APP_DEBUG=false`

## 3. Déploiement backend

- déployer le code du dépôt backend
- lancer `php artisan migrate --force`
- exécuter `php artisan config:clear`
- exécuter `php artisan config:cache`
- exécuter `php artisan route:cache` si la stratégie projet le permet

## 4. Worker et queue

- utiliser `QUEUE_CONNECTION=database` ou `redis`
- lancer un worker dédié à `whatsapp`
- superviser le worker avec un service système ou un superviseur équivalent
- vérifier régulièrement `php artisan queue:failed`

## 5. Configuration Meta

- configurer le `Callback URL` final avec `/api/v1/webhook/whatsapp`
- configurer le `Verify token` identique à `WHATSAPP_VERIFY_TOKEN`
- vérifier que le numéro de production et les événements `messages` sont bien abonnés
- vérifier que le token Meta utilisé est adapté à un usage durable

## 6. Validation avant ouverture

- exécuter `php artisan test tests\Feature\WhatsApp\WhatsAppFintechTest.php`
- exécuter `powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\check_whatsapp_env.ps1`
- exécuter `powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\test_whatsapp_webhook.ps1 -BaseUrl "https://votre-url-publique"`
- suivre la checklist `docs/WHATSAPP_FINAL_VALIDATION_CHECKLIST.md`

## 7. Points de vigilance

- ne jamais exposer l'OTP dans WhatsApp en production
- ne pas utiliser de tunnel local en production
- surveiller la réception réelle des messages entrants pendant les premiers tests live
- conserver des logs suffisants pour corréler webhook entrant, session, job sortant et réponse Meta