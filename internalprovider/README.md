# SMS Internal Test Provider

Microservizio isolato che simula un gateway SMS senza inviare messaggi reali. Richiede PHP 8.0+, HTTPS e una API key casuale di almeno 32 caratteri.

Il virtual host deve avere come document root esclusivamente `internalprovider/public`, non la cartella principale del servizio. In questo modo configurazione, sorgenti, test e registri rimangono fuori dal web.

## Configurazione

Impostare nel servizio PHP:

```text
INTERNAL_PROVIDER_API_KEY=<inserire qui un segreto casuale di almeno 32 caratteri>
INTERNAL_PROVIDER_ADMIN_PASSWORD_HASH=<hash password_hash della password amministratore>
INTERNAL_PROVIDER_DEFAULT_SCENARIO=success
INTERNAL_PROVIDER_TIMEOUT_MS=15000
INTERNAL_PROVIDER_LOG_MESSAGE=false
INTERNAL_PROVIDER_MAX_LOG_BYTES=10485760
```

Nel portale SMS, se il NAS non risolve il DNS pubblico, il trasporto del provider interno usa
automaticamente l'IP pubblico `82.77.19.48` mantenendo SNI e verifica TLS. In caso di cambio IP,
impostare `SMS_INTERNAL_PROVIDER_RESOLVE_IP` nel servizio PHP del portale.

In alternativa, per sviluppo, creare `config.local.php`:

```php
<?php
return [
    'api_key' => '', // Inserire un segreto casuale prima dell'avvio.
    'admin_password_hash' => '', // Inserire un hash password_hash, non la password in chiaro.
    'default_scenario' => 'success',
];
```

Il file è escluso da Git. La directory `storage/` deve essere scrivibile da PHP e non deve essere servita dal web server.
È disponibile anche [`config.local.php.example`](config.local.php.example), da copiare e personalizzare senza rinominare o versionare il segreto risultante.

Generazione consigliata della chiave:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Generazione dell'hash per la password della console (scegliere una password lunga e unica):

```powershell
php -r "echo password_hash('SOSTITUIRE-CON-UNA-PASSWORD-LUNGA', PASSWORD_DEFAULT), PHP_EOL;"
```

## Console web

La console protetta e disponibile all'indirizzo `https://provtest.book-my.eu/` (il vecchio percorso `/admin` resta compatibile). Mostra:

- totale, riusciti, falliti e percentuale di successo;
- data UTC, stato, codice HTTP, scenario, destinatario mascherato, mittente e IP sorgente;
- ricerca e filtri per esito e scenario;
- testo del messaggio solo quando `INTERNAL_PROVIDER_LOG_MESSAGE=true`.

La console usa una sessione con cookie `HttpOnly` e `SameSite=Strict`, protezione CSRF e una password separata dalla API key. Se `INTERNAL_PROVIDER_ADMIN_PASSWORD_HASH` non e configurata, risponde in modalita fail-closed e non permette l'accesso.

## Endpoint

- `GET /health`: stato pubblico senza dati sensibili.
- `POST /api/v1/messages`: simula un invio.
- `GET /api/v1/messages?limit=50`: ultimi log mascherati, richiede autenticazione.

Il POST accetta form URL encoded o JSON con `to`, `from`, `text` e `api_key`. È supportato anche `Authorization: Bearer <api-key>`.

## Scenari

Lo scenario si seleziona nell’endpoint, ad esempio `/api/v1/messages?scenario=reject`:

- `success`: HTTP 202 e messaggio accettato;
- `reject`: HTTP 422;
- `provider_error`: HTTP 503;
- `rate_limit`: HTTP 429 con `Retry-After`;
- `timeout`: risposta ritardata e HTTP 504;
- `mixed`: esito deterministico in base a destinatario e testo (70% successo, 10% per ciascun errore).

## Configurazione nel portale SMS

Creare un provider di tipo **Test interno**, solo come Super Admin:

- endpoint: `https://provtest.book-my.eu/api/v1/messages?scenario=success`;
- request type: `POST`;
- API key: lo stesso valore configurato nel servizio;
- mittente: un valore chiaramente fittizio, ad esempio `TEST`.

Il portale separa questi invii dai log e dai movimenti economici reali. Il virtual host e l'endpoint previsti sono configurati per `provtest.book-my.eu`.

## Verifica locale

```powershell
php selftest.php
php -S 127.0.0.1:8091 router.php
```

Il secondo comando è esclusivamente per sviluppo. In produzione applicare `openresty.conf.example` e `lighttpd.conf.example`, forzare HTTPS, limitare l’accesso di rete al portale SMS quando possibile e non pubblicare mai la cartella principale `internalprovider/`.
