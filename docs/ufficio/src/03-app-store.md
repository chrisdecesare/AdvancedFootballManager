---
title: 03 · Pubblicare l'app su Google Play e App Store
subtitle: Percorsi, costi, regole di revisione e checklist
toc: yes
---

> **Come leggere questo documento.** Le cifre e le regole hanno tra parentesi quadre il codice della fonte (elenco completo nel documento 07, consultate il **17 ottobre 2026**). Dove una fonte non si è potuta aprire direttamente, ma solo leggere in un estratto di ricerca, è scritto «estratto». Dove non si è trovato nulla, è scritto **NON VERIFICATO**: nessuna cifra è stata inventata.

## 1. In sintesi

- Con il PHP di oggi si può già arrivare su entrambi gli store. **Next.js non è necessario** per le app.
- **Costo verificato del primo anno: 124 USD** (Apple 99 USD/anno [AP1] + Google 25 USD una tantum [GP1]), più un Mac per iPhone (prezzo non verificato) e un dominio proprio.
- **Collo di bottiglia su Android**: un account Google Play personale nuovo deve fare un test chiuso con **almeno 12 tester per 14 giorni consecutivi** prima di andare in produzione [GP2, estratto].
- **Rischio principale su iPhone**: Apple rifiuta le app che sono «un sito riconfezionato» (regola 4.2) [AP3]. Servono funzioni davvero native (notifiche push, condivisione) e un account di prova per il revisore.
- **Le «scommesse»** sono con KOIN finti: non sono gioco d'azzardo con soldi veri, ma vanno dichiarate e classificate per età; se un giorno i KOIN si comprassero con denaro, cambia tutto (paragrafo 6).
- **Obblighi in app**: cancellazione dell'account dentro l'app, segnalazione e blocco dei contenuti degli utenti, informativa privacy, dichiarazioni sui dati (paragrafi 4 e 5).

**Raccomandazione:** Capacitor per Android e iOS, con notifiche push native; in alternativa, per partire subito solo su Android, una TWA (Trusted Web Activity).

## 2. Account sviluppatore

| | Apple | Google Play |
|---|---|---|
| Costo | **99 USD/anno** (esenzioni solo per enti no-profit, scuole accreditate, enti pubblici) [AP1] | **25 USD una tantum**, carta non prepagata, età minima 18 anni [GP1, estratto] |
| Come privato | Apple Account con verifica in due passaggi e maggiore età. **Non servono D-U-N-S, società né sito** [AP2] | Verifica identità: nome legale e indirizzo dal profilo pagamenti Google, conferma di email e telefono; se serve, documento d'identità [estratto] |
| Come organizzazione | Entità legale, **D-U-N-S**, sito pubblico, email di dominio [AP2]. Tempo di verifica non dichiarato | Serve D-U-N-S e sito [estratto] |
| Tempo | Conferma d'acquisto per i privati entro 24 ore [AP2] | Dipende dalla verifica; poi il test chiuso (sotto) |
| Dispositivo | Mac con Xcode 26 o superiore per compilare l'app iOS [AP9, CP1] | Un **telefono Android fisico** (Android 10+, non rootato) per la verifica con l'app Play Console [GP3, estratto] |
| Test obbligatorio | – | Account personale creato dopo il 13/11/2023: **test chiuso con almeno 12 tester per 14 giorni**; solo dopo si può chiedere la produzione, con revisione di solito entro 7 giorni [GP2, estratto] |

Altre note:

