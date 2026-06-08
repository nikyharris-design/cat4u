# Come contribuire a Cat4U

Grazie per voler contribuire. Queste sono le linee guida di base per mantenere il progetto ordinato.

## Flusso di lavoro

1. Non lavorare direttamente sul branch `main`: crea un branch dedicato per ogni modifica (es. `feat/login-google`, `fix/scadenza-cataloghi`).
2. Fai commit piccoli e frequenti, con messaggi chiari.
3. Apri una Pull Request verso `main` descrivendo cosa cambia e perché.

## Convenzioni sui messaggi di commit

Ogni messaggio inizia con un prefisso che indica il tipo di modifica:

- `feat:` nuova funzionalità (es. `feat: aggiunta esportazione analytics`)
- `fix:` correzione di un bug (es. `fix: corretta scadenza cataloghi`)
- `refactor:` riorganizzazione del codice senza cambiarne il comportamento
- `style:` modifiche solo a CSS/HTML o formattazione
- `chore:` aggiornamenti di dipendenze, configurazione, manutenzione

## Prima di inviare una modifica

- Verifica che il progetto parta e che le pagine modificate funzionino.
- Controlla la sintassi dei file PHP modificati con `php -l nome-file.php`.
- Non includere mai il file `.env` o altri dati sensibili nei commit.