---
title: 05 · Pubblicità, ricavi e crescita degli utenti
subtitle: Cosa è possibile, quanto può rendere (con ipotesi esplicite) e come far crescere le leghe
toc: yes
---

> **Come leggere questo documento.** I **dati di mercato** (RPM, eCPM, CPI, retention) vengono quasi tutti da **blog e studi di terzi**, non da fonti ufficiali: sono indicati come «stima di terzi» e per l'Italia e per le app sportive **non esistono dati specifici verificati**. I **ricavi** del paragrafo 4 sono **calcoli nostri** su ipotesi dichiarate, da prendere come ordini di grandezza, non come previsioni. Codici fonte tra parentesi quadre (documento 07). Consultazione: **17 ottobre 2026**.

## 1. In sintesi

- **Su Altervista non si possono usare AdSense/AdMob propri**: solo il circuito Altervista, con una quota di ricavi trattenuta [L23, estratto]. Per guadagnare in proprio serve prima il trasloco su un server tuo (documento 02).
- **I ricavi pubblicitari iniziali sono modesti.** A 10.000 utenti attivi al mese le stime vanno da circa **24 $ (app, prudente)** a circa **3.600 € (web, ottimistico)**. Con 1.000 utenti attivi si resta **sotto la soglia di pagamento** per mesi (paragrafo 4).
- **Il rischio principale di AdSense** è che l'app stia quasi tutta dietro il login: le norme escludono gli annunci su schermate di navigazione o senza contenuto editoriale e servono **pagine pubbliche con contenuti originali** [H28, H-LOG].
- **Un'app in un involucro web non è un'app AdMob:** per mostrare annunci AdMob serve una versione nativa o un involucro con l'SDK; una TWA no (ipotesi nostra, non verificata).
- **La crescita vera passa dalle leghe**, non dalla pubblicità a pagamento: un link d'invito per WhatsApp, risultati condivisibili, rituali settimanali. Prima di spendere in acquisizione, misurare retention e K-factor.
- **Alternative ai banner** da valutare: abbonamento premium per le leghe, sponsor locali di campi, partnership con centri sportivi, oggetti cosmetici a pagamento (mai i KOIN).

## 2. Cosa succede restando su Altervista

| Punto | Cosa risulta | Fonte |
|---|---|---|
| Banner di terzi | Vietati; eccezione solo per AdSense collegato dal pannello Altervista; Altervista può sospendere il servizio se Google sospende | [L23, estratto] |
| AdSense con codice proprio | Secondo un moderatore del forum non produce annunci e violerebbe i termini | [H-A1, forum, non ufficiale] |
| Dominio personalizzato | Non sblocca gli annunci esterni | [H-A2, forum] |
| Quota all'utente | Circa 65% dei ricavi AdSense (più una componente di Altervista): **dato di forum, NON ufficiale** | [H-A3, forum] |
| Pagamento | Soglia di 70 € dal resoconto Altervista | [H-A4, tutorial ufficiale, estratto] |
| Approvazione | Da giorni a mesi; banner vuoti nel frattempo | [H-A4] |

**Conclusione:** la pubblicità su Altervista è possibile ma con quote, controllo e fisco diversi (la controparte dei ricavi può essere Altervista, non Google: documento 04). Con ambizioni di crescita e app, conviene il trasloco.

## 3. Come funziona la pubblicità su un server proprio e nelle app

**AdSense (web)**

- Requisiti: 18+ anni, contenuto proprio conforme alle policy, accesso al codice HTML [H26, estratto].
- File `ads.txt` nella radice del dominio; i cambiamenti possono richiedere da giorni a un mese [H27, estratto].
- **Contenuto**: niente annunci su schermate senza contenuto editoriale, in costruzione, o usate per avvisi, navigazione, azioni [H28, estratto]. Per le pagine dietro login non c'è una regola ufficiale trovata: il crawler non entra nel login, quindi servono pagine pubbliche (classifiche pubbliche di una lega, Gazzetta, curiosità) con testi originali. **Da chiarire con Google.**
- Sottodomini: Google non li tratta più come siti separati; contano i domini completi [H29, estratto]: un dominio proprio è la strada.
- Quota ai publisher circa **68%**, soglia di pagamento **70 €**, pagamento tra il 21 e il 26 del mese [H30, estratto].
- **Consenso (SEE, UK, Svizzera):** CMP certificata Google con TCF; TCF v2.3 obbligatorio da marzo 2026 [L8, L9].

