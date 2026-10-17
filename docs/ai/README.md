# Intelligenza artificiale in Calcetto Manager: piano e 10 idee

Documento di pianificazione: **non cambia nulla nel sito**. Raccoglie idee per usare modelli di intelligenza artificiale (reti neurali, modelli di testo e di immagini) in Calcetto Manager, dice dove avrebbero senso, dove no, e in che ordine provarle.

> **Stato dei dati.** Le idee sono proposte nostre. Cifre, prezzi, limiti e disponibilità dei servizi citati **non sono stati verificati**: dove serve è scritto *da verificare*. Per le regole su privacy, minori e store vedi `docs/ufficio` (documenti 03 e 04).

## 1. Principi

1. **Usare l'AI dove serve davvero.** Molte funzioni si risolvono con statistica o regole semplici; una rete neurale non è un obiettivo.
2. **Pochi dati, modelli semplici.** Ogni lega ha poche partite: i modelli che imparano dai nostri dati vanno usati solo con abbastanza storia.
3. **I dati sono sensibili.** Foto e nomi di giocatori, anche minorenni (14–17 anni): niente invio a un servizio esterno senza contratto (art. 28 GDPR), informativa e valutazione dei rischi.
4. **Si parte dagli usi a basso rischio**, con una persona (l'admin) che conferma ciò che l'AI propone quando tocca punti, KOIN o classifiche.
5. **Un servizio esterno prima di costruire un modello proprio:** costa a consumo e si può spegnere.

## 2. Dove può girare (da decidere, senza vincoli oggi)

| Opzione | Come | Pro | Contro |
|---|---|---|---|
| **A. Servizio esterno chiamato dal server che c'è** | Una richiesta web a un servizio di modelli (per esempio i modelli di Cloudflare, Workers AI) dal PHP o da Next.js | Nessun cambio di hosting; si prova una funzione alla volta; si paga a consumo | Dati verso un fornitore: contratto e informativa; dipendenza da un servizio |
| **B. Next.js su Cloudflare** | Il sito in Next.js gira su Cloudflare (adattatore OpenNext) con i modelli accanto | Un solo fornitore per sito e AI | **Cloudflare non esegue PHP né MariaDB**: serve riscrivere tutto in Next.js e usare il loro database (D1, SQLite) o un database esterno raggiunto da fuori. È la migrazione completa del documento `docs/piano-migrazione-nextjs.md`, non un dettaglio |
| **C. Modello proprio sul VPS** | Un modello piccolo gira sul server Docker | Dati in casa | Serve CPU/GPU e manutenzione; sensato solo per modelli molto piccoli (per esempio classificatori) |

**Raccomandazione:** partire con la **A**. Prezzi, limiti e disponibilità dei modelli (testo, immagini, moderazione) **da verificare** prima di scegliere.

## 3. Le 10 idee

Legenda: **Valore** = utilità per i giocatori; **Difficoltà** = lavoro stimato (bassa/media/alta); **Rischio** = privacy e regole.

### 1. Moderazione di foto profilo e testi
- **Cosa fa:** controlla le foto caricate e i testi (idee, pensieri sulla partita, nomi delle leghe) prima che li veda qualcun altro; segnala o blocca i contenuti inadatti.
- **Dati:** foto e testi appena caricati.
- **Modello:** classificatore di immagini e di testo (anche di tipo «sicuro / non sicuro»).
- **Valore:** alto: gli store chiedono filtro e segnalazione dei contenuti degli utenti (Apple 1.2, Google UGC); protegge i minori.
- **Difficoltà:** media. **Rischio:** invio di foto a un servizio esterno (contratto art. 28, informativa).
- **Quando:** **prima di pubblicare le app.**

### 2. Gazzetta e commenti scritti da un modello di testo
- **Cosa fa:** scrive il resoconto della partita, le pagelle, soprannomi, un riassunto dei «pensieri» della partita e le curiosità della Gazzetta del mercoledì, con il tono giusto.
- **Dati:** risultato, marcatori, voti, MVP, statistiche già nell'app (nessun dato nuovo).
- **Modello:** modello di linguaggio con istruzioni e vincoli (stile, lunghezza, nomi).
- **Valore:** medio-alto: rende vivace il rituale settimanale.
- **Difficoltà:** bassa. **Rischio:** frasi sbagliate o offensive: revisione dell'admin prima della pubblicazione; non inventare fatti (si fornisce solo il dato vero).
- **Quando:** subito dopo la moderazione.

### 3. Previsione di chi non si presenta e dell'orario migliore del promemoria
- **Cosa fa:** stima chi probabilmente non confermerà e manda il promemoria all'ora in cui quella persona risponde di più.
- **Dati:** storico di presenze, conferme e orari di risposta.
- **Modello:** regressione logistica o alberi di decisione: non serve una rete profonda.
- **Valore:** alto per chi organizza (meno «siamo in 9?»). **Difficoltà:** bassa-media.
- **Rischio:** profilazione di un comportamento personale: spiegarlo nell'informativa; mostrare la stima solo all'organizzatore.
- **Quando:** con alcune decine di partite per lega.

### 4. Lettura degli screenshot di WhatsApp
- **Cosa fa:** l'admin incolla l'immagine della chat; il modello propone la lista di marcatori e assist e chi ha fatto assist a chi. L'admin controlla e conferma.
- **Dati:** immagini caricate dall'admin (con nomi e messaggi).
- **Modello:** modello che legge immagini e testo (visione + linguaggio).
- **Valore:** alto per l'admin (oggi si fa a mano). **Difficoltà:** media-alta (nomi da abbinare ai giocatori, ambiguità).
- **Rischio:** errori che cambiano punti, KOIN e Fanta; la chat contiene dati di terzi. **Attenzione:** l'importazione della «cronaca» è già stata tolta su richiesta: riproporla solo come **aiuto con conferma**, mai come salvataggio automatico.

### 5. Avatar in pixel art a partire dalla foto
- **Cosa fa:** dalla foto propone un avatar simile (capelli, barba, carnagione), da ritoccare.
- **Dati:** la foto del giocatore.
- **Modello:** modello di visione che sceglie le opzioni del catalogo (capelli, colori…), non genera immagini libere.
- **Valore:** medio (divertente, personale). **Difficoltà:** media.
- **Rischio:** foto elaborata da un servizio esterno; stereotipi nelle scelte; la foto di un minore. Solo con consenso e senza conservare la foto fuori dall'app.

### 6. Domande in italiano sulle statistiche
- **Cosa fa:** «Chi ha fatto più assist a Luigi quest'anno?» → una risposta calcolata sui dati veri, con tabella.
- **Dati:** le statistiche già presenti.
- **Modello:** il modello traduce la domanda in una ricerca **prestabilita e controllata** (non scrive SQL libero); il calcolo lo fa il sito.
- **Valore:** medio (curiosità, discussioni di spogliatoio). **Difficoltà:** media.
- **Rischio:** risposte errate se la domanda è ambigua; sicurezza (mai eseguire codice generato).

### 7. Rilevamento di abusi sui KOIN
- **Cosa fa:** segnala account fatti apposta per raccogliere passaggi di KOIN, schemi di scambio sospetti, puntate concertate.
- **Dati:** movimenti di portafoglio, passaggi, orari, dispositivi.
- **Modello:** prima **regole semplici** (limiti giornalieri già presenti, cicli di passaggi); un modello di anomalie solo con molti utenti.
- **Valore:** alto se l'app cresce. **Difficoltà:** media. **Rischio:** falsi positivi: le segnalazioni le guarda l'admin, nessun blocco automatico.

### 8. Suggerimenti per il Fanta
- **Cosa fa:** per ogni figurina stima i punti attesi nella prossima partita (forma, presenza, avversari) e suggerisce chi comprare o mettere capitano.
- **Dati:** punti e voti passati, presenze, bonus.
- **Modello:** regressione semplice o alberi con pochi attributi.
- **Valore:** medio (gioco più strategico). **Difficoltà:** media.
- **Rischio:** pochi dati: stime poco affidabili all'inizio; mostrare l'incertezza; non alterare le quote del Fanta.

### 9. Raggruppare e riassumere le idee degli utenti
- **Cosa fa:** per l'admin: raggruppa le proposte del box «Hai un'idea per il sito?», segnala i doppioni e ne fa un riassunto per tema.
- **Dati:** i testi delle proposte (leggibili solo dall'admin).
- **Modello:** modello di linguaggio con raggruppamento per somiglianza.
- **Valore:** medio per chi gestisce il prodotto. **Difficoltà:** bassa.
- **Rischio:** i testi possono contenere dati personali: usare un fornitore con contratto.

### 10. Orario e campo migliori per la prossima partita
- **Cosa fa:** propone giorno, ora e campo con più presenze probabili, in base alla storia e alle risposte.
- **Dati:** presenze passate per giorno/ora/campo, indisponibilità dichiarate.
- **Modello:** statistica e classificazione semplice.
- **Valore:** alto per i gruppi che cambiano orario spesso. **Difficoltà:** media.
- **Rischio:** basso; la scelta resta all'organizzatore.

## 4. Cosa non fare con una rete neurale

- **Quote, previsione dei risultati e bilanciamento delle squadre.** Ogni lega ha poche partite: una rete impara a memoria (overfitting) e dà risultati peggiori del modello statistico di oggi (Poisson, rating). Rivedere questa scelta solo con molte partite per lega.
- **Decisioni automatiche su KOIN, punti e classifiche** senza conferma di una persona.
- **Dati di salute o infortuni** (categorie particolari): niente modelli su questi.

## 5. Privacy e norme (da rispettare in ogni idea)

- Contratto con il fornitore del modello (art. 28 GDPR), informativa aggiornata, dove finiscono i dati (UE o fuori), conservazione e cancellazione.
- **Minori (14–17 anni):** foto e testi vanno trattati con particolare cautela; verificare l'età del consenso e se serve una valutazione d'impatto (DPIA).
- **Store:** dichiarare nei questionari (Data safety, etichette privacy) l'uso di servizi esterni di AI.
- Dettagli e fonti: `docs/ufficio` (documento 04, e 07 per le fonti).

## 6. Ordine consigliato

| Fase | Idee | Si chiude quando |
|---|---|---|
| **1** | 1 Moderazione, 2 Gazzetta | La moderazione blocca i contenuti di prova inadatti e passa la revisione degli store; la Gazzetta esce senza errori per 3 settimane con la revisione dell'admin |
| **2** | 3 Presenze, 6 Statistiche in italiano, 9 Idee degli utenti | Misura: meno risposte mancanti entro il promemoria; risposte alle domande giuste in una prova su domande reali |
| **3** | 4 Screenshot WhatsApp, 5 Avatar da foto, 7 Abusi KOIN | Solo se richiesto dagli utenti; sempre con conferma |
| **4** | 8 Fanta, 10 Orario migliore | Con abbastanza storia per lega |

## 7. Costi, rischi e cose da verificare

- **Costi:** a consumo; **prezzi e limiti dei servizi da verificare**. Stimare prima il numero di chiamate (foto caricate, testi, Gazzetta settimanale) e fissare un tetto di spesa.
- **Rischi principali:** errori dei modelli su contenuti sensibili, dati personali verso terzi, dipendenza dal fornitore, costi che crescono con gli utenti.
- **Da verificare prima di partire:** disponibilità e prezzi dei modelli di moderazione e di visione; dove vengono elaborati i dati; condizioni di Cloudflare o di altri fornitori per i dati dei minori; se per la moderazione basta un servizio già pronto.
- **Metriche:** percentuale di contenuti bloccati a ragione, tempo di revisione dell'admin, costo per partita, soddisfazione dei giocatori.
