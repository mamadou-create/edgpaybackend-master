# NIMBA ChatGPT Setup

Ce document couvre maintenant les providers OpenAI/ChatGPT et Google Gemini.

## Variables .env

Configurer le backend Laravel avec les variables suivantes:

```env
NIMBA_AI_ENABLED=true
NIMBA_AI_PROVIDER=chatgpt
NIMBA_AI_BASE_URL=https://api.openai.com/v1/chat/completions
NIMBA_AI_API_KEY=YOUR_OPENAI_API_KEY
NIMBA_AI_MODEL=gpt-4.1-mini
NIMBA_AI_TIMEOUT=20
NIMBA_AI_TEMPERATURE=0.3
NIMBA_AI_MAX_TOKENS=500
NIMBA_AI_ENABLE_APP_FALLBACK=true
NIMBA_AI_ENABLE_WHATSAPP_FALLBACK=true
NIMBA_AI_RAG_ENABLED=true
NIMBA_AI_RAG_MAX_SNIPPETS=4
NIMBA_AI_RAG_MIN_SCORE=0.18
NIMBA_AI_WEB_SEARCH_ENABLED=false
NIMBA_AI_WEB_SEARCH_PROVIDER=serper
NIMBA_AI_WEB_SEARCH_BASE_URL=https://google.serper.dev/search
NIMBA_AI_WEB_SEARCH_API_KEY=
NIMBA_AI_WEB_SEARCH_SERPER_API_KEY=
NIMBA_AI_WEB_SEARCH_TAVILY_API_KEY=
NIMBA_AI_WEB_SEARCH_TIMEOUT=12
NIMBA_AI_WEB_SEARCH_MAX_RESULTS=4
NIMBA_AI_WEB_SEARCH_LANGUAGE=fr
NIMBA_AI_WEB_SEARCH_REGION=gn
```

Exemple Gemini:

```env
NIMBA_AI_ENABLED=true
NIMBA_AI_PROVIDER=gemini
NIMBA_AI_BASE_URL=https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent
NIMBA_AI_API_KEY=YOUR_GEMINI_API_KEY
NIMBA_AI_MODEL=gemini-2.0-flash
NIMBA_AI_TIMEOUT=20
NIMBA_AI_TEMPERATURE=0.3
NIMBA_AI_MAX_TOKENS=500
NIMBA_AI_ENABLE_APP_FALLBACK=true
NIMBA_AI_ENABLE_WHATSAPP_FALLBACK=true
NIMBA_AI_RAG_ENABLED=true
NIMBA_AI_RAG_MAX_SNIPPETS=4
NIMBA_AI_RAG_MIN_SCORE=0.18
```

Exemple Claude:

```env
NIMBA_AI_ENABLED=true
NIMBA_AI_PROVIDER=claude
NIMBA_AI_CLAUDE_BASE_URL=https://api.anthropic.com/v1/messages
NIMBA_AI_CLAUDE_API_KEY=YOUR_ANTHROPIC_API_KEY
NIMBA_AI_CLAUDE_MODEL=claude-3-5-sonnet-20241022
NIMBA_AI_CLAUDE_VERSION=2023-06-01
NIMBA_AI_ENABLE_APP_FALLBACK=true
NIMBA_AI_ENABLE_WHATSAPP_FALLBACK=true
```

Pour un mode multi-provider propre, utilisez les variables dédiées par provider:

```env
NIMBA_AI_OPENAI_API_KEY=...
NIMBA_AI_GEMINI_API_KEY=...
NIMBA_AI_CLAUDE_API_KEY=...
```

Après modification:

```powershell
php artisan config:clear
```

## Base de connaissances administrable

Le mode RAG récupère automatiquement:

- les entrées définies dans `config/chatbot.php`
- les surcharges `chatbot_app_knowledge_<key>`
- les articles JSON `chatbot_knowledge_article_<slug>` stockés dans `system_settings`

Exemple d'article JSON administrable:

```json
{
  "title": "Notifications EdgPay",
  "topic": "settings",
  "patterns": ["activer les notifications", "notifications edgpay"],
  "keywords": ["notifications", "alertes", "profil"],
  "content": "Les notifications EdgPay se gèrent depuis les paramètres du compte, section Notifications.",
  "channels": ["app", "whatsapp"],
  "priority": 0.9
}
```

## API admin existante

Les routes système existantes permettent déjà de gérer ces clés:

- `GET /api/v1/system-settings/key/{key}`
- `PUT /api/v1/system-settings/key/{key}`
- `PUT /api/v1/system-settings/bulk-update`

Exemples de clés utiles:

- `nimba_ai_rag_enabled`
- `nimba_ai_rag_max_snippets`
- `nimba_ai_rag_min_score`
- `chatbot_web_search_enabled`
- `chatbot_web_search_provider`

## Recherche web optionnelle

- La recherche web est desactivee par defaut.
- Elle sert uniquement a enrichir les questions generales d actualite ou de contexte externe, par exemple `Quelle est la situation en Iran aujourd hui ?`.
- Les questions produit EdgPay restent traitees en priorite via les connaissances EdgPay et le RAG interne.
- Le back-office peut maintenant activer/desactiver cette recherche et choisir `serper` ou `tavily` via les `system_settings` `chatbot_web_search_enabled` et `chatbot_web_search_provider`.
- Providers supportes actuellement:
  - `serper` via `https://google.serper.dev/search`
  - `tavily` via `https://api.tavily.com/search`
- Quand la recherche web est activee, NIMBA injecte les extraits trouves dans le prompt et renvoie aussi `web_references` dans les metadonnees de reponse pour l app et WhatsApp.
- Pour un usage multi-provider propre sans modifier le code, vous pouvez preconfigurer:
  - `NIMBA_AI_WEB_SEARCH_SERPER_API_KEY`
  - `NIMBA_AI_WEB_SEARCH_TAVILY_API_KEY`
- `chatbot_app_knowledge_transfer_howto`
- `chatbot_knowledge_article_notifications`

## Notes provider

- `chatgpt` / `openai`: NIMBA envoie un payload `chat/completions` classique.
- `gemini` / `google`: NIMBA convertit le transcript en `contents` Gemini et passe la clé API Google dans la query string `?key=...`.
- `claude` / `anthropic`: NIMBA envoie un payload `messages` Anthropic avec `x-api-key` et `anthropic-version`.
- Le catalogue des agents conversationnels NIMBA reste séparé du provider IA sous-jacent. Vous pouvez donc avoir un agent nommé `GPT` ou `Gemini`, puis le router vers le provider réel de votre choix côté backend.

## Agents conversationnels et provider réel

Chaque agent conversationnel peut désormais définir:

- `provider`: `chatgpt`, `gemini`, `claude` ou vide pour suivre le provider global NIMBA
- `model`: modèle imposé pour cet agent, sinon fallback sur le modèle par défaut du provider

Exemple d'agent JSON administrable:

```json
[
  {
    "key": "gpt",
    "label": "GPT Assistant",
    "description": "Agent OpenAI",
    "provider": "chatgpt",
    "model": "gpt-4.1-mini",
    "system_prompt": "Style d agent: structuré, clair, utile."
  },
  {
    "key": "claude",
    "label": "Claude Assistant",
    "description": "Agent Anthropic",
    "provider": "claude",
    "model": "claude-3-5-sonnet-20241022",
    "system_prompt": "Style d agent: nuancé, rigoureux, lisible."
  }
]
```