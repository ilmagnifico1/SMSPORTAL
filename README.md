# SMS Portal

Per installare una nuova istanza usa lo schema privo di dati in `database/schema.sql` e segui [INSTALLATION.md](INSTALLATION.md).

SMS Portal è un’applicazione web PHP per gestire l’invio di SMS singoli e campagne, organizzata per aziende, team e utenti. Il portale centralizza provider, liste di destinatari, tariffe, crediti, autorizzazioni dei dispositivi e registri operativi.

## Funzionalità principali

- Invio di SMS singoli e campagne verso liste importate da CSV.
- Gestione multi-azienda con team, utenti, ruoli e permessi.
- Configurazione di più provider SMS e assegnazione alle aziende.
- Provider fittizio isolato per test di successo, rifiuto, errore, rate limit e timeout senza costi reali.
- Crediti aziendali e provider, ricariche e storico dei movimenti.
- Costi di acquisto e prezzi di vendita per Paese, prefisso, sotto-prefisso o intervallo telefonico.
- Calcolo dei segmenti SMS, della spesa prevista e del margine.
- Dashboard amministrativa con acquisti, vendite e profitto.
- Log dei messaggi e registro degli eventi di sistema.
- Firewall applicativo, limitazione dei tentativi di accesso e controlli CSRF.
- Interfaccia disponibile in italiano e inglese, con preferenza per utente.
- Conferma crittografica degli invii tramite estensione Chrome o Firefox e dispositivi approvati.

## Requisiti

- PHP 8.0 o successivo.
- MariaDB o MySQL con supporto InnoDB e `utf8mb4`.
- Estensioni PHP: PDO MySQL, cURL, OpenSSL, mbstring e fileinfo.
- Server web con HTTPS; il progetto include esempi per Apache, IIS, Lighttpd e OpenResty.
- Google Chrome o Mozilla Firefox per l’autorizzazione degli invii tramite estensione.

## Configurazione

Le credenziali e le impostazioni sensibili devono essere configurate come variabili d’ambiente del servizio PHP:

```text
SMS_DB_HOST=localhost
SMS_DB_PORT=3306
SMS_DB_NAME=sms
SMS_DB_USER=sms_app
SMS_DB_PASSWORD=<password sicura>
SMS_TRUSTED_PROXIES=<IP/CIDR del reverse proxy>
SMS_PUBLIC_HOST=<hostname pubblico>
SMS_PROVIDER_ALLOW_HTTP=false
SMS_PROVIDER_ALLOW_PRIVATE=false
SMS_TEST_LOG_RETENTION_DAYS=30
```

In sviluppo è supportato anche `classes/config.local.php`, che è escluso da Git. La chiave locale `trusted_proxies` accetta una lista di IP/CIDR esatti e viene usata solo quando `SMS_TRUSTED_PROXIES` non è impostata. Non salvare credenziali, dump SQL, log o chiavi private nella document root o nel repository.

## Avvio

1. Pubblica il progetto nella document root del server web.
2. Crea un database vuoto e un utente dedicato con privilegi limitati al solo database dell’applicazione.
3. Configura le variabili `SMS_DB_*` nel servizio PHP.
4. Verifica che PHP possa scrivere esclusivamente nelle directory operative necessarie.
5. Apri `index.php` tramite HTTPS. Le tabelle applicative vengono inizializzate dal portale.
6. Configura aziende, utenti, provider, crediti e listini dall’area **Impostazioni**.
7. Installa l’estensione Chrome o Firefox e approva il dispositivo prima di effettuare invii reali.

Per un’installazione di produzione, applica prima tutte le indicazioni contenute in [docs/SECURITY.md](docs/SECURITY.md).

## Estensioni browser

La cartella [`chrome-extension/`](chrome-extension/) contiene l’estensione Manifest V3 che firma ogni autorizzazione di invio con una chiave ECDSA P-256 conservata nel profilo Chrome.

Consulta [`chrome-extension/README.md`](chrome-extension/README.md) per installazione, registrazione e approvazione del dispositivo.

La variante Mozilla si trova in [`firefox-extension/`](firefox-extension/) e usa un manifest dedicato con background script compatibile con Firefox. Le istruzioni di prova e distribuzione firmata sono in [`firefox-extension/README.md`](firefox-extension/README.md). Dalla pagina **Impostazioni** è inoltre possibile controllare la versione installata e richiedere con un click il controllo aggiornamenti e la ricarica dell’estensione.

## Struttura del progetto

```text
index.php           Unico ingresso PHP pubblico e front controller
app/                Moduli MVC e pagine interne non accessibili direttamente
  Controllers/      Gestione delle richieste e delle risposte HTTP
  Models/           Oggetti del dominio applicativo
  Services/         Regole e casi d'uso dell'applicazione
  Repositories/     Accesso ai dati e compatibilità con il codice legacy
  Views/            Template PHP privi di query e logica di persistenza
  Pages/            Una cartella per ogni route del portale
classes/            Logica applicativa, accesso dati, billing e sicurezza
inc/                Bootstrap, sessioni, navigazione e internazionalizzazione
templates/          Componenti e viste condivise
css/ e js/          Stili e script dell’interfaccia
chrome-extension/   Estensione Chrome per autorizzare gli invii
firefox-extension/  Estensione Firefox per autorizzare gli invii
internalprovider/   Microservizio provider fittizio; pubblicare solo la sottocartella public/
scripts/            Verifiche automatiche, audit e test diagnostici
logs/               File di fallback e registri locali non pubblici
```

Le pagine interne sono organizzate in `app/Pages/<route>/index.php`. Nella radice non esistono più endpoint PHP separati: tutte le richieste passano da `index.php?route=<route>`.

## Architettura MVC

La conversione all'architettura MVC viene eseguita per moduli, mantenendo gli URL esistenti durante la transizione. Una pagina compatibile nella radice delega la richiesta a un controller; il controller usa un service, il service accede ai dati tramite un repository e passa Model tipizzati alla View.

```text
index.php?route=companies
    → Router
        → CompanyController
        → CompanyService
            → CompanyRepository
                → SmsApp / CreditManager durante la transizione
        → Views/companies/index.php
```

Il modulo **Aziende** è il primo modulo migrato. Il codice nuovo deve rispettare questi confini ed evitare query SQL, regole applicative o gestione delle richieste direttamente nelle View.

## Verifiche

Sono disponibili controlli locali per le aree più sensibili:

```powershell
php scripts/i18n-selftest.php
php scripts/mvc-selftest.php
php scripts/pricing-rules-selftest.php
php scripts/campaign-estimate-selftest.php
php scripts/device-auth-selftest.php
php scripts/internal-provider-selftest.php
powershell -ExecutionPolicy Bypass -File scripts/security-check.ps1
```

Alcuni audit richiedono un ambiente configurato o l’accesso al database. Eseguili con credenziali dedicate e senza stampare segreti nei log.

## Sicurezza

Non esporre direttamente al web `classes/`, `inc/`, `logs/`, `.git`, file di configurazione, dump o documentazione operativa. Usa HTTPS, un reverse proxy configurato correttamente e restrizioni di rete per database e pannelli amministrativi.

Le misure implementate e la checklist di distribuzione sono documentate in [docs/SECURITY.md](docs/SECURITY.md). Le attività pianificate sono raccolte in [TODO.md](TODO.md).