- **Dichiarazione DSA di «trader»** su Apple per chi distribuisce nell'Unione Europea: va dichiarato se si agisce come professionista [AP8]. Se non lo sei, i consumatori UE vengono avvisati che i diritti dei consumatori non si applicano.
- **Persona o società?** Apple dice che le app in settori regolamentati (tra cui il gioco d'azzardo) devono essere inviate dall'ente che offre il servizio, non da un privato [AP3, 5.1.1(ix)]. La norma pensa al gioco con soldi veri; con una schermata di «scommesse» il revisore potrebbe porsi la domanda. Si attenua spiegando nelle note di revisione che i KOIN sono finti e non convertibili. *Questa è una nostra inferenza, non una regola scritta.*
- Se l'app sarà pubblicata a nome di un'**azienda** (consigliato per la pubblicità e i ricavi), serve il D-U-N-S: il tempo per ottenerlo non è stato verificato.
- Google ha introdotto la registrazione dei pacchetti Android; scadenza 30/09/2026 [GP14, estratto]: controllare la Home di Play Console al momento della pubblicazione.

## 3. Percorsi tecnici

| Percorso | Cos'è | Pro | Contro |
|---|---|---|---|
| **A. TWA** (Bubblewrap / PWABuilder) | App Android che apre la PWA a schermo intero | Rapida; riusa il sito; Bubblewrap richiede Node 18+ [TW1] | Solo Android. Serve `/.well-known/assetlinks.json` sul dominio con risposta 200 [TW2]: **su Altervista non verificato**, con un dominio proprio sì. Notifiche push web invece che native |
| **B. Capacitor** (consigliato) | Involucro nativo Android e iOS con gli asset dentro l'app e chiamate al sito | Un solo progetto per i due store; plugin ufficiali per le notifiche push [CP2]; valore «app» reale (regola 4.2) | Mac e Xcode 26 per iOS [CP1]; più lavoro; la funzione `server.url` della documentazione è presentata per il live reload: non è chiaro se sia ammessa in produzione (**NON VERIFICATO**) |
| **C. PWABuilder iOS** | Progetto Xcode con WKWebView | Veloce | Definito sperimentale [estratto]; molto esposto alla regola 4.2 |
| **D. Nativo** (Swift / Kotlin / Flutter / React Native) | Riscrittura | Massimo controllo | Costo e tempi molto più alti; non serve per questo progetto |

**Cookie e sessione.** Se il sito e il dominio usati dall'app sono gli stessi (dominio proprio), i problemi dei cookie di terze parti su iOS non si pongono [estratto]. Con un sito su `*.altervista.org` e un'app che lo chiama, il comportamento dei cookie di sessione **non è stato verificato**: un altro motivo per passare a un dominio proprio (documento 02).

**Politiche sulle app che sono «solo un sito»**

- **Apple 4.2:** «Your app should include features, content, and UI that elevate it beyond a repackaged website» [AP3, testo letterale]. La 4.2.6 respinge le app da template se non inviate dal titolare dei contenuti: se l'app è inviata da te con i tuoi contenuti non ti riguarda (nostra lettura).
- **Google:** vieta le app il cui scopo principale è una WebView di un sito fatta senza il permesso del proprietario [GP11, estratto]. Con un sito tuo il divieto non scatta.

## 4. Regole Apple da rispettare (linee guida aggiornate al 16/10/2026) [AP3]

| Tema | Cosa chiede | Per Calcetto Manager |
|---|---|---|
| **4.2** Funzioni minime | Più di «un sito riconfezionato» | Notifiche push native, condivisione, icona e splash, accesso rapido; descrivere le funzioni nelle note di revisione |
| **1.2** Contenuti degli utenti | Filtro dei contenuti inadatti, **segnalazione** con risposta tempestiva, **blocco** degli utenti abusivi, contatti pubblici | Servono per foto profilo, idee, pensieri sulla partita, nomi delle leghe |
| **5.1.1(v)** Cancellazione | Se si crea un account, **si deve poterlo cancellare dentro l'app** [AP5]: non basta disattivarlo, vale anche per gli ospiti, i contenuti vanno cancellati; un link a una pagina web per completare è ammesso; può non essere immediata se spieghi i tempi | Oggi non c'è: **da sviluppare** |
| **5.1.1** Privacy | Informativa raggiungibile da App Store Connect e dall'app (cosa raccogli, terzi inclusi gli SDK pubblicitari, conservazione, cancellazione) [AP3] | Da scrivere (documento 04) |
| **5.1.2(i)** | L'app non può obbligare ad attivare le notifiche per funzionare | Le notifiche restano facoltative |
| **5.3** Gioco d'azzardo | 5.3.3 vieta l'acquisto in-app di credito per giocare con soldi veri; 5.3.4 chiede licenze solo per il gioco con soldi veri. Il testo **non definisce** il «simulated gambling» | Dichiarare KOIN finti, nessun acquisto, nessun premio reale |
| **Età** | Nel questionario, «simulated gambling» infrequente = 13+, frequente = 18+ [AP6]. Un altro estratto indicava 12+ e 18+: quale valga **NON VERIFICATO**; «frequente» non è definito | Prevedere una classificazione alta (probabilmente 17+/18+) finché l'esito non è confermato nel questionario aggiornato, obbligatorio da settembre 2026 [AP10] |
| **3.1.1** Acquisti in-app | I beni digitali venduti si pagano con IAP; le valute acquistate non possono scadere; le loot box devono dichiarare le probabilità | Oggi non si compra nulla: nessun IAP. Se i KOIN diventassero acquistabili: IAP e rischio gioco d'azzardo molto più alto |
| **1.3 / Kids** | Niente pubblicità di terzi nella categoria Kids; non usare «per bambini» | **Non** scegliere la categoria Kids |
| **4.8** Sign in with Apple | Richiesto solo se si offre un login con terze parti (Google, Facebook…) | Oggi login solo con account proprio: non serve |
| **2.1** Revisione | Account di prova funzionante e server acceso durante la revisione | Preparare un account demo e un lega demo |
| **Etichette privacy** | Dichiarare i dati raccolti, anche dagli SDK di terzi; «tracking» se i dati si combinano con altre app per la pubblicità [AP7] | Da compilare; con AdMob personalizzato vanno dichiarati tracking e identificatori |
| **ATT** | Prompt di tracciamento su iOS 14.5+ se c'è tracking [AD1, estratto] | Solo se si usa pubblicità personalizzata |
| **Requisiti tecnici** | Xcode 26 obbligatorio dal 28/04/2026 [AP9] | Mac con Xcode 26 |

## 5. Regole Google Play da rispettare

| Tema | Cosa chiede | Fonte |
|---|---|---|
| Contenuti degli utenti | Moderazione adeguata, strumenti in app per **segnalare e bloccare**, accettazione dei termini prima di caricare | [GP4, estratto] |
| Gioco d'azzardo | Vieta app che accettano puntate o vendono valute in-app per ottenere premi fisici o monetari; licenze e geo-limitazione per i soldi veri. KOIN finti e premi virtuali non rientrano nel divieto (nessun esempio esplicito trovato: **NON VERIFICATO**). Nella politica sugli annunci, «gioco simulato» tra i contenuti non ammessi per alcuni annunci: il testo non è stato aperto | [GP5, estratto] |
| Pagamenti | Le valute vendute vanno con Play Billing; i punti guadagnati o assegnati nell'app no | [GP8, estratto] |
| Cancellazione dell'account | Percorso in app **più** un link web con il nome dell'app; congelare l'account non vale; compilare la sezione nel modulo Data safety | [GP6, estratto] |
| Data safety | Obbligatorio per le app pubblicate, coerente con l'informativa privacy; include gli SDK di terzi | [GP7, estratto] |
| API di destinazione | Dal 31/08/2026 le nuove app e gli aggiornamenti devono usare Android 16 (API 36); proroga richiedibile fino al 01/11/2026 | [GP9] |
| Classificazione contenuti | Questionario IARC in «App content», da rifare se le funzioni cambiano | [GP12, estratto] |
| Famiglie | Escludere il pubblico bambini; gioco d'azzardo reale o simulato è una violazione comune per le app per famiglie | [GP13, estratto] |

## 6. Le «scommesse» negli store

- Le scommesse sono **simulate**: KOIN finti guadagnati giocando, nessun denaro, nessun premio reale, nessuna conversione. È la condizione che le tiene fuori dal gioco d'azzardo delle regole degli store (Apple 5.3.4 e Google «Gambling» parlano di soldi veri) [AP3, GP5].
- **Il rischio non è zero:** il revisore può comunque leggere «scommesse» e porre domande, e la classificazione per età sale. Mitigazioni: testi dentro l'app che dicono chiaramente «KOIN finti, nessun valore reale»; nessuna possibilità di acquistarli; nessun premio in denaro; note di revisione che lo spiegano; dichiarare in modo coerente nei questionari.
- **Se si vendessero i KOIN**, valgono: Apple IAP (3.1.1) e Google Play Billing, regole sulle valute virtuali, e il rischio che l'insieme venga considerato gioco d'azzardo: serve prima un parere legale (documento 04).

## 7. Notifiche push

Oggi il sito usa il **Web Push** con chiavi VAPID (`lib/webpush.php`). In un'app nativa:

- **Capacitor** usa il plugin ufficiale `@capacitor/push-notifications`: su Android passa da **FCM** (file `google-services.json`; su Android 13+ serve chiedere il permesso), su iOS dal token **APNs** con la capability Push Notifications; la chiave APNs si carica in Firebase; richiede un account Apple a pagamento [CP2].
- **Il server va esteso**: una tabella con i token FCM/APNs e un invio con le API di Firebase e/o Apple. Il formato esatto dell'API FCM v1 e l'autenticazione APNs **non sono stati verificati in questa ricerca**.
- Gli abbonamenti Web Push esistenti restano validi per browser e PWA, ma **non si convertono** in token nativi (nostra lettura).
- **Web Push su iPhone**: disponibile da iOS 16.4 solo per le web app aggiunte alla schermata Home [WP1, estratto]; non funziona dentro Safari. Se il Web Push funzioni dentro una WebView Capacitor su iOS o in una TWA: **NON VERIFICATO**.

## 8. Tempi, rifiuti e costi

**Tempi di revisione**

- Apple: almeno il 50% delle app in meno di 24 ore, il 90% in meno di 48 [AP4]. Oltre il 40% dei problemi non risolti riguarda la completezza (regola 2.1).
- Google: da poche ore a 7 giorni o più; margine consigliato: una settimana [GP10, estratto].

**Motivi frequenti di rifiuto documentati da Apple** [AP4]: crash e bug, link rotti, contenuto segnaposto, informazioni di revisione incomplete (account demo), lacune nell'informativa privacy, scopi dei permessi poco chiari, screenshot non fedeli, 4.2 (siti impacchettati), 4.3 (app simili), 4.1 (copie), ente sbagliato per i settori regolamentati. **Per Google:** violazioni su gioco d'azzardo, contenuti degli utenti, Data safety incoerente, API di destinazione non rispettata (fonti dei paragrafi 5 e 6, non esiste un elenco ufficiale unico).

**Costi del primo anno**

| Voce | Valore | Verifica |
|---|---|---|
| Apple Developer Program | 99 USD/anno | sì [AP1] |
| Google Play | 25 USD una tantum | estratto [GP1] |
| Mac con Xcode 26 (per iPhone) | prezzo non rilevato | **NON VERIFICATO** |
| Alternativa al Mac: Xcode Cloud | 25 ore al mese incluse nell'iscrizione Apple | sì [AP1] |
| Dominio proprio (serve alla TWA e ai cookie) | circa 20 €/anno secondo fonti secondarie | fonte non ufficiale |
| Firebase (FCM), AdMob | prezzi non controllati | **NON VERIFICATO** |
| **Totale verificato** | **124 USD** | |

## 9. Checklist di pubblicazione

1. Account Apple e Google (privato o società) e, per Google, telefono Android fisico per la verifica.
2. Dominio proprio con HTTPS; (TWA) file `assetlinks.json`.
3. Funzioni da aggiungere al sito: **cancellazione account in app**, **segnalazione e blocco** dei contenuti e degli utenti, pagina informativa privacy, pagina «termini d'uso», pagina di supporto con un contatto (documento 04).
4. Progetto Capacitor; notifiche push native (FCM/APNs); icona, splash, screenshot, descrizioni.
5. Moduli: Data safety, questionari d'età (Apple e IARC), etichette privacy Apple, dichiarazione DSA.
6. Account di prova e istruzioni nelle note di revisione; spiegare che i KOIN sono finti.
7. Android: test chiuso con almeno 12 tester per 14 giorni (reclutare in anticipo, per esempio i giocatori delle leghe esistenti); poi richiesta di produzione.
8. Invio a revisione; margine di almeno una settimana per Google e di qualche giorno per Apple.
9. Dopo l'uscita: monitorare crash e recensioni; ogni aggiornamento delle funzioni di gioco richiede di rivedere i questionari.

## 10. Cosa non si è potuto verificare

- Il testo di Apple sul «simulated gambling» fuori dal questionario d'età; quale tabella (13+/18+ o 12+/18+) valga e cosa sia «frequente».
- Il testo integrale delle politiche Google su gioco simulato e annunci; la domanda esatta del questionario IARC.
- Il tempo di verifica Apple per le organizzazioni.
- Se Altervista serve `/.well-known/assetlinks.json` con risposta 200 e il costo ufficiale del dominio.
- Se il Web Push funziona nelle WebView Capacitor/TWA e che cosa succede agli abbonamenti attuali; formato dell'API FCM v1 e autenticazione APNs.
- Se `server.url` in produzione è accettato; come si comportano i cookie di sessione.
- Prezzo di un Mac, minuti di GitHub Actions su macOS, costi Firebase.
- Gli aspetti legali italiani sulle scommesse simulate (documento 04).
