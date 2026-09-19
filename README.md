# Advanced Football Manager (Calcetto Manager)

Sito PHP + MySQL per gestire il calcetto tra amici: rosa con profili, partite con conferma
presenze, squadre bilanciate automaticamente, risultati, voti tra giocatori, MVP, classifica,
statistiche avanzate e pagamenti delle quote.

Il codice del sito è nella cartella [`calcetto/`](calcetto/). Serve PHP 8.0+ e MySQL/MariaDB.

## Accesso

Non esiste iscrizione libera: **gli account li crea solo l'admin** (pagina *Admin* o
*Rosa → Nuovo giocatore*). Senza login non si vede nulla (`PUBLIC_READ = false`).

Sicurezza: password di almeno 8 caratteri, blocco di 15 minuti dopo troppi tentativi falliti,
CSRF su ogni modulo, query preparate, cookie di sessione `Secure`/`HttpOnly`/`SameSite`,
HTTPS obbligatorio, cartella `uploads/` che serve solo immagini.

## Configurazione: `config.local.php`

Password del database e codice di installazione **non stanno su GitHub**. Vanno in
`calcetto/config.local.php`, escluso da Git (`.gitignore`) e dal deploy automatico:

```bash
cp calcetto/config.local.php.example calcetto/config.local.php
```
poi compilalo (su Altervista: `DB_USER` = nome utente, `DB_NAME` = `my_` + nome utente,
`DB_PASS` vuota se il pannello non ne indica una). Questo file si carica sul server **una
volta sola** via FTP.

## Prima installazione su Altervista

1. Pannello Altervista → **Database** → attiva MySQL; **Impostazioni PHP** → PHP 8.0 o superiore.
2. Carica via FTP (mostrando i file nascosti, per i `.htaccess`) il contenuto di `calcetto/`
   nella cartella del sito, **compresi** `config.local.php` e `install.php`.
3. Apri `https://nomeutente.altervista.org/install.php`, inserisci `INSTALL_KEY`, username e
   password dell'admin. Il file `install.php` si cancella da solo (controlla via FTP che sia sparito).
4. Da *Admin* crea gli account degli amici.

## Deploy automatico (GitHub → Altervista)

Ogni push su `main` esegue [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml),
che carica via FTP **solo i file cambiati**. Non tocca mai `config.local.php`, `install.php`
e le foto in `uploads/players/`.

Configurazione, una volta sola (repository → *Settings → Secrets and variables → Actions*):

| Tipo | Nome | Valore |
|---|---|---|
| Secret | `FTP_SERVER` | `ftp.nomeutente.altervista.org` |
| Secret | `FTP_USERNAME` | il tuo nome utente Altervista |
| Secret | `FTP_PASSWORD` | la password FTP |
| Variable (facoltativa) | `FTP_PROTOCOL` | `ftp` se Altervista rifiuta la connessione cifrata (predefinito: `ftps`) |
| Variable (facoltativa) | `FTP_DIR` | cartella del sito sul server, se non è la principale (es. `./calcetto/`) |

Da riga di comando: `gh secret set FTP_PASSWORD` (chiede il valore senza mostrarlo).
Il primo deploy si può lanciare anche a mano da *Actions → Deploy su Altervista → Run workflow*.

## Come si usa

| Chi | Cosa fa |
|---|---|
| **Admin** | crea partite, modifica presenze, genera le squadre, inserisce risultato/gol/assist/autogol, conclude la partita, chiude le votazioni, gestisce pagamenti, giocatori, account e statistiche |
| **Giocatore** | conferma o disdice la presenza, vede partite e statistiche, dopo la partita vota tutti gli altri (1-10) e l'MVP, modifica il proprio profilo (foto, numero, ruolo, piede, password) |

Ciclo di una partita: l'admin crea la partita → i giocatori confermano → l'admin genera le
squadre bilanciate → a fine partita inserisce il risultato → i giocatori votano → l'admin chiude
le votazioni e voti/MVP entrano nelle statistiche.

## Test in locale

Servono PHP 8.0+ con `pdo_mysql` e `gd`, e un MySQL/MariaDB. Le credenziali si passano con
variabili d'ambiente (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`) oppure con `config.local.php`;
per `install.php` serve comunque `INSTALL_KEY` in `config.local.php`.

```bash
cd calcetto
php -S localhost:8088
```
