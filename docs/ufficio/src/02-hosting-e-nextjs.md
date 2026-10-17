---
title: 02 · Hosting e Next.js: un solo ambiente Docker per PHP oggi e Next.js domani
subtitle: Confronto dei provider, architettura, costi, tempi e rischi
toc: yes
---

> **Come leggere i prezzi.** Vengono da pagine dei provider lette come estratti di ricerca (non aperte direttamente) o da siti di terzi: sono **indicativi e vanno riletti sul sito prima di comprare**. Codici fonte tra parentesi quadre, elenco nel documento 07. Consultazione: **17 ottobre 2026**. Dove manca un dato: **NON VERIFICATO**.

## 1. In sintesi

- Altervista offre solo PHP: per crescere (cron puntuali, email affidabili, pubblicità propria, Next.js in futuro) serve **un server proprio**. Un **VPS con Docker Compose** costa oggi **circa 5–12 € al mese** per la scala di 1.000–10.000 utenti registrati (stima nostra, da validare con un test di carico).
- **Un solo ambiente va bene per entrambi:** nello stesso Compose girano `Caddy` (HTTPS automatico), `PHP-FPM` con il sito di oggi, `MariaDB` con lo stesso schema, e più avanti `Next.js` (immagine Docker «standalone»). Caddy smista gli indirizzi pagina per pagina: il sito cambia senza interruzioni.
- **La scelta di Next.js non è un prerequisito** per app, pubblicità e crescita (documenti 03 e 05): serve per un'interfaccia più interattiva e per la manutenzione a lungo termine. Il trasloco su VPS invece **serve comunque**.
- **Costi ricorrenti stimati (scenario minimo):** VPS 6–12 € + backup 1–5 € + posta transazionale 0 € (piano gratuito) + dominio circa 20 €/anno ≈ **8–18 € al mese**. Altervista oggi costa 0.

## 2. Perché lasciare Altervista

| Limite di oggi | Effetto |
|---|---|
| Solo PHP, nessun Node | Next.js non può girarci |
| Pubblicità: solo il circuito Altervista, con quota di ricavi trattenuta; AdSense/AdMob propri non ammessi [L23, H-A1] | Niente ricavi pubblicitari propri finché si resta lì |
| Hosting standard: 3 GB e 30 GB di traffico al mese; limiti di CPU, database, cron e posta non trovati [L23, estratto] | Rischio di sospensione con molti utenti, **NON VERIFICABILE** senza chiedere ad Altervista |
| `mail()` di PHP: le email finiscono spesso nello spam | Verifica email e recupero password poco affidabili |
| Cron esterno ogni 10–15 minuti, o «al volo» mentre qualcuno usa il sito | Promemoria e chiusura voti non puntuali |
| Cache Varnish che toglie i cookie | Nome di sessione «finto» per sopravvivere |

## 3. I provider (prezzi indicativi, IVA come indicata)

| Provider e piano | Risorse | Prezzo mensile | Note | Fonte |
|---|---|---|---|---|
| **Contabo** VPS 10 | 4 vCPU, 8 GB, 75 GB NVMe, traffico illimitato | 4,50–5,50 € (12 mesi) | Prezzi incoerenti tra le fonti; IVA ignota | [H2, estratto] |
| **OVHcloud** VPS-2 | 4 vCPU, 8 GB, 75 GB | 8,50 $ | Prezzo in euro non trovato | [H3, estratto] |
| **Aruba Cloud** VPS (OpenStack) 4 vCPU / 8 GB | 4 vCPU, 8 GB, 80 GB | 11,79 € + IVA | **Dati in Italia**; piano 2 vCPU/4 GB a 6,29 €; il nuovo Cloud Server parte da 9,49 € + IVA | [H4, estratto] |
| **Hetzner** CX33 | 4 vCPU, 8 GB, 80 GB | 8,49 € (da 6,49 €) + IVA, IPv4 +0,50 € | Due aumenti nel 2026 (aprile e 15 giugno); i piani CX e CAX risultano spesso «non disponibili» (fonti di terzi, da riverificare) | [H1, estratto; T-H] |
| **Hetzner** CPX22 | 2 vCPU AMD, 4 GB | 19,49 € (era 7,99 €) | Dopo gli aumenti non è più conveniente | [H1, estratto] |
| **Scaleway** DEV1-S | 2 vCPU, 2 GB | circa 6 € | Troppo poca RAM per quattro servizi | [H5, estratto] |
| **DigitalOcean** Basic | 2 GiB: 12 $; 4 GiB: 24 $ | in dollari, IVA non indicata | Più caro per la stessa RAM | [H6, estratto] |

