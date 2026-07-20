# Database

`schema.sql` contiene esclusivamente la struttura delle tabelle, senza record applicativi.

Esempio di importazione:

```bash
mysql -u sms_app -p nome_database < database/schema.sql
```

Usare un database vuoto e credenziali dedicate all'applicazione.
