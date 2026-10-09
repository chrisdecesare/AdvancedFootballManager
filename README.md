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

**Resta collegato:** chi entra non deve rifare l'accesso ogni volta (anche chiudendo il browser o l'app dalla schermata Home): sul dispositivo
resta un cookie con un codice casuale a lunga scadenza (180 giorni, che si sposta in avanti a ogni uso); nel database c'è solo la sua impronta
(tabella `auth_tokens`). Si perde uscendo dall'account («Esci») e quando la password dell'account viene cambiata (da lui o dall'admin): allora
tutti i dispositivi devono rifare l'accesso. Al massimo 10 dispositivi per account.

**Email, recupero password e sicurezza:** ogni giocatore può collegare la propria email da *Modifica profilo → Sicurezza* (`account.php`), o già
all'iscrizione (facoltativa). L'indirizzo conta solo dopo la conferma con il link ricevuto (un passaggio con il pulsante «Sì, conferma»). Con un'email
confermata, dalla pagina di accesso «Password dimenticata?» arriva un link per sceglierne una nuova: vale 60 minuti, si usa una volta sola e la risposta è
identica sia che l'account esista o no (non si scopre chi è iscritto). Senza email confermata la password si reimposta dall'admin, come prima.
Altre misure:
- **Password attuale richiesta** per cambiare password o email da `account.php` (con un limite di tentativi), e avviso via email (all'indirizzo confermato) quando cambiano password o email.
- **Cambio password = uscita ovunque:** cambiando o reimpostando la password (da sé, dal link o dall'admin) tutte le sessioni e i «resta collegato» degli altri dispositivi decadono
  (`users.session_version`); da `account.php` si può anche «Uscire da tutti gli altri dispositivi».
- **Password più solide:** minimo 8 caratteri, non troppo comuni («password», «12345678», «calcetto»...), non uguali allo username.
- **Limiti anti-abuso:** al massimo 8 richieste di recupero all'ora per connessione, 3 email di recupero e 5 di conferma all'ora per account.
- **I link delle email** usano l'indirizzo fissato da un admin (si salva da solo alla prima visita di un admin) e non l'intestazione `Host` della richiesta; i codici nei link sono casuali, nel database c'è solo l'impronta e si consumano al primo uso.
- **Invio:** su Altervista partono con la funzione `mail()` di PHP, dal mittente `noreply@<indirizzo del sito>`: possono finire nello spam. In *Admin → Email* c'è una
  email di prova. Facoltativi in `config.local.php`: `MAIL_FROM` (altro mittente), `MAIL_REPLY_TO`, `SITE_URL` (indirizzo del sito con la barra finale, per i link).

Le password non si possono leggere da nessuno (sono salvate cifrate): l'admin può solo reimpostarle.
Con `REGISTRATION = false` in `config.php` le iscrizioni si chiudono e gli account li crea solo l'admin.
Senza login non si vede nulla (`PUBLIC_READ = false`).

Contro gli abusi: campo trappola per i bot, massimo 10 iscrizioni all'ora per indirizzo IP e
massimo 100 iscrizioni in attesa.

**Livelli di sicurezza in più** (`lib/security.php`, riassunti anche in *Piattaforma → Sicurezza*):
- **Verifica in due passaggi** (da *Modifica profilo → Sicurezza*, `account.php`): oltre alla password serve il codice a 6 cifre di un'app
  di autenticazione (Google/Microsoft Authenticator, Authy...), con QR code e 8 **codici di recupero** usa e getta. Un codice già usato non vale una
  seconda volta. Chi gestisce il sito o una lega vede in cima alle pagine un avviso finché non la attiva. Se qualcuno perde il telefono e i codici,
  l'admin del sito gliela toglie da *Piattaforma → Account*.
- **Conferma della password** (`confirm.php`): *Admin* e *Piattaforma* la chiedono dopo 30 minuti di inattività (anche a chi resta collegato
  con il "resta collegato"), e le azioni distruttive (cedere o eliminare una lega, reimpostare password o eliminare account altrui) se è più vecchia
  di 10 minuti.
- **Avviso email per un accesso da un dispositivo nuovo** (browser + sistema), e per attivazione/disattivazione della verifica in due passaggi.
  I dispositivi visti si vedono in *Sicurezza*.
- **Blocco per username**: oltre ai limiti per connessione, 15 tentativi falliti sullo stesso username da connessioni diverse lo bloccano per
  15 minuti (attacchi distribuiti) e il proprietario riceve un'email.
- **Sessione legata al browser** che l'ha aperta: un cookie di sessione rubato e usato da un altro browser non vale.
- **Trappola a tempo** sui moduli pubblici (iscrizione, crea lega, password dimenticata): un modulo firmato inviato in meno di pochi secondi
  (o vecchio di ore) è di un bot. **Codici d'invito**: dopo 20 sbagliati in un'ora da una connessione, nessun link funziona più per un'ora.
  **Richieste di ingresso**: al massimo 10 al giorno per account.
- **Header** in più: `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy`, `X-Permitted-Cross-Domain-Policies`, Permissions-Policy estesa.
- **Eventi di sicurezza nel registro** (login falliti e bloccati, username sotto attacco, codici sbagliati, codici di recupero usati, nuovi
  dispositivi, sessioni da altri browser, richieste CSRF, accessi negati, bot, inviti tentati a caso) e scheda *Piattaforma → Sicurezza* con
  gli eventi della settimana, le connessioni sospette e chi ha poteri senza la verifica in due passaggi.
- La libreria del QR code è ospitata nel sito (`assets/vendor/qrcode.js`, licenza MIT): nessuno script esterno sulla pagina dei codici.

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
| Variable (facoltativa) | `FTP_DIR` | cartella di destinazione sul server (predefinita: `./calcetto/`) |

Da riga di comando: `gh secret set FTP_PASSWORD` (chiede il valore senza mostrarlo).
Il caricamento è sempre cifrato (FTPS): l'FTP semplice manderebbe password e codice in chiaro, quindi non è più un'opzione.
Il primo deploy si può lanciare anche a mano da *Actions → Deploy su Altervista → Run workflow*.

## Infortunati

Dalla scheda del giocatore (*Modifica*, per l'admin o chi amministra la lega) c'è la casella **Infortunato**. Da infortunato un giocatore:
- risulta **assente** in tutte le partite in programma, esce dalle squadre e dal campo, e le scommesse su di lui/lei saltano (come per chi si ritira, vedi sotto *Scommesse*);
- **non può confermare** la presenza (né da sé né da chi gestisce la partita) finché non viene segnato di nuovo disponibile;
- nelle partite create dopo (e se cambia lega) entra già come assente (`sync_match_players`);
- si vede in Rosa (etichetta rossa e riga «Infortunati» in alto) e nel profilo;
- i compagni di lega ricevono la notifica («Infortunio: …», `push_notify_injury` in `lib/webpush.php`), come quando un infortunio si segna nella cronaca della partita.

Quando guarisce le sue assenze non cambiano da sole: non si può sapere quali erano dell'infortunio e quali una sua scelta, quindi ridà lui/lei la presenza
sulle partite. La logica è `player_set_injured` in `lib/stats.php` (colonna `players.injured`, migrazione v27).

## La Gazzetta del mercoledì

Il "giornale" della lega (`lib/gazzetta.php`): in **Home in basso a destra** (sotto le curiosità, accanto all'ultima partita) e a tutta pagina
in `gazzetta.php` (*Sfoglia*). È uno slider a pagine, su carta rosa con i titoli da quotidiano:
- **Prima pagina**: la prossima partita, quanti sono confermati e i titoli delle altre pagine;
- **Infermeria**: chi è segnato infortunato nel profilo (rosso, ai box) e chi si è fatto male in partita nelle ultime 2 settimane (viola, con la nota);
- **Posti vacanti**: quanti posti mancano per la prossima partita e chi non ha ancora risposto. I posti sono quelli dei moduli scelti per
  la partita, altrimenti la misura tipica della lega (mediana dei giocatori delle ultime 5 partite, 10 se non ce ne sono);
- **Probabili formazioni**: se le squadre sono già fatte quelle ufficiali, altrimenti le prova il bilanciamento (rating + intesa, sempre la
  soluzione migliore) tra i confermati, completati da chi non ha risposto ma gioca più spesso (in corsivo, «in dubbio»; chi si è appena fatto
  male non entra). Per ogni squadra la forza e la «coppia d'oro» (la coppia con più intesa). Il calcolo resta in `meta` (`gzb_<partita>`)
  finché non cambiano i giocatori o le statistiche;
- **Turnover**: chi entra e chi esce rispetto all'ultima partita, e perché (infortunato, non ci sarà, non ha ancora risposto);
- **Novità**: l'ultima partita della settimana (risultato, MVP, bomber), i nuovi arrivati nella lega e gli oggetti usciti nel Negozio;
- **Mercato KOIN**: i passaggi di KOIN tra giocatori della settimana, con il messaggio.

Le pagine si calcolano al momento, quindi sono sempre aggiornate. **Ogni mercoledì** esce l'edizione nuova: dalle 10 arriva la notifica
«È uscita la Gazzetta del mercoledì» ai giocatori di ogni lega con una partita in programma o giocata nell'ultimo mese, una volta per lega
ed edizione (`gazzetta_push_due`, chiamata da `push_run_due`: cron o al volo mentre qualcuno usa il sito).

## Passaggi di KOIN tra giocatori

Da **Scommesse → «Passa KOIN a un compagno»** (o dal profilo di un compagno, *Passagli dei KOIN*) si passa una parte dei propri KOIN a un
giocatore della stessa lega, con un messaggio facoltativo (`lib/passaggi.php`). Sono due mosse del portafoglio (`wallet_moves.kind = 'passaggio'`,
`peer_id` = l'altro giocatore, migrazione 58): −N a chi dà, +N a chi riceve. Si passano solo i KOIN disponibili (non quelli puntati), solo dentro
la stessa economia (i KOIN di una lega restano lì) e al massimo `KOIN_PASS_DAILY_MAX` (1000) al giorno. Chi riceve ha la notifica push e,
alla prima pagina che apre, la sovraimpressione «+N KOIN!» col nome di chi li ha passati. I passaggi della settimana finiscono nella Gazzetta.

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
gruppi (chi si iscrive non vede i gruppi: li assegna l'admin quando approva l'iscrizione). Ogni partita appartiene a un gruppo.
Un giocatore vede e partecipa solo a giocatori e partite dei suoi gruppi (le altre, anche aprendo il link,
danno «non trovato»); chi è in più gruppi li vede tutti e può filtrare con i pulsanti in alto; l'admin vede
tutto. Statistiche, classifica, pagamenti e intesa sono calcolati dentro al gruppo. Il gruppo iniziale si
chiama «Principale» e si rinomina (es. in YBQ).

**Foto e sfondo del profilo:** in «Modifica profilo» chi sceglie una foto vede subito una finestra di ritaglio: trascina
e ingrandisce (cursore, rotella o pizzico con due dita) per decidere quale parte tenere, e viene caricata solo quella (400x400).
Lo sfondo della scheda del profilo (e della carta nella Rosa) può essere automatico (colore del ruolo), un colore a scelta oppure un'immagine.
Per l'immagine si apre un editor con la **foto intera** e **due riquadri** da scegliere sulla stessa foto: quello **orizzontale** (4:1, l'intestazione
del profilo sui computer) e quello **verticale** (3:4, la card nella Rosa e il profilo sui telefoni, dove l'intestazione va a capo). Ogni riquadro si
sposta trascinandolo e si allarga o stringe dall'angolo giallo, con il cursore dello zoom, la rotella o due dita; sotto ci sono le due anteprime vere
(«Così nel profilo», «Così nella Rosa»), anche nel modulo, ciascuna con la **qualità** che avrà (ottima, buona, discreta, bassa: dipende dai
pixel veri dentro il riquadro, quindi dallo zoom e dalla foto). Il server tiene l'originale (`bg_src`, fino a 3200 px; un JPG già di quella misura
resta intatto, senza ricomprimerlo) e ne ricava i due ritagli (`bg_image` fino a 2400x600 e `bg_image_v` fino a 900x1200, pensati per gli schermi
ad alta densità e mai ingranditi oltre i pixel veri; riquadri in `bg_crop`): con «Cambia ritaglio» si scelgono parti diverse senza ricaricare la foto.
Il browser manda la foto intatta se sta nel limite di caricamento del server (`upload_max_filesize`), altrimenti una copia di alta qualità che ci sta.
Gli sfondi caricati prima hanno solo la versione orizzontale: si vedono come prima e con «Cambia ritaglio» si rifanno da quella.

**Google Calendar:** nella scheda di una partita in programma (e nella prossima partita della Home) il
pulsante «Aggiungi a Google Calendar» (e, nell'elenco Partite, il tasto con l'icona del calendario a destra di ogni partita in programma) apre Google Calendar con l'evento già compilato (data, ora, campo,
note, quota e link alla partita). Non serve nessun collegamento con l'account Google: la notifica arriva
con i promemoria del calendario di chi lo salva. La durata (`MATCH_DURATION_MIN` in `config.php`, 60
minuti) è fissa perché le partite non hanno una durata salvata.

**Intestazione:** da computer titolo e schede stanno sulla stessa riga e si compattano quando lo spazio
cala (sotto 1340px sparisce il nome accanto alla foto, sotto 1100px restano le icone con il nome della scheda
attiva). Da telefono (sotto 800px) c'è il titolo al centro, un pulsante a panino a sinistra che apre il menu
delle schede (con «Rivedi il tutorial» ed «Esci») e il profilo a destra, con il pallino delle iscrizioni.

## Scommesse e Negozio (goliardici)

La scheda **Scommesse** (`bets.php`, logica in `lib/bets.php`) fa puntare sulle partite in programma con **KOIN finti**: nessun euro in gioco.
Ognuno parte con 100 KOIN. Per ogni partita si può fare una puntata per mercato:

- **Chi vince?** (squadra 1, pareggio, squadra 2): si paga quando l'admin/manager chiude la partita col risultato;
- **Chi segna?** (un giocatore segna almeno un gol): si paga alla chiusura della partita;
- **Chi sarà l'MVP?**: si paga quando le votazioni si chiudono.

**Quote fisse, calcolate come dai bookmaker:** si stima la probabilità di ogni esito e la quota decimale è `1 / (probabilità × (1 + margine))`. Il margine (l'*overround*, il
guadagno del banco) è del 6% sull'esito, 12% su chi segna, 15% sull'MVP: per questo la somma delle probabilità implicite (1 / quota) di un mercato supera il 100%. La quota si
salva con la puntata (`bets.odds`): vincita = puntata × quota, chi sbaglia perde la puntata. Come si stimano le probabilità (`bet_quotes` in `lib/bets.php`):

- **Chi vince?** modello di **Poisson** sui gol: dai gol medi per squadra del gruppo e dalla differenza di rating medio tra le due squadre si ricavano i gol attesi di ciascuna, poi si sommano
  le probabilità di tutti i punteggi possibili per avere 1, X e 2 (con una correzione che aumenta i pareggi, come nei modelli tipo Dixon-Coles). Se le squadre non sono ancora fatte la partita è in equilibrio;
- **Chi segna?** i gol attesi della partita (o della squadra) si ripartiscono tra i giocatori in proporzione ai loro gol a partita, mescolando **stagione** (stabilizzata: con poche partite conta il
  valore tipico del ruolo), **ultime 5 partite** e **stato di forma**; probabilità di segnare = 1 − e^(−gol attesi). Chi segna spesso ed è in forma ha quota bassa, chi non segna mai quota alta
  (fino al tetto della quota). Il valore tipico del ruolo dipende da come si gioca, scelto per ogni partita
  (*Portieri: volanti / fissi* nella creazione e in *Gestione partita*, predefinito «volanti»): con i **portieri volanti** nessuno sta fisso in porta, quindi
  chi si segna portiere o difensore vale come un difensore/centrocampista, di poco sotto gli attaccanti (POR 0,30 · DIF 0,34 · CEN 0,40 · ATT 0,50 gol a partita);
  con i **portieri fissi** il portiere non segna quasi mai (POR 0,03 · DIF 0,18 · CEN 0,35 · ATT 0,65). Col passare delle partite contano sempre più i gol veri;
- **Chi sarà l'MVP?** pesano i premi MVP, la media voto (stagione e ultime partite), la forma, i gol attesi e la probabilità che la sua squadra vinca; le probabilità si normalizzano a 100% prima del margine.

Chi non ha ancora confermato la presenza vale meno (potrebbe non esserci). Le costanti (margini, peso della forma, correzione dei pareggi) sono in cima a `lib/bets.php`.
**Quote alzate nelle prime partite:** con pochi dati l'incertezza è maggiore, quindi finché un gruppo non ha ancora nessuna partita giocata
(e per tutte le partite già in programma quando è arrivata questa regola) ogni quota diventa `quota × 1,17 + c`, con `c` tra 0,2 e 0,5
(`BET_BOOST_MULT`, `BET_BOOST_C`, funzione `bet_boost`). La `c` cambia da una scelta all'altra ma resta la stessa per la stessa scelta, quindi
ricaricare la pagina non la cambia. Con l'aggiornamento del database (versione 28) tutte le puntate ancora aperte, singole e multiple, sono state
ricalcolate con le quote nuove (il riepilogo è in `meta.requote_v28`).

Se manca il dato (nessuno ha votato l'MVP) le puntate sono rimborsate. **Le scommesse si aprono 48 ore prima della partita** (`BET_OPEN_HOURS`; se la partita
viene creata a meno di 48 ore, sono aperte subito) e si chiudono al calcio d'inizio: fino ad allora si può cambiare o ritirare la puntata.

**Ruoli bloccati con le scommesse aperte:** da quando si aprono le scommesse di una partita del suo gruppo fino alla fine della partita (al massimo 12 ore dopo
l'inizio, anche se nessuno la chiude) un giocatore non può cambiarsi posizione preferita e seconda posizione da *Modifica profilo*: c'era chi si segnava portiere
per alzare la quota «chi segna», puntava e poi tornava attaccante, falsando le quote e la generazione delle squadre. L'admin può comunque correggere il ruolo di chiunque.

Chi resta al verde (meno di 20 KOIN e nulla in gioco)
riceve un sussidio di 30 KOIN a settimana. Ci sono titoli goliardici in base ai KOIN e la classifica dei più ricchi.

Il portafoglio non è un numero salvato ma la somma delle mosse (`wallet_moves`), quindi correggere un risultato, riaprire una partita o riaprire le votazioni
rifà i pagamenti da solo, e cancellare una partita restituisce i KOIN. Le costanti (KOIN iniziali, soglia e importo del sussidio, margine) sono in cima a `lib/bets.php`.

**Negozio** (`shop.php`, logica in `lib/shop.php`, catalogo in `lib/shop_items.php`): i KOIN si spendono per personalizzare il profilo, e le personalizzazioni si vedono sul profilo e nella Rosa.
Quattro schede, con filtro «che posso comprare / miei» e un pulsante **Prova** che mette l'oggetto addosso all'anteprima fissa in alto, prima di comprarlo:

- **Copricapi (100)**, in diagonale su un angolo del riquadro: cappellini, berretti, cowboy, cuoco, mago, pirata, vichingo, corone, elmetti, orecchie da gatto, gelato, zucca, coppa... Sono disegni SVG in stile
  fumetto costruiti da 52 forme (`lib/hats.php`) con colori diversi: per un cappello nuovo basta una riga nel catalogo;
- **Bordi (30)**: un anello attorno al riquadro, in tinta unita, a gradiente, a motivi (cantiere, scacchi, greca), con alone al neon o animato (arcobaleno in movimento, scarica elettrica, oro pulsante; le
  animazioni si fermano a chi ha attivo «riduci movimento»);
- **Sfondi (50)** al posto delle strisce del ruolo (prato, aurora boreale, tigre, marmo, fibra di carbonio, rubino...); sostituiscono il colore o l'immagine scelti da *Modifica profilo* (e viceversa);
- **Nickname (50)** sotto il nome: 17 si comprano, 33 **si sbloccano da soli** con un obiettivo: gol (5, 10, 25, 50; 3 o 4 in una partita), assist (3, 10, 25, 50), gol + assist, premi MVP (1, 3, 5, 10, 20),
  presenze (1, 15, 30, 60), vittorie (10, 25, 5 di fila), media voto (7,5 o 8), autogol, sconfitte, scommesse vinte, KOIN e oggetti comprati.

Prezzi e obiettivi si cambiano in `lib/shop_items.php` (una riga per oggetto); il disegno di sfondi e bordi sta in `assets/style.css` (classi `bgp-<chiave>` e `brd-<chiave>`). Gli acquisti stanno in `player_items`,
cosa si indossa adesso nelle colonne `bg_preset`, `border_key`, `nick_key`, `hat_key` di `players`.

**Vendere gli oggetti:** un oggetto comprato (nel Negozio o nell'Avatar) si può rivendere con «Vendi»: torna **metà di quanto l'avevi pagato**
(`SHOP_SELL_RATE` in `lib/shop.php`, arrotondato per difetto), nella stessa moneta: la parte pagata con i crediti dell'Avatar torna in crediti (che non
valgono per le scommesse), il resto in KOIN della lega in cui l'avevi comprato (colonne `player_items.paid_credits` e `paid_eco`). Se lo indossavi, te lo
togli; se lo rivuoi, lo ricompri al prezzo di quel momento. Gratis, premi del Fanta e nickname sbloccati non si vendono.

**Copricapi e capelli dell'Avatar:** quasi tutti i copricapi schiacciano i capelli alti (codino, ricci, afro, creste diventano un taglio corto). Quelli che
non poggiano sulla testa o la cingono appena (aureola, coppa, pallone, alloro, fiori, cuffie, bandana e le bandiere) lasciano i capelli come sono, e
aureola, coppa e pallone salgono sopra un codino o un afro (`PX_HATS_KEEP_HAIR`, `PX_HATS_FLOAT` in `lib/avatar_pixel_art.php`).

**Overall:** dove prima si vedeva il rating (1-10, che sembrava un voto) ora si vede l'**overall** in stile videogioco, da 1 a 99 (rating × 10): in cima al profilo, sulla carta nella Rosa, in Classifica e nelle formazioni.
Si calcola come prima (rating base deciso dall'admin, media voto e percentuale di vittorie) ed è quello che serve a bilanciare le squadre.

**Campo:** il nome del campo (in Home e nella partita) è un link: apre l'itinerario di Google Maps fino al campo partendo dalla posizione attuale di chi clicca.
Conviene scrivere nel campo «Campo» nome e indirizzo (es. «Centro sportivo Rossi, Via Roma 1, Milano»).


**Regole aggiunte:**
- **Niente scommesse su se stessi** nei mercati sui giocatori (chi segna, doppietta, tripletta, over 3,5, assist, gol + assist, autogol, MVP, miglior difensore): i pulsanti non compaiono e il server
  le rifiuta, anche dentro una multipla.
- **Quote dal vivo nella schedina**: mentre la pagina è aperta la schedina chiede al server le quote ogni 20 secondi (`bets.php?quote=1`) e
  aggiorna pulsanti, selettore dell'over/under e selezioni già messe, con una freccia ▲/▼ quando una quota cambia; le partite ormai chiuse escono
  dalla schedina. Se al momento di puntare la quota è diversa da quella vista, il messaggio lo dice. Le quote mostrate sono quelle con cui si
  punta davvero (senza contare le proprie puntate nella domanda).
- **Over/under che si muove tutto insieme**: prima la domanda abbassava solo la soglia giocata, e l'over 9,5 molto giocato finiva sotto
  l'over 8,5 (che è più facile). Ora i KOIN puntati spostano i **gol attesi** della partita, come fanno i bookmaker con la linea: più KOIN
  sugli over che sugli under li alzano (fino al 25%, `BET_OU_SHIFT`), e con loro cambiano le quote di tutte le soglie, over e under. Ogni
  puntata pesa per quanto è in bilico la sua soglia (`bet_ou_lean`): tanti KOIN sull'over 20,5 non spostano il mercato. Sulla singola
  soglia la domanda pesa meno che negli altri mercati (`BET_OU_DEMAND`), e alla fine `bet_ou_monotone` garantisce l'ordine: più è alta la
  soglia, più paga l'over e meno l'under (se due soglie vicine si invertono, la più facile scende a quella della più difficile).
- **Ruolo meno pesante**: i gol attesi di partenza per ruolo sono vicini tra loro (portieri volanti: chi è in porta prima o poi tira), i gol
  attesi dei giocatori si avvicinano del 30% alla media della partita (`BET_FLATTEN`) e i tetti delle quote sono più bassi (gol ×15,
  doppietta ×35, tripletta ×75, MVP ×40).
- **Mercato «Chi fa autogol?»** (`autogol`): si punta su un giocatore che fa almeno un autogol, si paga a fine partita con quelli inseriti nel risultato.
  È un evento raro, quindi le quote sono alte (tra ×2 e ×40, margine del 20%): il tasso di ogni giocatore (autogol a presenza) è tirato forte verso quello di
  tutto il gruppo (`BET_OG_PRIOR`, `BET_OG_PRIOR_APPS`, `BET_OG_OWN_APPS` in `lib/bets.php`). Nella multipla si possono mettere più giocatori, e lo stesso
  giocatore può stare anche in «segna»: fare gol e fare autogol non si comprendono a vicenda.
- **Mercati «Chi fa assist?», «Chi fa gol + assist?», «Chi fa over 3,5 gol?» e «Miglior difensore?»** (`assist`, `golassist`, `over35`, `difensore`):
  - `assist`: il giocatore fa almeno un assist. Gli assist attesi della partita sono una quota dei gol (`BET_ASSIST_PRIOR`, poi i dati del gruppo) e si ripartiscono come i gol, con le stesse differenze attenuate.
  - `golassist`: almeno un gol **e** almeno un assist nella stessa partita; la probabilità è quella del gol per quella dell'assist (come nel sito di riferimento della lega).
  - `over35`: il giocatore segna più di 3,5 gol, cioè almeno 4. Quote altissime (da ×2,5 a ×150, margine del 20%).
  - `difensore`: si punta su chi vincerà il premio «Miglior difensore» votato dai giocatori insieme all'MVP (`MATCH_AWARDS`, `award_votes`): vale lo stesso vincitore che compare nella partita (più voti, a parità la media voto più alta). Si paga alla chiusura delle votazioni come l'MVP (`BET_VOTE_MARKETS`); se nessuno ha votato il premio, rimborso. Candidati: tutti tranne gli ospiti e, con i portieri fissi, i portieri; il ruolo preferito pesa sulle quote (`BET_DEF_ROLE`). Il mercato compare con almeno due candidati.
  - Nella multipla lo stesso giocatore non può stare in due scelte che si comprendono: «segna», «doppietta», «tripletta», «over 3,5» e «gol + assist» tra loro; «assist» e «gol + assist» tra loro. «Miglior difensore» ha una sola scelta per partita, come l'MVP.
- **Importo modificabile dall'admin**: chi amministra la lega vede, sotto ogni mercato, `Admin: cambia l'importo di una puntata`: cambia solo quanto si gioca (la quota presa resta), il portafoglio si aggiorna (`bet_admin_set_stake`). Solo per puntate singole aperte di partite non ancora giocate.
- **Chi si ritira porta via le sue scommesse** (`bets_void_for_player`): quando un giocatore si segna «Non ci sono» (da sé, o lo fa chi gestisce la partita),
  le puntate singole su di lui/lei in quella partita (chi segna, doppietta, tripletta, over 3,5, assist, gol + assist, autogol, MVP, miglior difensore) vengono cancellate e i KOIN tornano; nelle
  multiple si toglie **solo quella selezione**, la multipla resta con le altre e la quota si ricalcola (se non ne restano, sparisce e i KOIN tornano).
  Chi vince, over/under e le scommesse sugli altri giocatori non si toccano.
- **Premi per gol e assist**: 100 KOIN per ogni gol e 50 per ogni assist (`BET_REWARD_GOAL`, `BET_REWARD_ASSIST`), più gli stessi importi in crediti dell'Avatar (`SHOP_CREDIT_GOAL`, `SHOP_CREDIT_ASSIST`), a chi li ha fatti, appena
  il risultato viene salvato; se viene corretto il premio si aggiorna, se la partita torna «programmata» o viene eliminata sparisce. Una mossa
  del portafoglio per giocatore e partita (`kind = 'premio'`). Il totale si vede nella scheda «Scommesse».
- **Premi MVP e miglior difensore**: alla chiusura dei voti, 1000 KOIN a chi vince l'MVP e 250 a chi vince il miglior difensore (`BET_REWARD_MVP`, `BET_REWARD_DIF`,
  `match_vote_prizes_sync` in `lib/bets.php`; ospiti esclusi; partite da `VOTE_PRIZES_FROM`). Sono KOIN normali del portafoglio (non crediti dell'Avatar), quindi si spendono
  in tutto il Negozio e nell'Avatar. Una mossa per premio e partita (`premio-mvp-m<id>`, `premio-dif-m<id>`): cambia giocatore se il vincitore cambia, sparisce se i voti si
  riaprono, la partita è annullata/riportata a programmata/eliminata. Al vincitore arriva una push e, alla prima pagina che apre, la sovraimpressione «Congratulazioni!»
  (`match_prizes_unseen` in `lib/guess.php`, `layout.php`; stesso `players.gift_seen_id` dei regali). La migrazione v57 li assegna anche alle partite già chiuse dall'8 ottobre 2026.
- Al passaggio al nuovo modello (migrazione v26) le puntate ancora aperte sono state riprezzate, quelle su se stessi annullate e rimborsate, e i
  premi assegnati anche per le partite già giocate.
**Regalare KOIN:** in *Admin → Regala KOIN* l'admin sceglie un giocatore delle sue leghe, una cifra e, se vuole, un **messaggio** (fino a 200 caratteri):
si aggiunge una mossa `regalo` al portafoglio, visibile subito nel saldo, in classifica e nel Negozio. Il giocatore riceve la notifica push (con il messaggio)
e, alla prima pagina che apre, la sovraimpressione col KOIN con il messaggio in un fumetto. Ogni admin regala **al massimo 500 KOIN al giorno** in tutto
(`COIN_GIFT_DAILY_MAX` in `lib/guess.php`, dalla mezzanotte; colonne `wallet_moves.note` e `given_by`). I premi di «Indovina la funzionalità» non contano
nel limite. Ogni regalo finisce nel registro delle operazioni.

**Oggetti nuovi:** quando escono oggetti da *Uscite* (subito o a una data), alla prima richiesta dopo l'uscita (o da `cron.php`) parte una notifica push
a chi le ha attive, con quanti oggetti e di che tipo. Chi non ha le notifiche vede un pallino rosso sulla scheda del menu (Avatar o Negozio) e sulla
categoria, finché non la apre. Per 7 giorni gli oggetti usciti hanno l'etichetta «Nuovo» (`SHOP_NEW_DAYS`, logica in `lib/shop.php`: `shop_news_*`).
Gli oggetti dell'Avatar usciti prima del suo lancio non si annunciano.

**Rivelazione del countdown:** in *Admin → Indovina la funzionalità*, oltre alla data di uscita, si può scegliere un'ora in cui la novità smette di essere
«top secret» e si dice cos'è (il countdown continua fino all'uscita); da quel momento non si mandano più idee. La card della sorpresa in Home è stata tolta:
il countdown resta nella pagina «Indovina la funzionalità» (`guess.php`).

## Fanta (fantacalcio della lega)

La scheda **Fanta** (`fanta.php`, indirizzo `/fantacalcio`, logica in `calcetto/lib/fanta.php`) è il fantacalcio della lega: ogni giocatore con un
account è anche fantallenatore e si compra le **figurine** dei compagni. Più squadre possono avere la stessa figurina.

- **Lancio:** fino a `FANTA_LAUNCH_AT` (venerdì 2 ottobre 2026 alle 16, in cima a `lib/fanta.php`) lo vede solo l'admin del sito, senza indizi per gli altri.
- **Stagioni:** le apre e le chiude chi amministra la lega, dalla pagina stessa; contano le partite che iniziano dopo l'apertura.
- **Quote (1-4 crediti):** la **quota attuale** di una figurina la dà la sua ultima partita, appena si chiudono le votazioni, dai punti fanta
  della partita (voto + bonus): 1 sotto 6,5 punti (ha perso senza fare granché), 2 fino a 9,5 (una partita normale), 3 fino a 13 o con voto da 7,5
  (una bella partita), 4 da 13 in su o con voto da 8,5 (una prestazione sontuosa). Chi non ha ancora giocato vale 1. La **quota base** è la quota attuale
  all'apertura della stagione (`fanta_prices`). Soglie in cima a `lib/fanta.php` (`FANTA_QUOTE_PTS`, `FANTA_QUOTE_VOTE`).
- **Rosa:** 6 figurine (5 titolari e 1 in panchina) con 15 crediti fanta di partenza (erano 10: alle squadre già fatte se ne sono aggiunti 5), separati dai KOIN (salvati in `fanta_teams`). Si compra e si vende
  quando si vuole, sempre alla quota attuale: chi compra a 1 e rivende a 4 guadagna 3 crediti. **Chi vince una partita della lega** (in campo, con un account) guadagna 1 credito
  (`FANTA_WIN_CREDITS`, tabella `fanta_win_credits`): si toglie se il risultato viene corretto o la partita torna programmata, viene annullata o eliminata;
  contano le partite dal 7 ottobre 2026. Tra i titolari c'è un **capitano**, che raddoppia bonus e malus (un gol vale +6).
- **Punti di una figurina in una partita:** media dei voti ricevuti + 3 a gol, +1 ad assist, +3 all'MVP, +1 se la squadra vince, −2 ad autogol; chi non
  gioca fa 0. La **panchina** entra al posto del primo titolare che non gioca. Finché le votazioni sono aperte i punti sono provvisori.
- **Formazione al calcio d'inizio:** per ogni partita conta la rosa com'era al fischio d'inizio (tabella `fanta_lineups`): la «foto» si scatta alla prima
  richiesta dopo il calcio d'inizio (e da `cron.php`), e sempre prima di qualsiasi cambio di rosa, così i cambi fatti dopo non toccano quella partita.
- **Scambi:** un fantallenatore propone «ti do X, mi dai Y» a un altro, che accetta o rifiuta (notifica push a tutti e due). Le figurine devono essere
  diverse e nessuno può ritrovarsi due volte lo stesso giocatore. I crediti non cambiano; la figurina che arriva prende il posto (titolare/panchina) e la fascia di quella che parte.
- **Classifica:** tutti contro tutti, somma dei punti della stagione (a pari punti conta la partita migliore).
- **Premi:** alla chiusura i primi 5 ricevono oggetti che nel Negozio non si comprano e non si vedono (`fanta_reward_items()`, con `'fanta' => N` = va ai primi N):
  esultanze *Pioggia di KOIN*, *Giro d'onore* e *Alza la coppa*, creste *Cresta ribelle* e *Cresta d'oro*, *Alloro* e *Corona del Fanta*,
  *Maglia del Campione* e il nickname *Re del Fanta*. Chi arriva più in alto prende anche i premi dei posti sotto; un premio già vinto diventa 60 KOIN.
  I piazzamenti restano nell'albo d'oro (`fanta_awards`).

## Notifiche push

I giocatori possono ricevere notifiche sul telefono (o sul computer) anche a sito chiuso, con il logo del sito
(la palla dell'intestazione). Ognuno le attiva sul proprio dispositivo: in Home compare un invito («Vuoi le
notifiche?»), oppure da *Modifica profilo → Notifiche* (con il pulsante per una notifica di prova). Arrivano:

- **quando viene creata una partita**, a tutti i giocatori del gruppo che devono ancora rispondere;
- **come promemoria** a chi non ha ancora confermato né disdetto: 48 ore e 6 ore prima della partita (mai di notte, dalle 23 alle 8;
  se la partita viene creata a ridosso ne parte uno solo, e non a chi ha appena ricevuto l'avviso di nuova partita);
- **agli admin, quando qualcuno si iscrive** e chiede di entrare nella lega («Nuova richiesta di iscrizione»: nome, username, se è già in rosa e quante richieste ci sono da approvare); toccandola si apre *Admin*.
  Serve che l'admin abbia attivato le notifiche su almeno un dispositivo;
- **a chi si è appena iscritto, quando l'admin approva** («Iscrizione approvata!»): chi non ha ancora il login non ha un account con cui entrare, quindi subito dopo l'iscrizione la pagina
  «Richiesta inviata» offre di attivare le notifiche su quel dispositivo (vale solo per quel browser e solo finché la richiesta è in attesa). Se ha lasciato un'email, all'approvazione riceve anche
  un'**email** con il link per entrare: va all'indirizzo confermato o, se non è ancora confermato, a quello scritto all'iscrizione;
- **quando le votazioni si aprono** (o si riaprono) e **quando si chiudono** (con il nome dell'MVP), a chi ha giocato la partita.
- **quando cambia chi gioca**, solo a chi ha confermato la presenza: qualcuno conferma («Un giocatore in più») oppure chi aveva confermato dice che non ci sarà
  o annulla la conferma («Un giocatore in meno»), con il numero dei confermati. Vale anche quando la modifica la fa l'admin; chi fa la modifica e il giocatore interessato non la ricevono;
- **quando si aprono le scommesse** (48 ore prima, o subito se la partita è creata a ridosso; di notte aspetta le 8 se c'è tempo) e **quando manca un'ora** alla partita
  («Ultima ora per scommettere»), a tutti i giocatori del gruppo. Partono dal controllo periodico (vedi il cron più sotto): senza cron arrivano alla prima pagina aperta da qualcuno;
- **per ogni gol segnato durante la partita** (cronaca in diretta, vedi sotto), solo a chi del gruppo **non** sta giocando, con marcatore, assist e risultato;
- **quando una partita in programma cambia** (data, ora, campo, quota o note) o **viene annullata**, ai giocatori della partita
  (a chi aveva già risposto «Non ci sono» solo se cambia la data);
- **se cambia il giorno della partita**, oltre alla notifica («Partita spostata») tutte le risposte «Ci sono / Non ci sono» tornano
  «in attesa», le squadre già fatte si azzerano e ripartono i promemoria: tutti devono rispondere di nuovo. Cambiando solo l'ora o il campo le risposte restano.

Se sul telefono compare l'errore «push service error» il problema è nella registrazione presso il servizio notifiche di Google (FCM), non nel sito:
`assets/push.js` riprova da solo fino a 3 volte (ripulendo abbonamento e service worker) e, se non riesce, spiega cosa controllare
(internet, VPN / DNS privato / blocca-pubblicità, Google Play Services, data e ora del telefono, Brave).

Chi esce dall'account toglie il dispositivo dalle notifiche; al prossimo accesso (di chiunque) si riabbina da solo.
In *Admin → Notifiche* si vede quanti account le hanno attive.

**iPhone/iPad:** Apple le permette solo alle app aggiunte alla schermata Home (iOS 16.4 o successivo): in Safari «Condividi» →
«Aggiungi alla schermata Home», poi aprire Calcetto Manager dall'icona e attivare le notifiche da lì. Il sito è installabile come
app anche su Android e computer (`manifest.webmanifest`).

**Come funziona (nessuna configurazione):** Web Push con chiavi VAPID che il sito crea da solo alla prima necessità (tabella `meta`),
messaggio cifrato secondo RFC 8291, implementato in `lib/webpush.php` senza librerie: serve solo l'estensione `openssl` di PHP
(più `curl`, se c'è, per mandare le notifiche a più dispositivi insieme). Il browser si abbona con `assets/push.js` + `sw.js`
(service worker) e registra l'abbonamento in `push.php`. Le notifiche partono dopo aver mandato la pagina a chi ha premuto il pulsante
(il salvataggio non aspetta l'invio). Per sicurezza si accettano solo gli indirizzi dei servizi push dei browser (Google, Mozilla, Apple, Microsoft).

**Promemoria puntuali (facoltativo):** i promemoria partono da soli mentre qualcuno usa il sito (al massimo un controllo ogni 10 minuti).
Per averli puntuali anche quando nessuno lo apre, si può far chiamare `cron.php?key=CODICE` ogni 10-15 minuti da un pianificatore
(il cron dell'hosting o un servizio gratuito come cron-job.org). Il codice segreto e l'indirizzo completo si trovano in *Admin → Notifiche*.

## Curiosità

Ogni giocatore può scrivere delle curiosità su di sé (max 300 caratteri, fino a 30) dalla propria scheda in Rosa, sezione «Curiosità»;
le può scrivere e cancellare anche l'admin. Si leggono nella scheda del giocatore, nella scheda **Curiosità** (tutte, dalla più
recente, filtrabili per gruppo come il resto del sito) e, sotto le informazioni sulle partite, in Home: lì girano da sole, una alla volta
e **una nuova ogni 15 secondi** (dissolvenza e barretta che mostra il tempo che manca; si fermano se la pagina non è in primo piano), fino a 40 in ordine casuale a ogni visita
(`HOME_FACTS` e `FACT_SECONDS` in `lib/curiosities.php`). Come per il resto, ognuno vede solo le curiosità dei giocatori dei suoi gruppi.

## Leghe create dagli utenti (self-service)

Chiunque può **creare la propria lega** da `create_league.php` (link «Crea la tua lega» nella pagina di accesso e d'iscrizione), senza passare
dall'admin del sito: chi non ha un account lo crea nello stesso modulo, chi ce l'ha scrive solo il nome. Chi crea la lega ne è il **proprietario**,
ci entra come giocatore e la gestisce da **«La mia lega»** (`league.php`, nel menu):
- **Link d'invito** (`join.php?c=CODICE`) da mandare alla squadra, con i pulsanti Copia e WhatsApp. Chi lo apre crea l'account
  (`register.php?c=...`) o entra con quello che ha. **Modo di ingresso**: con approvazione (predefinito: la richiesta aspetta un admin della lega)
  oppure libero (si entra subito). «Nuovo link» invalida quello vecchio.
- **Richieste** da approvare o rifiutare, abbinando l'iscrizione a un giocatore già in rosa nella lega (stesso nome) come per le iscrizioni normali.
- **Membri e ruoli** dentro la lega: *Proprietario* (tutto, anche cedere o eliminare la lega), *Admin* (come il proprietario, ma non nomina
  altri admin e non cede/elimina), *Manager* (gestisce solo le partite). Si possono togliere membri (lo storico resta) e aggiungere giocatori
  senza account.
- **Impostazioni**: rinomina, cedi la lega a un altro membro, elimina (solo finché non ha partite).
- **Ultime operazioni** della lega.

Chi amministra una lega ha anche Pagamenti (solo per le sue partite), la gestione delle partite e le schede dei suoi giocatori (rating, attivo,
correzioni, leghe), ma **nessun potere sugli account** (username, password, email, ruoli del sito): quelli restano all'admin del sito.
Le notifiche e le email per le nuove richieste arrivano agli admin della lega, non all'admin del sito.

I membri di una lega vedono solo le proprie leghe, come per i gruppi. L'**admin del sito**, nelle pagine normali e in *Admin*, vede solo le
leghe «di casa» (quelle storiche, create da *Admin → Gruppi*, e quelle di cui fa parte), così le statistiche e gli account di sconosciuti non si
mescolano con i suoi; tutte le altre sono in *Piattaforma*.

Limiti anti-abuso (in `lib/leagues.php`): al massimo 3 leghe per account (`LEAGUE_MAX_PER_USER`), 5 al giorno per connessione
(`LEAGUE_MAX_PER_IP_DAY`), 200 richieste in attesa per lega; più i limiti dell'iscrizione normale (campo trappola, iscrizioni per IP).
Con `LEAGUE_CREATION = false` in `config.php` la creazione di nuove leghe si chiude.

## Piattaforma (solo admin del sito)

`platform.php` è la pagina personale dell'admin del sito, diversa da *Admin*:
- **Panoramica**: leghe, account, attivi negli ultimi 7 giorni, partite, scommesse, operazioni del giorno; quali operazioni fanno di più e le
  leghe più attive negli ultimi 30 giorni; le ultime leghe create.
- **Leghe**: tutte, con proprietario, data di creazione, giocatori, account, partite, operazioni e ultima attività. Il dettaglio di ognuna mostra
  le persone collegate (account, email, ruolo, iscrizione, ultimo accesso, operazioni) e le sue operazioni; da lì si apre «Gestisci»
  (`league.php`) con gli stessi poteri del proprietario, o si guarda la lega come un membro.
- **Account**: tutti, con leghe, email, creazione e ultimo accesso; dal dettaglio si reimposta la password o si elimina l'account.
- **Registro**: tutte le operazioni, filtrabili per lega, tipo e persona.

Il **registro delle operazioni** (tabella `activity_log`, funzione `log_activity()`) tiene un anno: accessi e uscite, iscrizioni e approvazioni,
creazione e gestione delle leghe (inviti, ruoli, membri), partite (creazione e ogni azione di gestione), presenze, voti, scommesse e multiple,
acquisti nel negozio, schede dei giocatori, pagamenti, modifiche di email/password e le azioni in *Admin*. L'ultimo accesso di ogni account è in
`users.last_seen_at` (aggiornato al massimo ogni 5 minuti).

**Statistiche in cache:** `compute_stats()` salva il risultato in `stats_cache` e lo riusa finché i dati non cambiano: ogni scrittura su partite,
presenze, voti, giocatori o leghe (intercettata in `q()`, `lib/db.php`) alza la versione in `meta.stats_ver` a fine richiesta. Così le pagine
non ricalcolano tutto lo storico a ogni visita, anche con anni di partite e tante leghe.

## Ospiti e giocatori liberi

Chi amministra una lega aggiunge un **ospite** a una partita in programma (scheda della partita → Presenze → *Aggiungi un ospite*): riceve utente e
password, vede la sua partita, in che squadra gioca e la formazione, e dice se ci sarà (`lib/guests.php`). Non è in nessuna lega, quindi resta fuori
da rosa, classifiche, statistiche, Fanta e scommesse.

- **Voti:** dopo la partita l'ospite vota e viene votato come gli altri, così riceve un **feedback** (media dei voti ricevuti, voti come MVP), che vede
  alla chiusura delle votazioni. Per la lega però i suoi voti, **quelli che dà e quelli che riceve** (medie, MVP, miglior difensore/portiere, punti Fanta,
  scommesse sull'MVP), contano solo se chi gestisce la lega li accetta: nella sezione *Voti* della partita c'è il riquadro «Voti degli ospiti» con
  «Fai contare» / «Non far contare». Finché non si decide non contano (`match_players.votes_ok`: NULL da decidere, 1 contano, 0 no; per i membri vale 1;
  filtro in `lib/stats.php: votes_ok_sql`). Se si cambia idea a votazioni chiuse, le scommesse sull'MVP si ripagano.
- **Account salvato:** l'ospite può tenere l'account (dalla sua partita o da *La tua area*, `guest.php`) scegliendo una password sua, perché quella di
  prima la conosceva chi l'ha invitato; gli altri dispositivi vengono scollegati. Da allora l'account non scade (`players.guest_saved`) e, finché non è
  in nessuna lega, è un **giocatore libero**. Può uscire dall'elenco quando vuole («Non voglio più essere chiamato»): l'account torna a scadere.
- **Giocatori liberi:** chi amministra una lega li vede (nome, ruoli, piede, partite da ospite, media dei voti ricevuti) nella scheda della partita
  (*Giocatori liberi*, tra gli ospiti) e in *La mia lega*. Può **chiamarne uno a una partita in programma** (entra tra le presenze come ospite e riceve
  una notifica) oppure **invitarlo nella lega** (al massimo `LEAGUE_INVITES_PER_DAY` inviti al giorno per lega, tabella `league_invites`). L'ospite trova
  gli inviti in *La tua area*: se accetta diventa un giocatore normale della lega, con le partite giocate da ospite che restano sue, ed esce dalle partite
  in programma delle altre leghe a cui era chiamato.
- **Scadenza:** l'account di chi non l'ha salvato sparisce `GUEST_KEEP_DAYS` giorni dopo la sua ultima partita. Togliere un ospite da una partita (o
  eliminare la partita) cancella l'account solo se non l'ha salvato e non gioca altre partite.

## Ruoli: admin, manager e giocatore

Il **manager** è un giocatore con qualche potere in più: da *Admin → Account* (o da *Modifica profilo → Ruolo*) l'admin può promuovere un account a manager.
Può **creare e gestire le partite dei suoi gruppi** (creazione, presenze di tutti, squadre, risultato, assist, apertura e chiusura
delle votazioni, modifica dei dati della partita), ma non può fare il resto dell'admin: niente pagina Admin, account e ruoli,
gruppi, nuovi giocatori o rating base, pagamenti, eliminazione di partite, e non vede i voti degli altri finché le votazioni sono aperte.
La regola sta in `can_manage_group()` in `lib/leagues.php` (partita per partita, con la sua lega). Gli stessi poteri li hanno i manager
nominati dentro una lega (vedi «Leghe create dagli utenti»).

## Come si usa

| Chi | Cosa fa |
|---|---|
| **Admin** | crea partite, modifica presenze, genera le squadre, inserisce risultato/gol/assist/autogol, conclude la partita, chiude le votazioni, gestisce pagamenti, giocatori, account, gruppi e statistiche |
| **Manager** | crea e gestisce le partite dei suoi gruppi (presenze, squadre, risultato, votazioni); il resto come un giocatore |
| **Giocatore** | conferma o disdice la presenza, vede partite e statistiche, dopo la partita vota tutti gli altri (1-10), l'MVP, il miglior difensore e (con i portieri fissi) il miglior portiere, modifica il proprio profilo (foto, numero, ruolo, piede, password), scrive le sue curiosità, attiva le notifiche |

Ciclo di una partita (dove si legge «admin» vale anche per il manager, per le sue partite): l'admin crea la partita → i giocatori confermano → l'admin genera le
squadre bilanciate → a fine partita inserisce il risultato → i giocatori votano → l'admin chiude
le votazioni e voti/MVP entrano nelle statistiche.

**Miglior difensore e miglior portiere:** insieme all'MVP si vota il miglior difensore e, se la partita è con i **portieri fissi**, il miglior portiere
(`MATCH_AWARDS` in `lib/stats.php`, tabella `award_votes`). Il portiere si sceglie tra chi gioca in porta nel modulo della sua squadra; il difensore tra
tutti gli altri (mai se stessi). Vince chi ha più voti (a parità la media voto più alta). Come l'MVP, i vincitori si vedono alla chiusura delle votazioni,
con un'etichetta accanto al nome e una colonna con i voti nella tabella della partita.

**Partita annullata:** per una partita saltata o interrotta (pioggia, campo chiuso, troppi assenti...) chi la gestisce (admin o manager della lega) apre
«Gestione partita → Partita annullata», scrive un motivo facoltativo e conferma; può anche azzerare la quota. La partita si chiude con lo stato `annullata`
e **non conta** per classifiche, statistiche, voti e MVP, Fanta e premi in KOIN per gol e assist; tutte le scommesse sulla partita (anche dentro le multiple)
vengono rimborsate. I dati **restano salvati** (presenze, squadre, gol già segnati, ospiti, pagamenti) e si vedono nella scheda, in sola lettura; in «Partite»
compare tra le «Annullate» con il motivo. Chi era in lista riceve la notifica. «Riporta a programmata» la riapre: le scommesse tornano in gioco.
Codice: `match_cancel()` in `lib/stats.php`, `bets_void_match()` in `lib/bets.php`.

**Diretta (cronaca della partita):** dal calcio d'inizio, nella scheda della partita compare la sezione «Diretta» (`lib/live.php`, tabella `match_events`). Chi gioca
(fino a 4 ore dall'inizio) e chi gestisce le partite segna i **gol** (con l'assist facoltativo, solo tra compagni) e gli **autogol**: ogni gol aggiorna subito il risultato,
i gol e gli assist della tabella «Risultato e marcatori» e l'intesa «chi ha servito chi», e manda la notifica a chi non gioca. Si segnano anche gli **infortuni**
(con una nota facoltativa: «caviglia», «stiramento»...): il giocatore compare con la crocetta viola nella squadra. Un evento segnato per sbaglio si toglie con la ×
(chi l'ha segnato o chi gestisce la partita) e i conti tornano indietro. A fine partita il risultato si controlla e si conclude come prima; gli infortuni si possono
aggiungere anche dopo, da chi gestisce la partita. Gli infortuni della partita sono sempre in **viola**: nel riepilogo di «Partite» una riga sotto la partita
(«Infortunato: Luca Verdi (caviglia)», `injuries_by_match`), e nel profilo del giocatore una sezione «Infortuni» con le partite e le note (`player_injuries`), la
crocetta nella tabella delle sue partite e, se si è fatto male nella sua ultima partita, l'etichetta «infortunato nell'ultima partita» in alto. È diverso dalla
casella **Infortunato** della scheda (etichetta rossa, vedi *Infortunati*), che lo segna assente nelle prossime partite.

**Fine votazioni e conto alla rovescia:** quando la partita viene conclusa (o le votazioni riaperte) si fissa un orario di fine: `VOTING_HOURS` in
`config.php` (24 ore). Nella scheda «Voti» si vede l'orario con il conto alla rovescia, e chi gestisce la partita lo cambia (o toglie la scadenza) da lì.
Arrivato l'orario le votazioni si chiudono da sole, con i voti d'ufficio e la notifica di chiusura: succede alla prima pagina aperta da qualcuno
dopo l'orario, o dal cron (`cron.php`) se c'è. Nella Home e nella partita, quando mancano meno di 24 ore alla partita, compare il conto alla rovescia
(«Mancano 3h 31min», poi minuti e secondi nell'ultima ora).

**Voti:** dopo l'invio compare un grande segno di spunta al centro dello schermo («Voti inviati!»; sparisce da solo o con un tocco). Si possono cambiare
quanti se ne vuole finché le votazioni sono aperte. Alla chiusura, per chi ha giocato e non ha votato il voto d'ufficio è **6** (`DEFAULT_VOTE` in `config.php`)
per ogni altro giocatore della partita (mai per se stesso; nessun MVP viene inventato). I voti d'ufficio hanno un contrassegno nel database
(`ratings.is_auto`): se le votazioni vengono riaperte spariscono e si rifanno alla chiusura successiva, e chi vota dopo sostituisce i suoi.

## Test in locale

Servono PHP 8.0+ con `pdo_mysql` e `gd`, e un MySQL/MariaDB. Le credenziali si passano con
variabili d'ambiente (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`) oppure con `config.local.php`;
per `install.php` serve comunque `INSTALL_KEY` in `config.local.php`.

```bash
cd calcetto
php -S localhost:8088
```