**Alternative gestite** (nessun server da mantenere, ma più care o con limiti sui lavori a orario):

| Servizio | Prezzo | Lavori a orario | Valutazione |
|---|---|---|---|
| Vercel Hobby / Pro | gratis (solo uso non commerciale) / 20 $ al mese | Hobby: **una volta al giorno**; Pro: al minuto, fatturati come funzioni [H7] | Non adatto al PHP; costoso per un sito con cron frequenti |
| Railway | da 5 $ (Hobby) / 20 $ (Pro) + uso | minimo ogni 5 minuti, il processo deve terminare da solo [H8] | Per tre servizi sempre accesi si arriva a circa 40–50 $ al mese (**calcolo nostro, non verificato**) |
| Render | da 0 a 25 $ al mese + 7 $ per servizio (Starter) | cron al secondo, minimo 1 $ al mese per cron [H9] | Un MariaDB gestito non verificato |
| Fly.io | da circa 1,94 $ per macchina piccola | niente cron veri sotto l'ora [H10] | Meno adatto |
| **Coolify / Dokploy su VPS** | software gratuito; minimo 2 vCPU/2 GB, ideale 4 GB | cron a 5 campi dentro Compose [H11] | Comodo per deploy e backup con interfaccia; occupa 600–800 MB di RAM |

**Scelta consigliata:** **un VPS 4 vCPU / 8 GB** con **Docker Compose**, deploy da GitHub Actions via SSH. Tra Contabo (più economico), OVHcloud e Hetzner (se disponibile) l'unica differenza è il prezzo; **Aruba è l'opzione se serve avere i dati in Italia** e un fornitore italiano per il GDPR (a circa il doppio del prezzo). Il provider preciso si sceglie dopo aver riletto prezzi e disponibilità e firmato il contratto art. 28 (documento 04).

## 4. Come dimensionarlo (stima, da validare)

Ipotesi: nelle sere di partita sono connessi in contemporanea il 3–5% degli utenti registrati, ognuno chiede una pagina ogni 20–30 secondi, una richiesta PHP dura 50–100 ms. Riferimenti: un worker PHP-FPM pesa 30–150 MB [H-FPM]; per MariaDB si parte dal 60–70% della RAM libera nel buffer pool [H-MDB]. **Tutto da misurare con un test di carico**: un dato affidabile sul consumo di RAM di Next.js standalone non è stato trovato.

| Utenti registrati | Picco contemporaneo | Richieste/s al picco | Macchina | Costo indicativo |
|---|---|---|---|---|
| circa 1.000 | 30–50 | 1–2 | 2 vCPU / 4 GB | 5–7 € |
| circa 10.000 | 300–500 | 12–20 | 4 vCPU / 8 GB (circa 15–25 worker PHP) | 5–12 € (Contabo/OVH/Hetzner) o 11,79 € (Aruba) |
| circa 50.000 | 1.500–2.500 | 60–100 | 8 vCPU / 16 GB o database su macchina separata, più una cache | 20–40 € (Aruba 8 vCPU/16 GB a 19,89 €) più il server del database |

I carichi più pesanti sono la **coda delle notifiche push** e il **bilanciamento delle squadre**: conviene isolarli in un lavoro a parte.

## 5. Architettura di arrivo

