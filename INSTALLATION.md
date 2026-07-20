# Installazione di SMS Portal

## Requisiti

- PHP 8.0 o successivo con PDO MySQL, cURL, OpenSSL, mbstring e fileinfo.
- MariaDB o MySQL con InnoDB e `utf8mb4`.
- Server web HTTPS con URL rewriting abilitato.

## Configurazione rapida

1. Creare un database vuoto e un utente dedicato.
2. Importare `database/schema.sql`.
3. Configurare le variabili `SMS_DB_HOST`, `SMS_DB_PORT`, `SMS_DB_NAME`, `SMS_DB_USER` e `SMS_DB_PASSWORD` nel servizio PHP. In alternativa, copiare `classes/config.local.php.example` in `classes/config.local.php` e inserire credenziali locali; il file risultante è escluso da Git.
4. Rendere scrivibili da PHP soltanto `logs/`, `uploads/` e, se si usa il simulatore, `internalprovider/storage/`.
5. Configurare `SMS_PUBLIC_HOST` e `SMS_TRUSTED_PROXIES` in base al proprio reverse proxy.
6. Pubblicare la radice del progetto tramite HTTPS e seguire `docs/SECURITY.md` prima dell'uso in produzione.

## Provider interno di test

Il simulatore è nella cartella `internalprovider/`. Copiare `internalprovider/config.local.php.example` in `internalprovider/config.local.php`, impostare una chiave API casuale e una password amministrativa con hash PHP, quindi pubblicare soltanto `internalprovider/public/` come document root dell'host di test.

Non versionare mai i due file `config.local.php`, log, messaggi registrati, upload o dump contenenti dati.
