# Piano: da PHP a Next.js

Documento di pianificazione: **non cambia nulla nel sito**. Descrive come portare Calcetto Manager dalla versione attuale
(PHP + MySQL su Altervista) a una versione in Next.js, in modo graduale e senza perdere dati, account, notifiche e KOIN.
In fondo ci sono vantaggi e svantaggi, per decidere se e quando farlo.

---

## 1. Da dove si parte

| | Oggi |
|---|---|
| Linguaggio | PHP 8 senza framework: 41 pagine (`calcetto/*.php`, ~8.300 righe) e 34 librerie (`calcetto/lib/*.php`, ~16.000 righe, ~620 funzioni) |
| Interfaccia | HTML generato dal server, `assets/style.css` (~1.750 righe), JavaScript a mano (`app.js`, `bets.js`, `push.js` e altri, ~1.600 righe) |
| Database | MySQL/MariaDB, 40 tabelle; lo schema lo aggiorna `ensure_schema()` in `lib/db.php` (migrazioni 1 → 59) |
| Hosting | Spazio WordPress di **Altervista**: solo PHP, database in comune con WordPress, cache Varnish davanti che toglie i cookie (da qui il nome `wordpress_logged_in_…` del cookie di sessione) |
| Deploy | GitHub Actions → FTP su Altervista (`.github/workflows/deploy.yml`) |
| Lavori a orario | `cron.php` chiamato da un servizio esterno ogni 10–15 minuti, oppure "al volo" mentre qualcuno usa il sito (`push_maybe_run`) |
| Notifiche | Web Push scritto a mano (`lib/webpush.php`: chiavi VAPID in `meta`, cifratura, coda con riprove), service worker `sw.js` |
| Email | `mail()` di PHP (finisce spesso nello spam) |
| File | foto e sfondi in `uploads/players/` (ridimensionati con GD in `lib/helpers.php`) |

Le parti con più logica, quelle da portare con più attenzione:

- **Scommesse ed economia KOIN**: `lib/bets.php` (quote con Poisson, margini, domanda, over/under, multiple, pagamenti), `lib/passaggi.php`, `lib/shop.php` (prezzi dinamici), economie per lega.
- **Statistiche e squadre**: `lib/stats.php` (rating, forma, classifiche), `lib/balance.php` (bilanciamento con ricerca esaustiva), `lib/chemistry.php` (intesa), `lib/formation.php`.
- **Fanta** (`lib/fanta.php`), **Avatar pixel** (`lib/avatar_pixel*.php`, ~3.100 righe), **Gazzetta** (`lib/gazzetta.php`).
- **Sicurezza**: sessioni legate al browser, "resta collegato" (`auth_tokens`), `session_version`, verifica in due passaggi (TOTP), limiti ai tentativi, CSRF.

## 2. La decisione da prendere prima di tutto: dove gira

Next.js ha bisogno di **Node.js**. Altervista offre solo PHP, quindi **bisogna cambiare hosting**. Il database di Altervista non è
raggiungibile da fuori, quindi va spostato anche quello.

| Opzione | Come | Pro | Contro |
|---|---|---|---|
| **A. VPS con Docker** (consigliata) | Un server piccolo (es. Hetzner/Contabo, ~4–6 €/mese): Next.js + MariaDB + Caddy (HTTPS automatico) con Docker Compose | Tutto sotto controllo; cron veri (ogni 5 minuti); foto su disco; PHP e Next possono convivere sullo stesso server durante il passaggio | Il server va mantenuto: aggiornamenti, backup, monitoraggio |
| B. Vercel + database gestito | Next.js su Vercel, MySQL esterno (Aiven, TiDB, Railway…), foto su Vercel Blob/S3 | Zero server da curare, deploy automatici, anteprime per ogni modifica | Sul piano gratuito i cron girano **una volta al giorno** (servono promemoria e scommesse ogni pochi minuti: piano a pagamento, ~20 $/mese); funzioni serverless con limiti di durata (il bilanciamento con 22 giocatori può durare secondi); più servizi da tenere insieme |
| C. Ibrido | Next.js solo come interfaccia (export statico) e PHP come API su Altervista | Si resta su Altervista | Si perde quasi tutto il bello di Next (rendering lato server, server actions); due codici da mantenere per sempre |

**Proposta: A.** È la più economica, non ha limiti sui lavori a orario e permette il passaggio graduale descritto sotto
(vecchio e nuovo sito sullo stesso server, stesso database).

## 3. Lo stack di arrivo

