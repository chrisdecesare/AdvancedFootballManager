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
gruppi (chi si iscrive non vede i gruppi: li assegna l'admin quando approva l'iscrizione). Ogni partita appartiene a un gruppo.
Un giocatore vede e partecipa solo a giocatori e partite dei suoi gruppi (le altre, anche aprendo il link,
danno «non trovato»); chi è in più gruppi li vede tutti e può filtrare con i pulsanti in alto; l'admin vede
tutto. Statistiche, classifica, pagamenti e intesa sono calcolati dentro al gruppo. Il gruppo iniziale si
chiama «Principale» e si rinomina (es. in YBQ).

**Foto e sfondo del profilo:** in «Modifica profilo» chi sceglie una foto vede subito una finestra di ritaglio: trascina
e ingrandisce (cursore, rotella o pizzico con due dita) per decidere quale parte tenere, e viene caricata solo quella (400x400).
Lo sfondo della scheda del profilo (e della carta nella Rosa) può essere automatico (colore del ruolo), un colore a scelta oppure un'immagine, ritagliata
allo stesso modo (1000x400). Se il browser non riesce a leggere il file, lo ritaglia il server (foto: parte alta, sfondo: centro).

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

La scheda **Scommesse** (`bets.php`, logica in `lib/bets.php`) fa puntare sulle partite in programma con **gettoni finti**: nessun euro in gioco.
Ognuno parte con 100 gettoni. Per ogni partita si può fare una puntata per mercato:

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
  (un portiere arriva a ×50);
- **Chi sarà l'MVP?** pesano i premi MVP, la media voto (stagione e ultime partite), la forma, i gol attesi e la probabilità che la sua squadra vinca; le probabilità si normalizzano a 100% prima del margine.

Chi non ha ancora confermato la presenza vale meno (potrebbe non esserci). Le costanti (margini, peso della forma, correzione dei pareggi) sono in cima a `lib/bets.php`.
Se manca il dato (nessuno ha votato l'MVP) le puntate sono rimborsate. Si punta fino al calcio d'inizio e fino ad allora si può cambiare o ritirare la puntata. Chi resta al verde (meno di 20 gettoni e nulla in gioco)
riceve un sussidio di 30 gettoni a settimana. Ci sono titoli goliardici in base ai gettoni e la classifica dei più ricchi.

Il portafoglio non è un numero salvato ma la somma delle mosse (`wallet_moves`), quindi correggere un risultato, riaprire una partita o riaprire le votazioni
rifà i pagamenti da solo, e cancellare una partita restituisce i gettoni. Le costanti (gettoni iniziali, soglia e importo del sussidio, margine) sono in cima a `lib/bets.php`.

**Negozio** (`shop.php`, logica in `lib/shop.php`, catalogo in `lib/shop_items.php`): i gettoni si spendono per personalizzare il profilo, e le personalizzazioni si vedono sul profilo e nella Rosa.
Quattro schede, con filtro «che posso comprare / miei» e un pulsante **Prova** che mette l'oggetto addosso all'anteprima fissa in alto, prima di comprarlo:

- **Copricapi (100)**, in diagonale su un angolo del riquadro: cappellini, berretti, cowboy, cuoco, mago, pirata, vichingo, corone, elmetti, orecchie da gatto, gelato, zucca, coppa... Sono disegni SVG in stile
  fumetto costruiti da 52 forme (`lib/hats.php`) con colori diversi: per un cappello nuovo basta una riga nel catalogo;
- **Bordi (30)**: un anello attorno al riquadro, in tinta unita, a gradiente, a motivi (cantiere, scacchi, greca), con alone al neon o animato (arcobaleno in movimento, scarica elettrica, oro pulsante; le
  animazioni si fermano a chi ha attivo «riduci movimento»);
- **Sfondi (50)** al posto delle strisce del ruolo (prato, aurora boreale, tigre, marmo, fibra di carbonio, rubino...); sostituiscono il colore o l'immagine scelti da *Modifica profilo* (e viceversa);
- **Nickname (50)** sotto il nome: 17 si comprano, 33 **si sbloccano da soli** con un obiettivo: gol (5, 10, 25, 50; 3 o 4 in una partita), assist (3, 10, 25, 50), gol + assist, premi MVP (1, 3, 5, 10, 20),
  presenze (1, 15, 30, 60), vittorie (10, 25, 5 di fila), media voto (7,5 o 8), autogol, sconfitte, scommesse vinte, gettoni e oggetti comprati.

Prezzi e obiettivi si cambiano in `lib/shop_items.php` (una riga per oggetto); il disegno di sfondi e bordi sta in `assets/style.css` (classi `bgp-<chiave>` e `brd-<chiave>`). Gli acquisti stanno in `player_items`,
cosa si indossa adesso nelle colonne `bg_preset`, `border_key`, `nick_key`, `hat_key` di `players`.

**Overall:** dove prima si vedeva il rating (1-10, che sembrava un voto) ora si vede l'**overall** in stile videogioco, da 1 a 99 (rating × 10): in cima al profilo, sulla carta nella Rosa, in Classifica e nelle formazioni.
Si calcola come prima (rating base deciso dall'admin, media voto e percentuale di vittorie) ed è quello che serve a bilanciare le squadre.

**Campo:** il nome del campo (in Home e nella partita) è un link: apre l'itinerario di Google Maps fino al campo partendo dalla posizione attuale di chi clicca.
Conviene scrivere nel campo «Campo» nome e indirizzo (es. «Centro sportivo Rossi, Via Roma 1, Milano»).

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

## Ruoli: admin, manager e giocatore

Il **manager** è un giocatore con qualche potere in più: da *Admin → Account* (o da *Modifica profilo → Ruolo*) l'admin può promuovere un account a manager.
Può **creare e gestire le partite dei suoi gruppi** (creazione, presenze di tutti, squadre, risultato, assist, apertura e chiusura
delle votazioni, modifica dei dati della partita), ma non può fare il resto dell'admin: niente pagina Admin, account e ruoli,
gruppi, nuovi giocatori o rating base, pagamenti, eliminazione di partite, e non vede i voti degli altri finché le votazioni sono aperte.
La regola sta in `can_manage_matches()` in `lib/auth.php`.

## Come si usa

| Chi | Cosa fa |
|---|---|
| **Admin** | crea partite, modifica presenze, genera le squadre, inserisce risultato/gol/assist/autogol, conclude la partita, chiude le votazioni, gestisce pagamenti, giocatori, account, gruppi e statistiche |
| **Manager** | crea e gestisce le partite dei suoi gruppi (presenze, squadre, risultato, votazioni); il resto come un giocatore |
| **Giocatore** | conferma o disdice la presenza, vede partite e statistiche, dopo la partita vota tutti gli altri (1-10) e l'MVP, modifica il proprio profilo (foto, numero, ruolo, piede, password), scrive le sue curiosità, attiva le notifiche |

Ciclo di una partita (dove si legge «admin» vale anche per il manager, per le sue partite): l'admin crea la partita → i giocatori confermano → l'admin genera le
squadre bilanciate → a fine partita inserisce il risultato → i giocatori votano → l'admin chiude
le votazioni e voti/MVP entrano nelle statistiche.

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
