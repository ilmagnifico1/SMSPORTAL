# TODO

## Gestione crediti e prezzi SMS

1. [x] Implementare la gestione del credito disponibile per ogni azienda.
2. [x] Implementare la gestione delle ricariche e dello storico dei movimenti.
3. [x] Definire i permessi per Super Admin e Admin aziendale relativi a crediti e ricariche.
4. [x] Implementare un listino prezzi per prefisso telefonico.
5. [x] Calcolare il costo del singolo messaggio in base al prefisso del destinatario.
6. [x] Scalare automaticamente il costo dal credito dell'azienda al momento dell'invio.
7. [x] Bloccare l'invio quando il credito disponibile non è sufficiente.
8. [x] Registrare nei log credito iniziale, costo del messaggio, credito residuo e tariffa applicata.
9. [x] Gestire correttamente messaggi concatenati o composti da più segmenti SMS.
10. [x] Aggiungere schermate di riepilogo per saldo, ricariche, consumi e prezzi per prefisso.
11. [x] Gestire il costo di acquisto per provider e prefisso.
12. [x] Gestire il prezzo di vendita per azienda, provider e prefisso.
13. [x] Mostrare acquisti, vendite e profitto nella dashboard del Super Admin.
14. [x] Gestire saldo, ricariche, uscite e credito residuo dei provider a livello Super Admin.
15. [x] Registrare e bloccare gli IP non autorizzati con Paese, bandiera e pagina di accesso negato.

## Sicurezza, internazionalizzazione e preventivo campagne

16. [ ] Eseguire un controllo completo della sicurezza del codice e dell'infrastruttura su tutti i protocolli e punti di accesso, non soltanto tramite browser web; prevenire bot, brute force, scansioni automatiche, injection, abuso delle API e altri vettori di attacco.
    - [x] Audit e hardening del codice applicativo, autenticazione, sessioni, CSRF, permessi, upload CSV, provider, TLS/SSRF, endpoint legacy e invii concorrenti.
    - [x] Aggiungere configurazioni di protezione per Apache/IIS, esempio Lighttpd, checklist di deploy e controllo statico automatico.
    - [x] Spostare o eliminare dalla document root `localhost.sql` e `log_messaggi.txt - collegamento.lnk`.
    - [x] Richiedere per ogni invio SMS una conferma firmata e monouso da un’estensione Chrome registrata, con approvazione/revoca amministrativa del dispositivo.
    - [x] Verificare i controlli da una rete esterna e aggiungere audit automatici per reverse proxy, percorsi sensibili, cookie/header e privilegi DB.
    - [x] Rimuovere dalla document root la chiave privata `chrome-extension.pem` risultata pubblicamente scaricabile; considerarla compromessa e rigenerarla prima della prossima distribuzione firmata.
    - [ ] Applicare su DSM/OpenResty la configurazione del reverse proxy, forzare HTTPS e bloccare `.git`, file di configurazione, documentazione e directory interne.
    - [ ] Creare e attivare un utente DB dedicato, ruotare le credenziali root e provider, quindi limitare MySQL/SSH/SMB alle sole reti amministrative.
17. [x] Rendere l'applicazione multilingua, centralizzando le traduzioni e permettendo la selezione della lingua per ciascun utente.
18. [x] Aggiungere alla campagna il campo calcolato "Spesa prevista": analizzare tutti i numeri della lista, riconoscere Paese e prefisso, applicare le relative tariffe e verificare prima dell'avvio se il credito disponibile è sufficiente per completare l'intera campagna.
20. [x] Estendere il listino oltre il solo prefisso internazionale, gestendo i costi per operatore telefonico tramite sotto-prefissi o intervalli numerici nazionali. Per esempio, per il prefisso italiano `+39`, riconoscere l'operatore dai numeri successivi e applicare il relativo costo di acquisto e prezzo di vendita.

## Architettura MVC

21. [ ] Migrare progressivamente il portale a un'architettura MVC a classi, mantenendo compatibili gli URL esistenti durante la transizione.
    - [x] Creare autoload, controller factory e renderer sicuro delle viste.
    - [x] Migrare il modulo Aziende con Model, Controller, Service, Repository e View.
    - [ ] Migrare autenticazione, dashboard e impostazioni.
    - [ ] Migrare utenti, team, dispositivi e firewall.
    - [ ] Migrare provider, crediti e listini.
    - [ ] Migrare invio singolo, liste, campagne e log.
    - [x] Introdurre un front controller pubblico e spostare ogni pagina in una cartella dedicata.
    - [ ] Rimuovere i bootstrap duplicati dopo la migrazione completa dei controller.