| Pezzo | Scelta | Perché |
|---|---|---|
| Framework | **Next.js (App Router) + TypeScript** | Pagine renderizzate sul server come oggi, ma con componenti riusabili e tipi |
| Database | **MariaDB, stesso schema di oggi** | Niente conversione dei dati: si copia il database e basta |
| Accesso ai dati | **Drizzle ORM** (schema letto dal database esistente con `drizzle-kit pull`) | Query tipizzate, SQL vicino a quello di oggi, migrazioni in file |
| Azioni | **Server Actions** al posto dei `POST` delle pagine | CSRF e form gestiti dal framework; meno codice |
| Login | **Implementazione propria, uguale a quella di oggi** (non NextAuth) | Stesso comportamento: sessione in database, "resta collegato" con `auth_tokens`, `session_version`, TOTP. Le password restano valide: `password_hash` di PHP produce bcrypt con prefisso `$2y$`, equivalente a `$2b$`: le librerie bcrypt di Node lo verificano (al limite cambiando il prefisso al momento del controllo) |
| Notifiche | Libreria **`web-push`**, con **le stesse chiavi VAPID** di `meta` | Gli abbonamenti già fatti continuano a funzionare: nessuno deve riattivare le notifiche |
| Lavori a orario | Script Node lanciato da **cron** sul server (o un worker sempre acceso) | Sostituisce `cron.php` e `push_maybe_run` |
| Email | **Nodemailer** con un SMTP vero (es. Brevo, gratis fino a 300 email/giorno) | Le email non finiscono più nello spam |
| Immagini | **sharp** al posto di GD | Ridimensionamento foto e sfondi più veloce |
| Stile | **`style.css` così com'è** come CSS globale, poi a pezzi in CSS Modules | Il sito resta identico; niente riscrittura grafica |
| Test | **Vitest** per la logica, **Playwright** per i percorsi completi | Vedi il punto 5 |

## 4. Strategia: passaggio graduale, non tutto in una volta

Riscrivere tutto e accendere il nuovo sito in un giorno ("big bang") è rischioso: 24.000 righe con molte regole sottili
(quote, premi, intesa) che si sbagliano facilmente. Si fa invece **pagina per pagina**, con vecchio e nuovo sito che convivono:

1. Prima si sposta **il sito PHP così com'è** sul nuovo server (Docker), con lo stesso database. Nessun cambiamento per gli utenti.
2. Davanti c'è Caddy, che smista gli indirizzi: all'inizio va tutto a PHP; man mano che una pagina è pronta in Next.js,
   il suo indirizzo (es. `/classifica`) passa a Next. Gli indirizzi "belli" di oggi (`/partita-12`, `/scommesse`…) restano gli stessi.
3. **Sessione condivisa**: oggi le sessioni PHP stanno in file. Come primo passo PHP le salva in una tabella (`sessions`, con un
   gestore di sessione su misura), così anche Next.js sa chi è collegato. L'utente passa da una pagina PHP a una Next senza accorgersene.
4. **Un solo padrone dello schema**: finché PHP è in funzione, le modifiche al database le fa solo `ensure_schema()`; Drizzle
   rilegge lo schema dopo ogni modifica. Al passaggio finale le migrazioni passano a Drizzle (partendo dalla versione 59 o successiva).
5. **Lavori a orario**: restano a PHP (`cron.php`) finché non sono tutti riscritti, poi passano in blocco a Node. Mai tutti e due
   insieme, altrimenti partono notifiche o pagamenti doppi.

## 5. Come si evita di sbagliare la logica: i test "di riferimento"

Prima di riscrivere un modulo si fotografa cosa fa oggi:

- Da una copia del database (anonimizzata) uno script PHP salva in JSON i risultati delle funzioni chiave: `bet_quotes` per ogni
  partita, `balance_teams` su pool fissi, `compute_stats`, `chemistry`, `shop_price`, `gazzetta`, `match_highlights`, i pagamenti
  di `bets_settle`.
- La versione TypeScript deve dare **gli stessi numeri** sugli stessi dati (test Vitest). Attenzione alle differenze tra i linguaggi:
  `round()` di PHP arrotonda il .5 lontano dallo zero, `Math.round` di JavaScript verso +∞ (diverso sui negativi); `intdiv`,
  `floor` sui float; i `DECIMAL` di MySQL arrivano a Node come stringhe; date e fuso orario (`Europe/Rome`).
- I percorsi principali (accesso, conferma presenza, puntata, risultato, voto, passaggio di KOIN) hanno un test Playwright che
  gira sia sul sito PHP sia su quello Next: stessi passi, stesso risultato.

## 6. Le fasi

Le stime sono in **giornate di lavoro** concentrate (con un assistente AI che scrive gran parte del codice), da prendere come
ordine di grandezza. Ogni fase si chiude solo quando il suo criterio di uscita è verificato.

