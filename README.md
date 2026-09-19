# Advanced Football Manager (Calcetto Manager)

Sito PHP + MySQL per gestire il calcetto tra amici: rosa con profili, partite con conferma
presenze, squadre bilanciate automaticamente, risultati, voti tra giocatori, MVP, classifica,
statistiche avanzate e pagamenti delle quote.

Il codice del sito è nella cartella [`calcetto/`](calcetto/). Serve PHP 8.0+ e MySQL/MariaDB.

## Accesso e iscrizione

Dalla pagina di login chi ha il link può **iscriversi** (nome, username, password, posizioni,
numero di maglia, piede). L'account resta **in attesa** e non vede nulla finché un admin non lo
approva: l'admin vede tutti i dati inseriti (e un pallino rosso nel menu segnala le richieste),
sceglie se creare un nuovo giocatore o collegare l'account a uno già in rosa, e può rifiutare.
**Abbinamento alla rosa:** se chi si iscrive ha lo stesso nome di un giocatore già in rosa (senza
account), l'iscrizione viene abbinata a lui in automatico: maiuscole, accenti, spazi e ordine delle
parole non contano ("mario rossi" = "Rossi Mario" = "Màrio Rossi"). Se i giocatori con quel nome sono
più di uno, o nessuno, l'admin sceglie all'approvazione (senza abbinamento viene creato un giocatore
nuovo). L'admin vede sempre l'abbinamento e può cambiarlo prima di approvare.

**Tutorial di benvenuto:** al primo accesso di ogni nuovo giocatore parte un giro guidato di tutte le
schede del sito (Home, Partite, Rosa, Classifica, profilo; l'admin vede anche Pagamenti e Admin), che si
può saltare. Una volta finito o saltato non riparte, ma si rivede dal pulsante «?» in alto. I passi sono
in `calcetto/lib/tour.php`, il disegno in `assets/app.js` e `assets/style.css`. Chi aveva già un
account attivo prima dell'introduzione del tutorial non lo vede in automatico.

Le password non si possono leggere da nessuno (sono salvate cifrate): l'admin può solo reimpostarle.
Con `REGISTRATION = false` in `config.php` le iscrizioni si chiudono e gli account li crea solo l'admin.
Senza login non si vede nulla (`PUBLIC_READ = false`).

Contro gli abusi: campo trappola per i bot, massimo 10 iscrizioni all'ora per indirizzo IP e
massimo 100 iscrizioni in attesa.

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
4. Manda il link agli amici: si iscrivono dalla pagina di login e tu approvi da *Admin*
   (oppure crei tu gli account da *Admin → Nuovo account*).

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

## Posizioni, squadre bilanciate e calendario

**Posizioni:** ogni giocatore ha una posizione preferita (Portiere, Difensore, Centrocampista, Attaccante)
e, se vuole, una seconda scelta; il **Jolly** ("si adatta a tutto") si può scegliere solo come seconda.
Nel profilo e nelle liste compaiono sempre entrambe.

**Squadre bilanciate** (`lib/balance.php`): per ogni divisione possibile (fino a 22 confermati le prova
tutte) si valuta la differenza di **rating** tra le squadre (rating base dell'admin + media dei voti dei
compagni + % di vittorie) e le **posizioni**: la 1ª scelta copre un ruolo al 100%, la 2ª al 60%, il Jolly
al 50% su qualsiasi ruolo. I portieri si dividono, e ogni squadra deve poter coprire difesa, centrocampo e
attacco del suo modulo (due posti scoperti nella stessa squadra costano più di uno per squadra). Sotto le
squadre si vedono forza totale e giocatori per ruolo.

**Intesa tra giocatori** (`lib/chemistry.php`, come la "chimica" dei vecchi FIFA): per ogni coppia che ha
giocato almeno 2 partite nella stessa squadra si guardano i **risultati** (punti a partita insieme contro la
media dei due), gli **assist** tra i due (li registra l'admin in «Chi ha fatto assist a chi», nella scheda
della partita; contano di più se si servono a vicenda) e i **gol** (a partita con e senza quel compagno). Ne
esce un bonus in punti rating, prudente con poche partite (peso n/(n+4)), che il bilanciamento somma alla
forza della squadra in cui i due giocano insieme (massimo ±2,5 a squadra). Nella scheda di ogni giocatore,
«Intesa e note» mostra i compagni con cui rende di più e le note (ad esempio quanto segna in più con lui).

**Gruppi** (es. YBQ e FANTA): l'admin li gestisce in Admin → Gruppi e assegna a ogni giocatore uno o più
gruppi (alla registrazione la persona sceglie i suoi, l'admin conferma). Ogni partita appartiene a un gruppo.
Un giocatore vede e partecipa solo a giocatori e partite dei suoi gruppi (le altre, anche aprendo il link,
danno «non trovato»); chi è in più gruppi li vede tutti e può filtrare con i pulsanti in alto; l'admin vede
tutto. Statistiche, classifica, pagamenti e intesa sono calcolati dentro al gruppo. Il gruppo iniziale si
chiama «Principale» e si rinomina (es. in YBQ).

**Google Calendar:** nella scheda di una partita in programma (e nella prossima partita della Home) il
pulsante «Aggiungi a Google Calendar» (e, nell'elenco Partite, il tasto con l'icona del calendario a destra di ogni partita in programma) apre Google Calendar con l'evento già compilato (data, ora, campo,
note, quota e link alla partita). Non serve nessun collegamento con l'account Google: la notifica arriva
con i promemoria del calendario di chi lo salva. La durata (`MATCH_DURATION_MIN` in `config.php`, 60
minuti) è fissa perché le partite non hanno una durata salvata.

**Intestazione:** da computer titolo e schede stanno sulla stessa riga e si compattano quando lo spazio
cala (sotto 1340px sparisce il nome accanto alla foto, sotto 1100px restano le icone con il nome della scheda
attiva). Da telefono (sotto 800px) c'è il titolo al centro, un pulsante a panino a sinistra che apre il menu
delle schede (con «Rivedi il tutorial» ed «Esci») e il profilo a destra, con il pallino delle iscrizioni.

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