**AdMob (app)**

- L'app deve essere pubblicata sullo store, con `app-ads.txt` e sito dello sviluppatore; le app create dopo gennaio 2025 senza verifica hanno erogazione limitata [H31, estratto].
- **UMP SDK** per il consenso a ogni avvio; niente richieste di annunci finché `canRequestAds` è falso [H32, estratto]. Su iOS serve anche il prompt ATT [AD1, AD2].
- Pagamenti: PIN a 10 $ di saldo, soglia **100 $** (conto in USD), circa il 21 del mese; soglia in euro non confermata [H33, estratto].
- Le norme Google Play sul **gioco d'azzardo simulato** e quelle sugli annunci si applicano alle app con «scommesse» anche finte: testo completo **da leggere** prima di attivare AdMob (documento 03) [GP5].
- **Minori:** il trattamento «child» disattiva gli annunci personalizzati (documento 04).

## 4. Quanto può rendere: formula e tre scenari

**Formula:** ricavo mensile = utenti attivi mensili × pagine (o impressioni) per utente × RPM / 1.000. I valori sono già al netto della quota di Google.

**Dati di partenza (stime di terzi, non specifiche per l'Italia):**

- RPM AdSense per un pubblico italiano: **1–5 €** (un solo blog) [T1]; per i siti sportivi italiani **non trovato**.
- eCPM AdMob «Tier 2» (include l'Italia): banner 0,18–0,35 $; interstitial 5–7 $; rewarded 8–10 $ [T2]: aggregatori di terzi, non attribuibili a un'unica fonte.

**Web / AdSense** (RPM 1 / 2,5 / 5 €)

| Scenario | Pagine viste per utente al mese | 1.000 utenti | 10.000 utenti | 50.000 utenti |
|---|---|---|---|---|
| Prudente (RPM 1 €) | 4 sessioni × 3 pagine = 12 | 12 € | 120 € | 600 € |
| Medio (RPM 2,5 €) | 8 × 5 = 40 | 100 € | 1.000 € | 5.000 € |
| Ottimistico (RPM 5 €) | 12 × 6 = 72 | 360 € | 3.600 € | 18.000 € |

**App / AdMob** (dollari; banner e interstitial, rewarded esclusi)

| Scenario | Ipotesi | 1.000 utenti | 10.000 utenti | 50.000 utenti |
|---|---|---|---|---|
| Prudente | 12 banner a 0,20 $ | 2,4 $ | 24 $ | 120 $ |
| Medio | 40 banner a 0,28 $ + 4 interstitial a 5 $ | 31 $ | 312 $ | 1.560 $ |
| Ottimistico | 72 banner a 0,35 $ + 12 interstitial a 7 $ | 109 $ | 1.092 $ | 5.460 $ |

**Letture pratiche (nostre):**

- Con 1.000 utenti attivi nello scenario prudente si resta sotto la soglia AdSense di 70 € per circa 6 mesi; in AdMob, scenario prudente, ci vogliono oltre 3 anni per i 100 $.
- Nello scenario medio, 10.000 utenti attivi **coprono il costo del server e poco più**.
- Tutto dipende dall'**approvazione di AdSense** su un'app con pochi contenuti pubblici: oggi è la parte più incerta.
- Ai ricavi vanno tolti **imposte e contributi** (documento 04).

**Impatto sull'esperienza.** Gli studi di terzi (non specifici per app di calcetto) mostrano che annunci invasivi aumentano l'abbandono: circa 6–7% in più dopo un'esperienza pubblicitaria fastidiosa [T4]; l'effetto dei banner semplici non è documentato. **Nostra raccomandazione:** banner leggeri solo in schermate non critiche; mai un interstitial prima della prima partita.

## 5. Alternative alla pubblicità

| Opzione | Pro | Contro | Dato |
|---|---|---|---|
| **Abbonamento premium** (per lega o per capitano) | Ricavo prevedibile, nessun annuncio | Pochi paganti (la quota di conversione tipica non è stata verificata) | Concorrenti con premium da 5,99 €/mese [T7, datato] |
| **Sponsor locali di campi** | Tono giusto, margine alto | Vendita porta a porta, scala poco | Nessun dato |
| **Partnership con centri sportivi** | Accesso a più leghe in una volta | Tempi lunghi, pochi referenti | Nessun dato |
| **Oggetti cosmetici a pagamento** (in euro, mai i KOIN) | Coerente col prodotto | Commissione di Apple/Google (15–30%; 15% con il programma Small Business fino a 1 milione di dollari [H34]); se si comprassero KOIN, le scommesse simulate diventerebbero «gioco con valuta acquistata» | Documenti 03 e 04 |
| **Banner AdSense/AdMob** | Semplice da attivare | RPM basso, rischio di rifiuto, impatto sull'esperienza, consenso | Paragrafi 3 e 4 |

Il **parere legale** è obbligatorio prima di vendere qualunque cosa che tocchi i KOIN (documento 04).

## 6. Come far crescere gli utenti

**Principio:** la retention vive nella **lega**: una partita a settimana, un gruppo di amici. Conta quanti giocatori ci sono in ogni lega, non il numero di scaricamenti.

Azioni (ipotesi di strategia nostra; l'ottimizzazione degli store non è stata verificata):

1. **Link d'invito WhatsApp** con anteprima (immagine e titolo): ogni lega ha il suo, ogni condivisione porta un nuovo giocatore.
2. **Risultati condivisibili:** MVP, classifica, Gazzetta del mercoledì, avatar del giocatore: ogni immagine porta il link.
3. **Rituale settimanale:** promemoria, voti, MVP: l'attività segue le partite, quindi D7 e D30 contano più di D1.
4. **Community:** gruppi Facebook, Instagram e Reddit di calcetto, pagine dei campi di calcetto.
5. **Pubblicità a pagamento** solo dopo aver misurato retention e K-factor: i costi per installazione in Italia **non sono verificabili** (proxy Europa occidentale da fonti di terzi: iOS 3,40 $, Android 1,85 $ per app fitness [T3]).
6. **Privacy:** un link d'invito non deve mostrare dati dei giocatori a chi non è nella lega.

**Concorrenti e mercato (fonti di terzi, datate):** Fubles dichiarava circa 400.000 utenti di cui il 25–30% scendeva in campo (2015); Goalcup è gratuita con banner; SquadraPiù ha un piano premium a 5,99 €/mese; SportEasy, Mr. Calcetto, Ten App [T7]. Dati aggiornati sul numero di praticanti di calcetto in Italia: **non trovati**.

## 7. Indicatori da misurare (KPI)

| KPI | Come si legge | Riferimento di terzi |
|---|---|---|
| **Leghe create** e giocatori per lega | Unità vera del prodotto | – |
| **Attivazione entro 7 giorni** | Giocatori che confermano una presenza o votano | – |
| **Retention D1 / D7 / D30** | Quanti tornano dopo 1, 7, 30 giorni | App in generale: D1 circa 25–30%, D30 circa 5–7%; giochi: D1 27%, D7 13%, D30 5% [T5] (non per app sportive) |
| **DAU/MAU** | Quanto è abituale l'uso | Social: 25% accettabile, 40% buono, 50%+ ottimo [T6] |
| **K-factor** | Inviti per utente × conversione; K = 1 è il pareggio | Valori tipici 0,3–0,7; oltre 1 sostenuto è raro [T6, di un fornitore] |
| **Ricavo per utente** | Ricavi / utenti attivi | Paragrafo 4 |
| **Costo di acquisizione** | Spesa / nuovi utenti | CPI Italia: **non verificabile** |

**Primo passo pratico:** installare uno strumento di analisi rispettoso della privacy (per esempio Plausible o Umami) per misurare utenti attivi, sessioni, pagine per sessione, leghe create e inviti, **prima** di decidere tra premium, sponsor e banner.

## 8. Cosa non si è potuto verificare

- RPM e eCPM specifici per l'Italia e per i siti/app sportivi; il CPI in Italia; la retention per app sportive; il numero di praticanti di calcetto; il CPM di Meta.
- La regola ufficiale di AdSense sulle pagine dietro login e se un'app con poco contenuto pubblico verrebbe approvata.
- Se AdMob funziona dentro una TWA (ipotesi: no) e la soglia di pagamento AdMob in euro.
- La percentuale esatta che Altervista trattiene (solo forum) e il testo italiano del regolamento.
- I ricavi del paragrafo 4 sono calcoli nostri su ipotesi dichiarate.
