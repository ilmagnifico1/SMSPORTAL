# Estensione Chrome per autorizzare gli invii SMS

L’estensione genera una coppia di chiavi ECDSA P-256 nel profilo Chrome. La chiave privata è non esportabile e rimane nell’archivio locale dell’estensione; il portale riceve soltanto la chiave pubblica. Ogni invio richiede una conferma in una finestra separata e produce una firma monouso valida per 90 secondi.

## Installazione manuale

1. Apri `chrome://extensions` in Chrome.
2. Attiva **Modalità sviluppatore**.
3. Seleziona **Carica estensione non pacchettizzata** e scegli questa cartella `chrome-extension`.
4. Accedi al portale e prova un invio: il dispositivo verrà registrato come **In attesa**.
5. Un amministratore deve aprire **Impostazioni → Dispositivi** (`index.php?route=devices`) e approvare il dispositivo.
6. Ripeti l’invio e conferma il riepilogo nella finestra dell’estensione.

## Aggiornamento durante lo sviluppo

Dopo aver caricato la versione 1.1.0 almeno una volta, puoi aprire **Impostazioni** nel portale e premere **Aggiorna estensione** per ricaricare con un click i file presenti nella cartella. Se è ancora installata la versione 1.0.0, usa una sola volta il pulsante **Ricarica** in `chrome://extensions` per attivare questa funzione.

Il pulsante ricarica i file dell'estensione installata, ma non può sostituire da solo un pacchetto CRX. Gli aggiornamenti automatici di una versione distribuita richiedono Chrome Web Store oppure un pacchetto firmato sempre con la stessa chiave e una configurazione di distribuzione amministrata.

Il manifest è limitato a `https://smsportal.book-my.eu/`. Se il portale viene pubblicato con un altro host o percorso, modifica sia `host_permissions` sia `content_scripts.matches` nel `manifest.json` e adegua `validatedBaseUrl()` nel `service-worker.js`, poi ricarica l’estensione.

## Limiti importanti

- Chrome su Windows non permette a un’estensione di leggere il MAC address. L’identità usata qui è la chiave crittografica del profilo Chrome.
- Cancellare il profilo/dati dell’estensione crea una nuova identità, che dovrà essere approvata nuovamente.
- Per produzione usa HTTPS, distribuzione amministrata dell’estensione e criteri Chrome Enterprise che ne impediscano la rimozione.
- L’estensione è un secondo controllo; sessione, permessi, CSRF, limiti di traffico e log server restano obbligatori.