```
Internet ──► Caddy (HTTPS automatico, smista gli indirizzi)
              ├── /vecchie pagine  ──► PHP-FPM  (sito di oggi, invariato)
              └── /pagine nuove    ──► Next.js  (output standalone, Node 24 LTS)
                          │                  │
                          └──── MariaDB (stesso schema) ────┘
              cron ──► script dei lavori a orario (prima PHP, poi Node, mai tutti e due)
              backup ──► restic verso un deposito esterno
```

- **Sessione condivisa:** PHP salva le sessioni in una tabella `sessions` (gestore su misura che scrive JSON) e Next.js legge solo l'identificativo dal cookie con una query; così il passaggio da una pagina PHP a una Next è invisibile. Alternative (Redis condiviso, token firmato) aggiungono un servizio: scartate. Fonte sul formato di sessione PHP: [H24]. **Strada valutata da noi, non confermata da una fonte ufficiale.**
- **Next.js:** versione stabile 16.4.2 [H19, GitHub]; Node 24 LTS attiva e Node 26 in *Current* (diventa LTS il 28/10/2026) [H20]; `output: "standalone"` genera `server.js` da avviare in Docker con `PORT` e `HOSTNAME` [H22]; con più istanze servono una cache condivisa e una chiave comune per le Server Actions [H21]. Caddy come proxy: pagina ufficiale non raggiunta (**NON VERIFICATO**), configurazione di base nota.
- **Posta:** SMTP transazionale al posto di `mail()`: Brevo (300 email/giorno gratis; 5.000 al mese a 9 $) [H15], Mailgun (100/giorno gratis, 15 $ per 10.000) [H16], Resend (3.000/mese gratis) [H18]; Amazon SES non ha più il piano gratuito per i nuovi clienti dal 21/07/2026 [H17].
- **Backup:** `restic` (cifrato, con deduplica) verso Backblaze B2 o Cloudflare R2 (circa 6 $/TB o 0,015 $/GB al mese, **stime di terzi**) [H12, T-B]; in più i backup del provider.
- **Monitoraggio:** UptimeRobot (50 monitor ogni 5 minuti gratis) [H14] e/o Uptime Kuma auto-ospitato [H13].
- **Lavori a orario:** cron del server ogni 5 minuti al posto del cron esterno.

## 6. Piano di migrazione (graduale, senza fermare il sito)

I tempi sono **giornate di lavoro concentrate** del piano tecnico interno (`docs/piano-migrazione-nextjs.md`), con un assistente AI che scrive gran parte del codice: **stime nostre**, non verificate.

| Fase | Cosa | Giornate | Si chiude quando |
|---|---|---|---|
| **0 Preparazione** | Inventario di pagine e lavori a orario; test di riferimento (JSON) sulle funzioni chiave; copia anonimizzata del database | 2–3 | I JSON esistono e si rigenerano con un comando |
| **1 Nuovo server** | VPS, Docker Compose (Caddy + PHP-FPM + MariaDB), backup, dominio e HTTPS, SMTP, copia di database e foto da Altervista, deploy da GitHub Actions | **3** | Il sito PHP gira identico sul nuovo server; Altervista resta spento ma intatto qualche settimana |
| **2 Fondamenta Next.js** | Progetto Next + TypeScript, Drizzle sul database esistente, sessioni condivise, layout e menu con lo stesso CSS | 5 | Una pagina Next mostra menu, KOIN e lega dell'utente collegato da PHP |
| **3 Pagine in sola lettura** | Home, Partite, Rosa, Giocatore, Classifica, Curiosità, Gazzetta | 6 | Ogni pagina servita da Next, schermate uguali a quelle PHP |
| **4 Partite** | Presenze, creazione partite, squadre, risultato, voti, pagamenti | 8 | I test di riferimento di bilanciamento, statistiche e premi passano |
| **5 Economia** | Scommesse, portafoglio, passaggi di KOIN, negozio, avatar, Fanta | 12 | Le quote coincidono al centesimo con PHP; saldi identici dopo una partita simulata |
| **6 Notifiche e lavori a orario** | Coda push con le stesse chiavi VAPID, email, cron in Node | 5 | Su un dispositivo di prova arrivano tutte le notifiche una volta sola |
| **7 Account e amministrazione** | Accesso, iscrizione, 2FA, ospiti, leghe, admin | 6 | Login con le password di oggi; nessuno rifà l'accesso |
| **8 Spegnimento di PHP** | Ultimi indirizzi a Next, migrazioni a Drizzle | 3–4 | Una settimana senza errori solo con Next |
| | **Totale** | **~50–55** | |

