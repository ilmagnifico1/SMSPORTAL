# Estensione Firefox per SMS Portal

Questa variante usa le API WebExtensions di Firefox e lo stesso sistema di firma ECDSA P-256 della versione Chrome. La chiave privata resta nel profilo Firefox e ogni nuovo dispositivo deve essere approvato dal portale.

## Prova locale

1. Apri `about:debugging#/runtime/this-firefox`.
2. Premi **Carica componente aggiuntivo temporaneo**.
3. Seleziona `manifest.json` dentro questa cartella.
4. Accedi al portale, registra il dispositivo e approvalo in **Impostazioni → Dispositivi**.

L'installazione da `about:debugging` è temporanea e viene rimossa al riavvio di Firefox. Per una distribuzione permanente, carica il pacchetto ZIP presente in `dist/firefox-extension` su Firefox Add-ons per la firma oppure distribuisci l'estensione tramite criteri aziendali.

Il pulsante **Aggiorna estensione** nella pagina Impostazioni ricarica il codice già installato. Non installa autonomamente un nuovo pacchetto non firmato.
