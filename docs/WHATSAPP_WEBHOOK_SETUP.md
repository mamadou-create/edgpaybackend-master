# Configuration webhook WhatsApp Meta

## Endpoint backend EdgPay

Le backend expose déjà :

- `GET /api/v1/webhook/whatsapp`
- `POST /api/v1/webhook/whatsapp`

En local, le point de test peut être :

- `http://127.0.0.1:8000/api/v1/webhook/whatsapp`

Pour Meta, il faut une URL publique HTTPS. `localhost` ne fonctionne pas côté Meta.

## Valeurs à saisir dans Meta

Dans `Meta for Developers` > votre app > `WhatsApp` > `Configuration` ou `API Setup` selon l'interface :

- `Callback URL` : votre URL publique backend suivie de `/api/v1/webhook/whatsapp`
- `Verify token` : la valeur `WHATSAPP_VERIFY_TOKEN` définie dans `.env`

Exemple si votre API publique est `https://api.example.com` :

- `https://api.example.com/api/v1/webhook/whatsapp`

## Vérification locale avant Meta

Depuis le backend :

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Puis dans un autre terminal :

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\test_whatsapp_webhook.ps1
```

Le script :

- vérifie le `GET` Meta challenge
- envoie un `POST` signé avec `X-Hub-Signature-256`
- confirme que la signature et le traitement webhook fonctionnent

## Exposition publique temporaire

Pour tester avec Meta avant déploiement public, utilisez un tunnel HTTPS, par exemple :

- `ngrok http 8000`
- `cloudflared tunnel --url http://127.0.0.1:8000`

Ensuite, utilisez l'URL HTTPS générée comme base du `Callback URL`.

## Points de contrôle

- `WHATSAPP_VALIDATE_SIGNATURE=true`
- `WHATSAPP_APP_SECRET` renseigné
- `WHATSAPP_VERIFY_TOKEN` identique dans Meta et dans `.env`
- backend accessible en HTTPS depuis Internet pour Meta

## Important pour les réponses sortantes en mode test

Si vous utilisez un numéro WhatsApp Cloud API de test Meta, les messages sortants ne partiront que vers des numéros explicitement autorisés par Meta.

Symptôme typique côté backend :

- erreur Meta `(#131030) Recipient phone number not in allowed list`

Où corriger cela :

- `Meta for Developers`
- votre app
- `WhatsApp`
- `API Setup`
- section des numéros de test autorisés / destinataires autorisés

Ajoutez le numéro de téléphone auquel vous voulez répondre, puis validez l'OTP envoyé par WhatsApp si Meta le demande.