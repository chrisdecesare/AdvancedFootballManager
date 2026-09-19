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
`calcetto/config.local.php`, escluso da Git (`.gitignore`) e dal deploy automatico. Ci sono due modi:

- **Su WordPress (es. Altervista):** si crea da solo con `setup-wordpress.php` (vedi sotto).
- **Altrove:** `cp calcetto/config.local.php.example calcetto/config.local.php` e compilalo
  (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `INSTALL_KEY`).

## Prima installazione su Altervista (spazio WordPress)

Sugli spazi WordPress di Altervista l'FTP entra **dentro `wp-content/`** e non c'è un MySQL
separato: il gestionale sta in `wp-content/calcetto/` e usa il database di WordPress
(le sue tabelle hanno nomi diversi da quelle `wp_...` di WordPress).

1. Configura il deploy (sezione sotto) ed esegui il workflow: carica il sito in `wp-content/calcetto/`.
2. Accedi a WordPress come amministratore e apri
   `https://TUOSITO.altervista.org/wp-content/calcetto/setup-wordpress.php`.
   Solo un amministratore WordPress può usarla. Clicca **Collega il database**: copia le
   credenziali in `config.local.php` (sul server) e mostra, una sola volta, il **codice di installazione**.
3. Apri `.../wp-content/calcetto/install.php`, inserisci il codice e scegli username e password
   dell'admin. `install.php` e `setup-wordpress.php` si cancellano da soli a fine uso.
4. Da *Admin* crea gli account degli amici.

## Prima installazione su un hosting PHP + MySQL normale

Crea `config.local.php` (vedi sopra), carica il contenuto di `calcetto/` (compresi i file
nascosti `.htaccess`), apri `/install.php`, inserisci `INSTALL_KEY`, username e password admin.

## Particolarità di Altervista (cache Varnish)

Davanti ai siti WordPress di Altervista c'è una cache che, nelle richieste GET, **toglie i cookie**
prima di darle a PHP, salvo quelli con nomi tipici di un utente WordPress loggato
(`wordpress_logged_in_<32 caratteri>`, `wordpress_sec_...`). Un cookie di sessione con un nome
qualunque non arriva mai: il login (un POST) riesce ma la pagina successiva non riconosce
l'utente e rimanda al login. Per questo `lib/bootstrap.php` chiama il cookie di sessione
`wordpress_logged_in_` + un codice proprio del gestionale (diverso da quello di WordPress, che
quindi lo ignora): per la cache è un utente loggato, quindi la pagina non viene cacheata.
Se il login "riesce ma resta sul login" solo per chi non è loggato in WordPress, il sintomo è questo.

## Deploy automatico (GitHub → Altervista)

Ogni push su `main` esegue [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml),
che carica via FTP **solo i file cambiati** in `wp-content/calcetto/`. Non tocca mai
`config.local.php` e le foto in `uploads/players/`.

Configurazione, una volta sola (repository → *Settings → Secrets and variables → Actions*):

| Tipo | Nome | Valore |
|---|---|---|
| Secret | `FTP_SERVER` | `ftp.nomeutente.altervista.org` |
| Secret | `FTP_USERNAME` | il tuo nome utente Altervista |
| Secret | `FTP_PASSWORD` | la password FTP |
| Variable (facoltativa) | `FTP_PROTOCOL` | `ftp` se Altervista rifiuta la connessione cifrata (predefinito: `ftps`) |
| Variable (facoltativa) | `FTP_DIR` | cartella di destinazione sul server (predefinita: `./calcetto/`) |

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
