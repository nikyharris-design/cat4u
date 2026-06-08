# Cat4U

Cat4U è un'applicazione web per la gestione e la pubblicazione di cataloghi PDF tramite QR code. Ogni azienda cliente ha la propria area riservata dove carica i cataloghi, li organizza per genere e genera automaticamente i QR code; i clienti finali sfogliano i cataloghi da una vetrina pubblica e ogni scansione viene tracciata in una sezione analytics.
## Prerequisiti

Per eseguire Cat4U in locale servono:

- **PHP 8.2 o superiore**, con le estensioni `gd` (generazione QR code) e `pdo_mysql` (connessione al database) attive
- **MySQL** o **MariaDB**
- **Apache** con il modulo `mod_rewrite` attivo (per gli URL puliti gestiti dal file `.htaccess`)
- **Composer** (per installare le librerie PHP)
## Installazione

1. **Clona o copia il progetto** nella cartella servita da Apache (con XAMPP, di norma `C:\xampp\htdocs\cat4u`).

2. **Installa le dipendenze PHP** con Composer, dalla cartella del progetto:

   3. **Crea il database.** In phpMyAdmin (o da MySQL) crea un database vuoto chiamato `cat4u`, poi importa lo schema delle tabelle.

4. **Configura le variabili d'ambiente.** Copia il file `.env.example` in un nuovo file chiamato `.env` e compila i valori con le tue credenziali (database, chiave Google Safe Browsing, parametri SMTP per l'invio email).

5. **Verifica i permessi della cartella `uploads/`**, in modo che l'applicazione possa salvarci i PDF e i QR generati.

6. **Avvia Apache e MySQL** dal pannello di XAMPP e apri il progetto nel browser:
## Variabili d'ambiente

La configurazione sensibile (credenziali database, chiavi API, parametri email) non è scritta nel codice ma in un file `.env` nella cartella principale, che **non viene mai versionato** (è escluso dal `.gitignore`).

Per sapere quali variabili servono, fai riferimento al file `.env.example`: contiene l'elenco completo delle chiavi di configurazione, senza valori sensibili. Per partire, copialo in `.env` e compila i valori con i tuoi dati.
