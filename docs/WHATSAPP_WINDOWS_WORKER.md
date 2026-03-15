# Worker WhatsApp sur Windows

## Vérifier les variables sensibles

Depuis la racine backend, vérifier que les secrets WhatsApp requis sont bien présents sans afficher leurs valeurs :

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\check_whatsapp_env.ps1
```

Variables obligatoires :

- `WHATSAPP_VERIFY_TOKEN`
- `WHATSAPP_ACCESS_TOKEN`
- `WHATSAPP_PHONE_NUMBER_ID`
- `WHATSAPP_APP_SECRET`

Variables recommandées :

- `WHATSAPP_VALIDATE_SIGNATURE=true`
- `WHATSAPP_QUEUE_OUTBOUND=true`
- `WHATSAPP_OUTBOUND_QUEUE=whatsapp`
- `QUEUE_CONNECTION=database` ou `redis`

## Démarrer le worker manuellement

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\start_whatsapp_worker.ps1
```

Exemple avec une seule exécution :

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\start_whatsapp_worker.ps1 -Once -StopWhenEmpty
```

## Enregistrer un démarrage automatique à la connexion

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\register_whatsapp_worker_task.ps1
```

La tâche planifiée créée s'appelle `EdgPayWhatsAppWorker` et relance le worker sur la queue `whatsapp,default` à chaque ouverture de session.

## Vérifications utiles

- s'assurer que la table `jobs` existe
- s'assurer qu'un process PHP worker tourne après la connexion
- exécuter `php artisan test tests\Feature\WhatsApp\WhatsAppFintechTest.php` après toute modification du module