**Scenario «minimo» (consigliato come prima richiesta):** solo le fasi **0 e 1** (circa 5–6 giornate, ~8–18 €/mese). Sblocca cron puntuali, email affidabili, pubblicità propria e il dominio per le app, **senza** toccare il codice delle funzioni. La migrazione a Next.js (fasi 2–8) si decide dopo, con dati reali.

## 7. Rischi e contromisure

| Rischio | Contromisura |
|---|---|
| Numeri diversi in quote, premi, classifiche | Test di riferimento in JSON prima di accendere ogni pagina (fase 0) |
| Notifiche perse o doppie | Stesse chiavi VAPID; un solo sistema di cron alla volta; coda con chiave unica |
| Tutti devono rifare l'accesso | Stesse tabelle di sessione; `password_hash` PHP (bcrypt `$2y$`) verificabile con le librerie Node |
| Dati persi nel trasloco | Copia completa di database e foto, controllo dei conteggi riga per riga, Altervista intatto fino alla fine |
| Server da mantenere | Aggiornamenti automatici di sicurezza, backup giornalieri fuori dal server, monitor gratuito |
| Costi che salgono | Il VPS è a canone fisso; i servizi a consumo (SMTP, storage dei backup) restano nei piani gratuiti o a pochi euro |
| Dipendenza da un solo fornitore | Immagini Docker portabili e backup cifrati fuori dal fornitore: cambiare VPS richiede ore, non settimane |
| Next.js cambia spesso | Aggiornamenti programmati; Node in LTS |

## 8. Vantaggi e svantaggi di Next.js (per decidere dopo la fase 1)

**Vantaggi:** TypeScript (errori visti prima), componenti riusabili, interfaccia più viva (quote, presenze, cronaca senza ricaricare), test automatici sulla logica delicata, più facile trovare collaboratori.

**Svantaggi:** ~50–55 giornate senza funzioni nuove per i giocatori; complessità maggiore (build, Node, Docker, differenza tra componenti server e client); regressioni possibili su regole accumulate nel tempo; due versioni da tenere allineate per settimane.

**L'alternativa:** restare in PHP e migliorarlo (tipi rigorosi, PHPStan, PHPUnit, PHPMailer con SMTP vero) costa una frazione del lavoro. **Next.js conviene se il sito deve crescere molto** (più leghe e utenti, interfaccia molto interattiva, un'app dedicata).

## 9. Decisioni richieste

1. Si approva la **fase 0 + 1** (trasloco su VPS, dominio proprio, SMTP, backup)? Budget: circa 5–6 giornate e 8–18 €/mese.
2. Provider: italiano (Aruba, dati in Italia) o il più economico (Contabo/OVH/Hetzner)?
3. Dominio proprio: quale nome e chi lo intesta?
4. La migrazione a Next.js (fasi 2–8) si decide dopo i primi risultati della fase 1 e delle app (documento 06).

## 10. Cosa non si è potuto verificare

- Pagine ufficiali dei provider aperte per intero; prezzi in euro di OVHcloud; IVA di Contabo, OVH, Aruba, Scaleway e DigitalOcean; disponibilità reale dei piani Hetzner.
- Pagina ufficiale di Caddy (`php_fastcgi`, HTTPS automatico) e Borg.
- Dimensionamento (paragrafo 4): è un calcolo su ipotesi dichiarate, non una misura.
- Limiti e condizioni di Altervista (documento 04).
