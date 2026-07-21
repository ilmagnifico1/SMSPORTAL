# Sicurezza del portale SMS

## Controlli applicativi implementati

- Session cookie `HttpOnly`, `SameSite=Lax`, `Secure` su HTTPS, session ID rigenerato e scadenza inattività/assoluta.
- Header CSP, anti-framing, MIME sniffing, referrer policy, permissions policy e HSTS su HTTPS.
- CSRF sulle operazioni mutative e permessi applicativi con comportamento fail-closed.
- Rate limiting del login per coppia IP/username, messaggio generico e confronto password a tempo uniforme.
- Migrazione automatica degli hash SHA-1 legacy verso `password_hash()` al primo login valido.
- Password nuove di almeno 12 caratteri e username con formato limitato.
- Rivalidazione periodica di utente, azienda, team e permessi; sessione invalidata se uno di questi viene disattivato.
- Endpoint legacy `adduser.php`, `classes/upload.php`, `upload.php` e `getshon.php` rimossi dal pacchetto.
- Upload CSV limitati per dimensione, estensione, MIME e numero massimo di contatti.
- Verifica TLS obbligatoria per i provider; protocolli cURL limitati a HTTP/HTTPS.
- Endpoint provider validati contro URL anomali, loopback, reti riservate e SSRF. HTTP e reti private richiedono opt-in esplicito.
- Credenziali provider non reinviate al browser durante la modifica.
- Blocco degli invii concorrenti della stessa campagna, con recupero dopo un’ora in caso di processo interrotto.
- Proxy header accettati soltanto da proxy configurati; nessuna rete privata è implicitamente attendibile.
- Segreti DB rimossi dai file versionati e caricati da variabili d’ambiente o da `storage/config.local.php`, escluso da Git e bloccato dal server web. `classes/config.local.php` resta supportato solo per compatibilità.
- Invii singoli e campagne protetti da autorizzazione ECDSA P-256 monouso dell’estensione Chrome: firma legata al payload esatto, scadenza di 90 secondi e consumo atomico sul server.
- Registro amministrativo dei dispositivi Chrome con stato in attesa, approvato o revocato; la chiave privata non esportabile rimane nel profilo dell’estensione e il server conserva soltanto la chiave pubblica.

## Estensione Chrome per gli invii

La cartella `chrome-extension/` contiene l’estensione Manifest V3. Le istruzioni di installazione e configurazione host sono in `chrome-extension/README.md`. Dopo il primo tentativo di invio, un amministratore deve approvare il dispositivo dalla route `index.php?route=devices`.

Chrome su Windows non espone il MAC address alle normali estensioni. L’identità del dispositivo è quindi una chiave crittografica generata nel profilo Chrome. Questa soluzione impedisce a una semplice richiesta dalla console o a un bot HTTP di produrre una firma valida, ma non rende affidabile un computer già compromesso o un profilo Chrome controllato dall’attaccante. In produzione sono raccomandati HTTPS e distribuzione forzata tramite Chrome Enterprise.

## Variabili d’ambiente

Impostare sul servizio PHP/Lighttpd, non soltanto nella shell dell’amministratore:

```text
SMS_DB_HOST=localhost
SMS_DB_PORT=3306
SMS_DB_NAME=sms
SMS_DB_USER=sms_app
SMS_DB_PASSWORD=<password lunga e casuale>
SMS_TRUSTED_PROXIES=<IP/CIDR esatti del reverse proxy>
SMS_PUBLIC_HOST=<hostname pubblico del portale>
SMS_PUBLIC_IP=<IP pubblico opzionale>
SMS_PROVIDER_ALLOW_HTTP=false
SMS_PROVIDER_ALLOW_PRIVATE=false
SMS_FIREWALL_BYPASS_CIDRS=
SMS_MAX_CSV_BYTES=5242880
SMS_MAX_CSV_RECIPIENTS=100000
SMS_LOGIN_MAX_ATTEMPTS=5
SMS_LOGIN_WINDOW_SECONDS=900
SMS_LOGIN_LOCK_SECONDS=900
SMS_SESSION_IDLE_SECONDS=1800
SMS_SESSION_ABSOLUTE_SECONDS=28800
SMS_TEST_LOG_RETENTION_DAYS=30
```

Se il servizio non consente di impostare variabili d'ambiente, `storage/config.local.php` può contenere `trusted_proxies` come lista di IP/CIDR. Usare esclusivamente gli indirizzi puntuali dei reverse proxy (preferibilmente `/32` o `/128`), mai un'intera rete privata.

Per un gateway hardware in LAN impostare `SMS_PROVIDER_ALLOW_PRIVATE=true`. Impostare anche `SMS_PROVIDER_ALLOW_HTTP=true` soltanto se l’apparato non supporta HTTPS e se il traffico passa su una VLAN/VPN dedicata.

## Azioni obbligatorie sul server

1. Creare un utente MySQL dedicato `sms_app`, senza privilegi globali o accesso remoto non necessario. L’applicazione attuale esegue migrazioni DDL a runtime e richiede privilegi sulla sola base `sms`; in seguito le migrazioni dovrebbero essere separate per poter rimuovere `CREATE` e `ALTER`.
2. Ruotare immediatamente la vecchia password MySQL, configurare le variabili `SMS_DB_*` oppure `storage/config.local.php`, verificare il login e rimuovere eventuali vecchie configurazioni da `classes/config.local.php`.
3. Spostare `localhost.sql`, log, backup e collegamenti Windows fuori dalla document root. Il dump può contenere dati personali, hash e configurazioni storiche.
4. Lighttpd non legge `.htaccess`: includere `deployment/lighttpd-security.conf.example` nella configurazione globale, adattare il prefisso `/sms`, riavviare Lighttpd e verificare che `app/`, `classes/`, `inc/`, `logs/`, `uploads/`, `storage/`, file `.sql` e dotfile restituiscano `403/404`.
5. Forzare HTTPS sul reverse proxy, usare TLS moderno, non pubblicare direttamente la porta backend e limitare l’accesso amministrativo con firewall/VPN.
6. Limitare MySQL, SMB, SSH/RDP e pannelli di gestione agli IP amministrativi. Il firewall PHP protegge solo le richieste che arrivano a PHP e non sostituisce il firewall di rete.
7. Configurare backup cifrati fuori dal web server, retention dei log, monitoraggio dei fallimenti login/provider e alert sugli eventi critici.
8. Ruotare le credenziali dei provider SMS eventualmente presenti nel dump o nella cronologia Git.

## Verifica dopo il deploy

Eseguire `powershell -ExecutionPolicy Bypass -File scripts/security-check.ps1`, quindi testare da una rete esterna:

- redirect HTTP→HTTPS;
- cookie di sessione con `Secure`, `HttpOnly` e `SameSite`;
- `403/404` per percorsi privati e file sensibili;
- blocco dopo cinque password errate;
- rifiuto di CSV non validi o troppo grandi;
- impossibilità di avviare due volte contemporaneamente la stessa campagna;
- rifiuto di invii privi di `authorization_id`, con autorizzazione scaduta, riutilizzata o riferita a un payload modificato;
- registrazione, approvazione, conferma e revoca di un dispositivo Chrome di prova;
- accesso negato dopo la disattivazione di utente, team o azienda.
