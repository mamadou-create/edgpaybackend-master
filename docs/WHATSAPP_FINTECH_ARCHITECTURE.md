# Architecture WhatsApp Fintech

## Objectif

Ajouter au backend Laravel EdgPay une couche WhatsApp permettant :

- l'identification d'un utilisateur par numéro WhatsApp
- la création d'un compte client et de son wallet
- la liaison d'un compte existant via OTP
- la consultation de solde
- l'envoi d'argent avec PIN et OTP selon le risque
- la consultation de l'historique
- la gestion d'un contexte conversationnel persistant

## Architecture cible dans ce repo

Utilisateur WhatsApp
→ WhatsApp Business Cloud API
→ `GET/POST /api/v1/webhook/whatsapp`
→ `WhatsAppWebhookController`
→ `WhatsAppCloudApiService`
→ `WhatsAppFintechService`
→ `WhatsAppConversationStateService`
→ `WhatsAppIntentParser`
→ `User` / `Wallet` / `WalletTransaction` / `whatsapp_chat_sessions` / `whatsapp_message_logs`

## Réutilisation des briques existantes

- OTP SMS : `App\Services\NimbaSmsService`
- Transfert wallet : `App\Services\WalletService::transfer(...)`
- Historique conversationnel app : `ChatHistory` reste séparé du canal WhatsApp
- Wallet et transactions : `Wallet`, `WalletTransaction`

## Endpoints ajoutés

### Webhook WhatsApp

- `GET /api/v1/webhook/whatsapp`
  - vérification Meta webhook
- `POST /api/v1/webhook/whatsapp`
  - reçoit soit le payload simplifié du prompt, soit le format Meta Cloud API

### API fintech WhatsApp

- `POST /api/v1/whatsapp/auth/create-user`
- `POST /api/v1/whatsapp/auth/link-account`
- `POST /api/v1/whatsapp/auth/verify-otp`
- `GET /api/v1/whatsapp/wallet/balance`
- `POST /api/v1/whatsapp/wallet/send`
- `GET /api/v1/whatsapp/transactions/history`
- `POST /api/v1/whatsapp/support/create`

## Modèle de données ajouté

### `users`

Colonnes ajoutées :

- `pin_hash`
- `whatsapp_phone`
- `whatsapp_verified_at`
- `phone_verified_at`

### `whatsapp_chat_sessions`

- session conversationnelle par numéro WhatsApp
- état courant
- contexte JSON

### `whatsapp_message_logs`

- journal complet des messages entrants / sortants
- payload fournisseur brut
- intent détecté

## États conversationnels gérés

- `idle`
- `awaiting_menu_choice`
- `awaiting_account_name`
- `awaiting_birth_date`
- `awaiting_pin`
- `awaiting_pin_confirmation`
- `awaiting_link_identifier`
- `awaiting_otp`
- `awaiting_amount`
- `awaiting_receiver`
- `awaiting_transfer_pin`
- `awaiting_transfer_otp`

## Règles sécurité implémentées dans le squelette

- PIN stocké en hash dédié
- OTP de liaison et OTP de transfert via cache + expiration
- seuil OTP configurable pour les transferts
- limite transaction configurable
- journalisation complète des messages WhatsApp
- normalisation stricte des numéros
- validation cryptographique du header `X-Hub-Signature-256` configurable via `WHATSAPP_VALIDATE_SIGNATURE`

## Résilience et exécution asynchrone

- les réponses WhatsApp sortantes passent par `SendWhatsAppTextMessageJob` si `WHATSAPP_QUEUE_OUTBOUND=true`
- queue dédiée configurable via `WHATSAPP_OUTBOUND_QUEUE`
- retries avec backoff progressif en cas d'échec fournisseur
- journalisation explicite des échecs finaux du job

## Limites volontairement laissées comme points d'extension

- templates WhatsApp enrichis
- anti-fraude avancée
- support humain et escalade omnicanale

## Mise en service minimale

1. renseigner `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID` et `WHATSAPP_APP_SECRET`
2. activer `WHATSAPP_VALIDATE_SIGNATURE=true` en environnement Meta réel
3. lancer un worker Laravel sur la queue WhatsApp, par exemple `php artisan queue:work --queue=whatsapp,default`
4. passer la queue sur Redis en production si le volume augmente