| Fase | Cosa | Stima | Si chiude quando |
|---|---|---|---|
| **0. Preparazione** | Inventario di pagine, azioni e lavori a orario; script dei test di riferimento (punto 5); copia anonimizzata del database; si decide se fermare le funzioni nuove durante la migrazione | 2–3 | I JSON di riferimento esistono e si rigenerano con un comando |
| **1. Nuovo server** | VPS, Docker Compose (PHP-FPM + MariaDB + Caddy), backup automatici giornalieri del database, dominio e HTTPS; si copiano database e `uploads/` da Altervista; deploy da GitHub Actions via SSH | 3 | Il sito PHP gira identico sul nuovo server; Altervista resta spento ma intatto per qualche settimana |
| **2. Fondamenta Next.js** | Progetto Next + TypeScript, Drizzle sul database esistente, sessioni condivise con PHP (punto 4.3), layout e menu, `style.css`, leghe ed economie (`scope_ids`, `eco_of_group`), helper di date e numeri | 5 | Una pagina vuota di Next mostra menu, KOIN e lega dell'utente collegato da PHP |
| **3. Pagine in sola lettura** | Home, Partite, Rosa, Giocatore, Classifica, Curiosità, Gazzetta, Tabellino | 6 | Ogni pagina servita da Next, con screenshot uguali a quelli PHP |
| **4. Partite** | Presenze, creazione e modifica partite, squadre (bilanciamento, formazioni, scambi), risultato e cronaca in diretta, voti e MVP, pagamenti, pensieri sulla partita | 8 | I test di riferimento di `balance_teams`, `compute_stats` e premi passano; i test Playwright delle partite passano |
| **5. Economia** | Scommesse (quote, schedina, multiple, pagamenti, rimborsi), portafoglio, passaggi di KOIN, Negozio con prezzi dinamici, Uscite, Avatar pixel, maglie, Fanta, Indovina | 12 | Le quote coincidono al centesimo con PHP; i saldi di tutti i giocatori dopo una partita simulata sono identici |
| **6. Notifiche, email, lavori a orario** | Coda push con le stesse chiavi VAPID, tutte le notifiche, promemoria, chiusura voti, Fanta, uscite, Gazzetta del mercoledì; email con SMTP; il cron passa da PHP a Node | 5 | Su un dispositivo di prova arrivano tutte le notifiche una volta sola |
| **7. Account e amministrazione** | Accesso, iscrizione e approvazione, recupero password, email, 2FA, ospiti, leghe e inviti, Admin, Piattaforma, tutorial | 6 | Login con le password di oggi; 2FA con le chiavi di oggi; nessuno deve rifare l'accesso |
| **8. Spegnimento di PHP** | Ultimi indirizzi a Next, migrazioni a Drizzle, via PHP-FPM dal server, pulizia del repository (PHP in un ramo d'archivio) | 3–4 | Una settimana senza errori con solo Next.js acceso |
| | **Totale** | **~50–55 giornate** | |

Ordine pensato per avere valore presto: dopo la fase 3 metà delle visite passa già dal nuovo sito, e ogni fase si può
fermare senza lasciare nulla a metà (le pagine non ancora pronte restano in PHP).

## 7. Da PHP a Next.js, modulo per modulo

| Oggi (PHP) | Domani (Next.js) |
|---|---|
| `calcetto/*.php` (una pagina = un file con HTML e `POST`) | `app/<percorso>/page.tsx` (lettura) + `actions.ts` (Server Actions per i form) |
| `lib/layout.php` (`layout_start` / `layout_end`) | `app/layout.tsx` + componenti `Menu`, `Flash`, `Sovraimpressioni` |
| `lib/db.php` (`q()`, `ensure_schema`) | `lib/db.ts` (Drizzle) + cartella `drizzle/` con le migrazioni |
| `lib/auth.php`, `lib/security.php` | `lib/auth.ts`, `middleware.ts` (protezione pagine, intestazioni di sicurezza) |
| `lib/bets.php`, `lib/passaggi.php`, `lib/shop.php` | `lib/economy/*.ts` (funzioni pure, testate con i JSON di riferimento) |
| `lib/stats.php`, `lib/balance.php`, `lib/chemistry.php`, `lib/formation.php` | `lib/football/*.ts` (il bilanciamento in un worker se serve) |
| `lib/webpush.php`, `cron.php`, `push.php`, `sw.js` | `lib/push.ts` (`web-push`), `jobs/*.ts` lanciati dal cron, `app/api/push/route.ts`, `public/sw.js` |
| `assets/app.js`, `bets.js`, `push.js` | Componenti client (`'use client'`): slider, schedina, selettore over/under, editor foto |
| `assets/style.css` | `app/globals.css` (identico), poi CSS Modules dove serve |
| `uploads/players/` | Volume Docker montato in `public/uploads/` (o servito da Caddy) |
| `.htaccess` (indirizzi belli) | Cartelle di `app/` con gli stessi nomi (`/partita/[id]`…) + `rewrites` in `next.config` per i vecchi `.php` |

## 8. Rischi e come si gestiscono

| Rischio | Contromisura |
|---|---|
| Numeri diversi in quote, premi, classifiche | Test di riferimento (punto 5) prima di accendere ogni pagina |
| Notifiche perse o doppie | Stesse chiavi VAPID; un solo sistema di cron alla volta; coda con chiave unica come oggi |
| Tutti devono rifare l'accesso | Sessioni e "resta collegato" letti dalle stesse tabelle; hash delle password compatibili |
| Dati persi nel trasloco da Altervista | Copia completa (database + `uploads/`), controllo dei conteggi riga per riga, Altervista lasciato intatto fino alla fine |
| Server da mantenere | Aggiornamenti automatici di sicurezza, backup giornalieri fuori dal server, un monitor gratuito (es. UptimeRobot) |
| Migrazione lunga, sito fermo | Il sito non si ferma mai: le pagine passano una alla volta; le funzioni nuove si possono continuare a fare in PHP fino alla fase 3, poi solo in Next |
| Costi | VPS ~5 €/mese, dominio ~10 €/anno, SMTP gratuito; oggi Altervista è gratis |

## 9. Vantaggi e svantaggi

### Vantaggi

- **TypeScript**: gli errori di tipo (una chiave sbagliata, un `null` dimenticato) si vedono mentre si scrive, non quando un utente apre la pagina.
- **Componenti riusabili**: card, pedine, slider, schedina, avatar diventano componenti; oggi lo stesso HTML è copiato in più pagine.
- **Interfaccia più viva**: aggiornamenti senza ricaricare la pagina (presenze, quote, cronaca in diretta) senza scrivere JavaScript a mano.
- **Niente più trucchi per Altervista**: cookie con nomi finti per la cache Varnish, `mail()` che finisce nello spam, cron "al volo" mentre qualcuno usa il sito.
- **Lavori a orario puntuali**: promemoria, apertura scommesse e Gazzetta escono all'ora giusta anche se nessuno apre il sito.
- **Email affidabili** con un SMTP vero.
- **Test automatici** sulla logica più delicata (quote, premi, bilanciamento), oggi assenti.
- **Ecosistema**: librerie mature per push, immagini, grafici, date; più facile trovare aiuto e collaboratori.
- **Prestazioni**: cache di Next.js, immagini ottimizzate, pagine che caricano solo il JavaScript che serve.

### Svantaggi

- **Tanto lavoro**: ~50 giornate per arrivare dove si è oggi, senza funzioni nuove per gli utenti finché non è finita
  (o con funzioni nuove fatte due volte durante il passaggio).
- **Hosting non più gratis**: Altervista costa 0; un VPS ~5 €/mese, Vercel con cron frequenti ~20 $/mese.
- **Un server da curare** (con l'opzione A): aggiornamenti, backup, certificati, spazio su disco. Oggi ci pensa Altervista.
- **Rischio di regressioni** in una logica fatta di tanti dettagli accumulati (premi una tantum, regole sulle economie, eccezioni sugli ospiti).
- **Più complessità**: build, Node, Docker, ORM, differenza tra componenti server e client. Il PHP di oggi si carica via FTP e funziona.
- **Due versioni da tenere allineate** durante la migrazione (settimane o mesi).
- **Next.js cambia spesso** (App Router, caching, Server Actions sono cambiati molto tra una versione e l'altra): servono aggiornamenti periodici.

### L'alternativa: restare in PHP e migliorarlo

Se l'obiettivo è soprattutto avere meno errori e codice più ordinato, si può ottenere molto restando in PHP: tipi rigorosi
(`declare(strict_types=1)` e analisi statica con PHPStan), test con PHPUnit sulla logica di scommesse e statistiche, un vero SMTP
via PHPMailer, e il trasloco su un VPS (fase 1 di questo piano) per avere cron puntuali. Costa una frazione del lavoro.
**Next.js conviene se il sito deve crescere ancora molto** (più leghe, più utenti, interfaccia più interattiva, magari un'app).

## 10. Decisioni da prendere prima di partire

1. Si migra davvero, o prima si fa solo il trasloco su VPS (fase 1) e si decide dopo?
2. Hosting: VPS (A) o Vercel (B)? Budget mensile accettabile?
3. Durante la migrazione si fermano le funzioni nuove, o si accetta di farle due volte?
4. Dominio: si resta su `*.altervista.org` (con un reindirizzamento) o se ne prende uno proprio?
