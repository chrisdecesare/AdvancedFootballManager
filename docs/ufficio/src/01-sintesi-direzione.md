---
title: 01 · Calcetto Manager: richiesta di budget per una prova di crescita
subtitle: Sintesi per la direzione: obiettivo, strade possibili, costi, rischi e decisioni richieste
---

> **Stato dei dati.** Cifre e regole sono state cercate su fonti ufficiali il **17 ottobre 2026**; dal sistema di lavoro molte pagine si sono potute leggere solo come estratti di ricerca. Ogni cifra rimanda a una fonte (documento 07); quello che non si è trovato è scritto **NON VERIFICATO**. I ricavi e le giornate di lavoro sono **stime nostre**. Il documento 04 **non è consulenza legale o fiscale**.

## Di cosa si tratta

**Calcetto Manager** è un sito/app per organizzare le partite di calcetto tra amici: presenze, squadre bilanciate, risultati, voti, classifiche, statistiche, avatar in pixel art, notifiche e una valuta **finta** (KOIN) con cui i giocatori fanno «scommesse» simulate e comprano oggetti virtuali. Oggi è un sito PHP + MySQL (circa 24.000 righe di codice, 40 tabelle) ospitato gratuitamente su **Altervista**, usato da gruppi di amici.

## Cosa vogliamo ottenere

1. **Molti più utenti**, aprendo il servizio ad altri gruppi.
2. **App su Google Play e App Store.**
3. **Pubblicità** (o altre fonti di ricavo) per sostenere i costi.
4. Una base tecnica che permetta, **se serve**, di passare a Next.js.

## Perché ora

L'app ha già le funzioni che tengono vivi i gruppi (promemoria, voti, MVP, Gazzetta, ruota della fortuna, calendario degli accessi). Il vincolo non è più il prodotto ma l'infrastruttura: **Altervista offre solo PHP, non permette AdSense/AdMob propri (solo il suo circuito con quota trattenuta), ha limiti di risorse non documentati e cron irregolari** [L23, estratto].

## Le strade (dettagli nei documenti 02–05)

| Strada | Cosa si fa | Costo in denaro, primo anno (indicativo) | Lavoro (stima) | Documento |
|---|---|---|---|---|
| **1. Server proprio** (VPS + Docker, un solo ambiente per PHP oggi e Next.js domani) | Trasloco senza cambiare il codice; cron puntuali, posta affidabile, dominio proprio, backup | circa **90–310 €** (server 5–12 €/mese, backup, dominio, posta gratuita) | **5–6 giornate** | 02 |
| **2. App negli store** | Android (TWA o Capacitor) e iPhone (Capacitor), notifiche push native | **25 USD** (Google, una tantum) + **99 USD/anno** (Apple) + Mac per iPhone (prezzo **NON VERIFICATO**) | 3–5 giornate Android, +6–10 iPhone | 03 |
| **3. Pubblicità e crescita** | AdSense/AdMob con consenso, premium, sponsor; inviti dalle leghe | Dipende; ricavi modesti all'inizio (vedi sotto) | 4–6 giornate | 05 |
| **4. Conformità** | Privacy, minori, cookie, gioco simulato, fisco | **Preventivi da richiedere** (avvocato/DPO, commercialista) | 4–6 giornate di sviluppo | 04 |
| **5. Next.js** (facoltativa) | Migrazione graduale, pagina per pagina | Stesso server | **~50–55 giornate** | 02 |

**Next.js non serve per ottenere app, pubblicità o più utenti.** Il trasloco sul server proprio serve comunque e rende Next.js possibile in futuro.

## Cosa può rendere la pubblicità (stime nostre su dati di terzi)

A **10.000 utenti attivi al mese**: da circa **24 $ al mese** (app, scenario prudente) a circa **3.600 € al mese** (web, scenario ottimistico); nello scenario medio **il ricavo copre il costo del server e poco più**. Con **1.000** utenti attivi si resta **sotto la soglia di pagamento** per mesi. Per l'Italia e per le app sportive **non esistono dati verificati**. **La pubblicità non è, da sola, la ragione dell'investimento:** l'obiettivo della prova è dimostrare **crescita e retention** (documento 05).

## Rischi principali

| Rischio | Contromisura |
|---|---|
| **Le «scommesse»** negli store e per la legge: oggi sono con KOIN finti, senza denaro né premi reali, e restano fuori dal gioco d'azzardo **solo finché è così** | Nessuna vendita di KOIN, testi chiari, classificazione per età alta, **parere legale prima** di toccare i KOIN o premiare con denaro (documenti 03 e 04) |
| **Minori** (utenti 14–17; soglia italiana del consenso digitale: 14 anni, con una riforma in discussione) | Verifica dell'età, informative semplici, cancellazione dell'account, valutazione d'impatto (DPIA) |
| **Rifiuto Apple** («solo un sito riconfezionato», regola 4.2) | Funzioni native vere (notifiche, condivisione), account demo; prima Android |
| **Pubblicità** poco redditizia o non approvata | Alternative: premium per le leghe, sponsor locali, oggetti cosmetici in euro (mai KOIN) |
| **Sicurezza e dati** su un server proprio | Backup cifrati fuori dal server, aggiornamenti automatici, monitoraggio, contratti con i fornitori |

## La richiesta

Si chiede l'approvazione dello **scenario A (minimo)** come prova, con punti di decisione prima di ogni passo successivo:

- **Spesa:** circa **90–310 €** nel primo anno + 25 USD (Google Play); **lavoro** circa **12–17 giornate**; **consulenza legale e fiscale da preventivare** (richiesta separata).
- **Tempi:** circa 3 mesi per server, requisiti privacy e app Android (compresi 14 giorni obbligatori di test chiuso con almeno 12 tester [GP2, estratto]).
- **Se i risultati sono buoni:** scenario B (iPhone, pubblicità, premium) e, solo dopo, scenario C (Next.js, ~50–55 giornate).

**Obiettivi proposti per la prova** (da concordare): leghe attive create da persone nuove, giocatori per lega (almeno 8–10), retention a 30 giorni, disponibilità del servizio almeno 99%, spesa ricorrente del server entro 18 €/mese (documento 06).

## Decisioni richieste

1. Si approva lo **scenario A**? Chi gestisce la spesa e quale intestatario per l'account sviluppatore e per il dominio (persona o azienda)?
2. **Provider:** italiano (Aruba, dati in Italia, circa il doppio del prezzo) o il più economico (Contabo, OVHcloud, Hetzner)?
3. Chi dà l'incarico per il **parere legale/DPO** e per il **commercialista**, e con quale budget?
4. La **vendita di qualsiasi cosa legata ai KOIN** resta fuori dalla prova: confermato?
5. La migrazione a **Next.js** si rimanda a dopo i risultati della prova?

## Dove approfondire

| Documento | Contenuto |
|---|---|
| 02 | Server, Docker, Next.js: provider, architettura, migrazione, costi |
| 03 | Google Play e App Store: account, regole, rischi sulle scommesse, notifiche |
| 04 | Privacy, minori, cookie, DSA, giochi, fisco, Altervista |
| 05 | Pubblicità, ricavi per scenari, crescita e KPI |
| 06 | Budget per fase, cronoprogramma, punti di decisione |
| 07 | Elenco numerato delle fonti con link e data di consultazione |
