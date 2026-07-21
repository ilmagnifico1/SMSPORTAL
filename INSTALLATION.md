# Installazione di SMS Portal

## Requisiti

- PHP 8.0 o successivo con PDO MySQL, cURL, OpenSSL, mbstring e fileinfo.
- MariaDB o MySQL con InnoDB e `utf8mb4`.
- Server web HTTPS con URL rewriting abilitato.
- Un database vuoto e un utente SQL dedicato con privilegi limitati a quel database.

## Installazione web

1. Copia il progetto nella document root.
2. Applica i permessi Linux indicati sotto.
3. Crea un database vuoto e il relativo utente SQL.
4. Apri via HTTPS la radice del portale. Se non è configurato, SMS Portal reindirizza automaticamente a `install/`.
5. Inserisci credenziali DB, nome dell'azienda iniziale e credenziali del primo Super Admin.
6. Al termine accedi con il Super Admin creato.

L'installer:

- rifiuta database che contengono già tabelle o viste;
- importa `database/schema.sql` senza dati dimostrativi;
- salva la password del Super Admin soltanto come hash;
- crea `storage/config.local.php` e `storage/install.lock`, entrambi esclusi da Git e bloccati dal server web;
- si disattiva automaticamente dopo il completamento.

Se il portale è dietro un reverse proxy, configura prima `SMS_TRUSTED_PROXIES` nell'ambiente PHP affinché HTTPS venga riconosciuto correttamente.

## Permessi Linux

Gli esempi assumono:

- progetto in `/var/www/smsportal`;
- processo PHP eseguito come `www-data`;
- gruppo del server web `www-data`.

Sostituisci percorso, utente e gruppo se la distribuzione usa valori diversi.

Permessi iniziali del codice:

```bash
sudo chown -R root:www-data /var/www/smsportal
sudo find /var/www/smsportal -type d -exec chmod 0750 {} \;
sudo find /var/www/smsportal -type f -exec chmod 0640 {} \;
```

Cartelle scrivibili necessarie durante l'installazione e l'esecuzione:

```bash
sudo install -d -o www-data -g www-data -m 0750 /var/www/smsportal/storage
sudo install -d -o www-data -g www-data -m 0750 /var/www/smsportal/logs
sudo install -d -o www-data -g www-data -m 0750 /var/www/smsportal/uploads
sudo install -d -o www-data -g www-data -m 0750 /var/www/smsportal/internalprovider/storage
```

Dopo che l'installazione web è terminata, rendi la configurazione non modificabile da PHP:

```bash
sudo chown -R root:www-data /var/www/smsportal/storage
sudo find /var/www/smsportal/storage -type d -exec chmod 0750 {} \;
sudo find /var/www/smsportal/storage -type f -exec chmod 0640 {} \;
```

Mantieni scrivibili da PHP soltanto le directory operative:

```bash
sudo chown -R www-data:www-data /var/www/smsportal/logs /var/www/smsportal/uploads /var/www/smsportal/internalprovider/storage
sudo find /var/www/smsportal/logs /var/www/smsportal/uploads /var/www/smsportal/internalprovider/storage -type d -exec chmod 0750 {} \;
sudo find /var/www/smsportal/logs /var/www/smsportal/uploads /var/www/smsportal/internalprovider/storage -type f -exec chmod 0640 {} \;
```

Non usare `chmod 777`.

## Installazione manuale

Se non vuoi usare il wizard:

1. Importa `database/schema.sql` in un database vuoto.
2. Copia `storage/config.local.php.example` in `storage/config.local.php` e inserisci le credenziali.
3. Crea azienda, team e Super Admin usando una password generata con `password_hash()`.
4. Crea `storage/install.lock` oppure configura le variabili `SMS_DB_*` e il lock nel sistema di distribuzione.

Le variabili d'ambiente `SMS_DB_HOST`, `SMS_DB_PORT`, `SMS_DB_NAME`, `SMS_DB_USER`, `SMS_DB_PASSWORD`, `SMS_TRUSTED_PROXIES` e `SMS_PUBLIC_HOST` hanno precedenza sulla configurazione locale.

## Provider interno di test

Il simulatore è nella cartella `internalprovider/`. Copia `internalprovider/config.local.php.example` in `internalprovider/config.local.php`, imposta una chiave API casuale e una password amministrativa con hash PHP, quindi pubblica soltanto `internalprovider/public/` come document root dell'host di test.

Non versionare mai file `config.local.php`, log, messaggi registrati, upload o dump contenenti dati.
