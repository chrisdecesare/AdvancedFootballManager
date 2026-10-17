---
title: 04 · Conformità legale, privacy e fisco (Italia / UE)
subtitle: Cosa devi rispettare per crescere, pubblicare sugli store e mostrare pubblicità
toc: yes
---

> **Avviso importante.** Questo documento **non è consulenza legale, fiscale o previdenziale**: riporta le regole trovate su fonti ufficiali e dice dove servono un avvocato/DPO e un commercialista. **Limite della ricerca:** dal sistema di lavoro non si sono potute aprire direttamente le pagine di Garante, EUR-Lex, Normattiva, ADM, Agenzia delle Entrate, Google e Altervista. Quasi tutto viene da **estratti di ricerca** che citano la pagina ufficiale («estratto»): prima di usare soglie, articoli o aliquote, **riaprire il link da un computer con accesso diretto** (elenco nel documento 07). Dove non si è trovato nulla: **NON VERIFICATO**. Consultazione: **17 ottobre 2026**.

## 1. Quadro in una pagina

| Tema | Stato oggi | Cosa serve |
|---|---|---|
| **Privacy / GDPR** | App con account, foto, statistiche, voti, notifiche, anche utenti 14–17 anni | Informativa, base giuridica per ogni trattamento, registro dei trattamenti, procedura per i diritti e per i data breach, contratti con i fornitori (hosting, SMTP, Firebase) |
| **Minori** | Soglia italiana del consenso digitale: **14 anni** [L5]; una riforma in Parlamento potrebbe portarla a **16** (esito 2026 **NON VERIFICATO**) [L6] | Verifica dell'età con «ragionevole sforzo», informative comprensibili, valutazione d'impatto (DPIA) |
| **Pubblicità** | Non attiva | Consenso con CMP certificata Google e TCF v2.3 [L8, L9]; rispetto delle linee guida cookie del Garante [L7] |
| **Scommesse simulate** | KOIN finti, nessun denaro né premio reale | Restano fuori dal gioco d'azzardo **solo finché** non c'è denaro/premio reale né vendita di KOIN (inferenza, parere legale consigliato) |
| **Contenuti degli utenti** | Foto, idee, pensieri, nomi delle leghe | Meccanismo di segnalazione, termini d'uso, moderazione, punto di contatto (DSA) [L10] |
| **Fisco** | Nessun ricavo | Con la pubblicità: partita IVA, regime, contributi; vedi paragrafo 7 |
| **Altervista** | Hosting gratuito | Le condizioni sulla pubblicità e sull'uso commerciale limitano le strategie: paragrafo 8 |

## 2. Privacy e protezione dei dati (Reg. UE 2016/679, «GDPR») [L1]

1. **Base giuridica (art. 6).** Ogni trattamento (accesso, statistiche, foto, notifiche, email, pubblicità) deve averne una. Il consenso non è l'unica; le candidate sono contratto, consenso, legittimo interesse, obbligo legale [estratto, Garante]. **Assegnarle è una decisione del titolare** da documentare con un consulente, non un dato verificabile da fonte.
2. **Informativa (artt. 13–14).** Contenuti elencati in modo tassativo; va resa **prima** del trattamento, in linguaggio chiaro; il Garante ha sanzionato informative prive del diritto di reclamo [estratto]. La checklist dettagliata dell'art. 13 **non è stata letta**: aprire il testo.
3. **Registro dei trattamenti (art. 30).** L'esonero sotto i 250 dipendenti vale **solo** se il trattamento non è a rischio, è occasionale e non riguarda dati particolari [L2, estratto]. Un'app con account e dati trattati con continuità ricade, **secondo la nostra lettura**, nell'obbligo.
4. **DPO (artt. 37–39).** Obbligatorio per enti pubblici, per il monitoraggio regolare e sistematico **su larga scala** come attività principale, o per dati particolari su larga scala; il numero di dipendenti non conta. «Larga scala» non è definita nel regolamento: se l'app con «molti utenti» ci rientri **NON VERIFICATO** (da chiedere a un consulente) [estratto].
5. **Diritti degli interessati (artt. 12–22).** Risposta entro **1 mese** (prorogabile fino a 3 nei casi complessi), di regola gratuita, anche in caso di diniego; cancellazione (art. 17) anche dopo revoca del consenso [L4, estratto]. **Conseguenza per l'app:** serve un percorso per accesso e cancellazione dei dati; gli store chiedono anche la cancellazione dentro l'app (documento 03).
6. **Violazione dei dati (artt. 33–34).** Notifica al Garante **entro 72 ore** dalla scoperta, salvo rischio improbabile; ogni violazione va documentata anche se non notificata; la procedura è telematica sul portale del Garante [L3, estratto]. Il testo dell'art. 34 (comunicazione agli interessati) **non è stato verificato**.
7. **Fornitori (art. 28).** Con hosting, SMTP e Firebase serve un contratto scritto che rispetti l'art. 28; il Garante ha sanzionato chi opera senza [estratto]. Google pubblica i termini di trattamento di Firebase e le clausole tipo UE [L29, versione da riverificare]. Se **Altervista** e il fornitore SMTP offrano un contratto art. 28 e dove siano i server: **NON VERIFICATO**.
8. **Trasferimenti fuori UE (artt. 44–49).** Consentiti con decisione di adeguatezza, clausole tipo o deroghe [estratto]. UE–USA: decisione sul Data Privacy Framework del 10 luglio 2023; il ricorso Latombe è stato respinto in primo grado il 3 settembre 2025 e l'appello (C-703/25 P) risultava pendente: aggiornare prima di scegliere servizi americani [L30, estratto].
9. **DPIA (art. 35).** L'elenco del Garante (provv. 467/2018) comprende i trattamenti non occasionali di dati di soggetti vulnerabili (tra cui i minori); con due o più criteri WP248 l'alto rischio è probabile [L27, estratto]. **Se l'app richieda la DPIA è una valutazione del titolare** (minori 14–17 + continuità + eventuale profilazione pubblicitaria): fortemente consigliata.

## 3. Minori

- **GDPR art. 8:** soglia generale 16 anni; gli Stati possono abbassarla ma non sotto i 13; il titolare deve fare «ogni ragionevole sforzo» per verificare il consenso del genitore [L1, estratto].
- **Italia, art. 2-quinquies del Codice privacy:** da **14 anni** il minore può dare il consenso per i servizi della società dell'informazione; **sotto i 14** serve il consenso di chi esercita la responsabilità genitoriale; informative in linguaggio comprensibile al minore [L5, estratto].
- **Cosa comporta per gli utenti sotto i 14:** la regola riguarda i trattamenti **basati sul consenso**; per altre basi (contratto) l'art. 8.3 rinvia al diritto contrattuale nazionale: come si applichi a un account gratuito **NON VERIFICATO**. Il Garante ha contestato a un servizio (provv. 10 aprile 2025) l'assenza di procedure di verifica dell'età [estratto]: la sola autodichiarazione è considerata insufficiente nelle linee guida UE per le grandi piattaforme; per un piccolo operatore il livello richiesto **non è stato trovato**.
- **Riforma:** il disegno di legge S.1136 «Tutela dei minori nella dimensione digitale» alza il consenso autonomo a 16 anni (e 15 per i social, in una versione successiva); ultimo stato trovato: ottobre 2025, in Commissione. **Esito 2026 NON VERIFICATO** [L6].
- **Pubblicità e minori:** AdMob ha sostituito i vecchi parametri con il trattamento per età (TFAT): il trattamento «child» disattiva annunci personalizzati e remarketing; l'SDK di consenso (UMP) **non** passa l'età automaticamente, va impostata nell'app. Quale impostazione valga per gli utenti 14–17 in Italia: **NON VERIFICATO** [estratto].
- **Foto di minori:** diritto d'immagine (art. 10 c.c., artt. 96–97 L. 633/1941): il ritratto non si pubblica senza consenso, salvo eccezioni; il Garante applica insieme diritto d'autore e protezione dei dati [L26, estratto]. Foto di minori caricate da altri utenti o da ospiti: **nessuna fonte specifica trovata**.

## 4. Cookie, pubblicità e consenso

- **Linee guida del Garante (10 giugno 2021, in vigore dal 10 gennaio 2022)** [L7, estratto]: lo scrolling da solo non è consenso; il **cookie wall** è illegittimo (salvo accesso equivalente senza consenso); se l'utente rifiuta, la scelta va registrata e il banner non riproposto per **almeno 6 mesi**; il consenso è revocabile. Condizioni per cookie tecnici/analytics senza consenso: **NON VERIFICATO**.
- **Nuove linee guida sui tracking pixel nelle email** (provv. 17 aprile 2026) [L31]: contenuto **non letto**: rilevanti se si inviano email con tracciamento delle aperture.
- **Marketing (art. 130 Codice privacy):** per email, SMS e messaggi automatizzati serve consenso preventivo; deroga «soft spam» solo per email su prodotti analoghi a quelli già acquistati [L28, estratto]. Le email di servizio (conferma account, reset password) **non sono state analizzate**.
- **Google (AdSense e AdMob) nello SEE e UK:** per annunci **personalizzati** serve una **CMP certificata Google con TCF IAB** (dal 16 gennaio 2024; dal 31 luglio 2024 anche la Svizzera) [L8, estratto]. **TCF v2.3 obbligatorio** dal 28 febbraio / 1 marzo 2026 (segmento `disclosedVendors`) [L9]; un successivo aggiornamento del 16 luglio 2026 (TCF v5.0.b / specifiche v2.4) **non è stato letto**. Per le app AdMob si usa l'**UMP SDK**.
- **Consent Mode v2:** i parametri `ad_user_data` e `ad_personalization` vanno concessi per la pubblicità personalizzata [estratto; pagine di Google datate, riverificare].
- **Sul sito Altervista**, chi fornisca la CMP quando la pubblicità passa dal circuito Altervista: **NON VERIFICATO**.

## 5. Contenuti degli utenti e DSA (Reg. UE 2022/2065) [L10]

- Un servizio che memorizza contenuti forniti dagli utenti è «**hosting**»; se li diffonde a un numero potenzialmente illimitato di terzi è «**piattaforma online**». Idee, pensieri, foto e nomi delle leghe: se sono visibili solo a gruppi chiusi o a tutti è **da qualificare** (il considerando 14 tratterebbe i gruppi chiusi come comunicazione interpersonale: non verificato).
- Obblighi per tutti gli hosting riportati dagli estratti: **punto di contatto** per le autorità (art. 11) e per gli utenti (art. 12), **condizioni d'uso** con le regole di moderazione e il sistema di reclami (art. 14), **meccanismo di segnalazione e azione** (art. 16: testo non recuperato).
- **Esenzione per micro e piccole imprese:** l'art. 19 le esonera dagli obblighi **aggiuntivi delle piattaforme online** (Sezione 3), non da quelli dell'hosting [estratto; da confermare sul testo]. Soglie della Raccomandazione 2003/361/CE: **micro** meno di 10 persone e fatturato o bilancio fino a 2 milioni di euro; **piccola** meno di 50 persone e fino a 10 milioni [L11]. Se uno sviluppatore persona fisica sia «impresa»: **NON VERIFICATO**.
- **Autorità italiana:** AGCOM (coordinatore dei servizi digitali) [L11]. Sanzioni fino al 6% del fatturato mondiale per i casi gravi: applicazione concreta a un micro operatore **NON VERIFICATA**.
- **Minori (art. 28):** linee guida della Commissione (14 luglio 2025, non vincolanti): non si applicano alle micro e piccole imprese [estratto].

## 6. Giochi, scommesse e premi

**Perché oggi le scommesse con KOIN finti non sembrano gioco d'azzardo.** Nessuna fonte trovata dice questa frase: è un'**inferenza dalle definizioni**.

- **Art. 721 c.p.:** giochi d'azzardo sono quelli in cui ricorre il **fine di lucro** e la vincita o perdita è interamente o quasi aleatoria [L12]. Senza fine di lucro manca un elemento (vigenza attuale non verificata).
- **ADM:** i «giochi pubblici» sono quelli con **vincita in denaro**, esercitabili solo con concessione [L13, estratto]; l'organizzazione a distanza senza concessione è reato (art. 4 L. 401/1989) [L14].
- **Premi non in denaro:** la competenza è del Ministero (MIMIT); ADM controlla solo che non si eluda il monopolio [estratto].
- **Limite:** non è stata trovata alcuna prassi ADM sui giochi simulati (social casino): resta **NON VERIFICATO** che un'autorità non qualifichi come illecito un gioco simulato che riproduce meccaniche d'azzardo. La conclusione regge perché oggi: nessuna posta in denaro, nessun premio in denaro, KOIN non convertibili. **Va riverificata a ogni modifica.**

**Se cambiasse qualcosa**

| Modifica | Effetto |
|---|---|
| **Premi reali** (buoni, denaro, merce) | Rientra nei concorsi/operazioni a premio (DPR 430/2001): promotore, comunicazione al Ministero, cauzione, regolamento; denaro e strumenti finanziari non ammessi come premio [L15, L16, estratto] |
| **Vendita di KOIN con denaro** | Valuta virtuale di gioco: regole consumeristiche (principi UE sulle valute virtuali, marzo 2025: prezzo chiaro, niente esortazioni dirette ai minori) [L17]; se i KOIN fossero convertibili in premi, perimetro del monopolio. **Nessuna fonte dice «lecito» o «illecito»: serve un parere legale prima di venderli.** Negli store: acquisti in-app (documento 03) |
| **Premi virtuali** (ruota, calendario) | Se rientrino nel DPR 430/2001: **NON VERIFICATO** (la norma riguarda premi promessi per scopi commerciali) |
| **Loot box** | In alcuni Stati UE sono considerate gioco d'azzardo [estratto]; posizione italiana (AGCM/ADM) **non trovata** |

## 7. Fisco e previdenza sui ricavi pubblicitari (persona fisica)

> Tutte le cifre sono da **far confermare a un commercialista**: vengono da estratti di fonti ufficiali e, per il 2026, da provvedimenti non riletti per intero.

- **Occasionale o abituale?** Attività svolta con «regolarità, sistematicità e ripetitività» → abituale: reddito di lavoro autonomo, **partita IVA** e fattura; occasionale: redditi diversi, fuori campo IVA [L22, estratto]. Pubblicità continuativa su un'app con molti utenti è, in linea di massima, abituale: valutazione del commercialista. Soglia dei 5.000 euro per l'occasionale e ritenute: **NON VERIFICATE**.
- **Regime forfettario** (L. 190/2014): ricavi dell'anno precedente fino a **85.000 euro**; uscita immediata se si superano **100.000** in corso d'anno; imposta sostitutiva **15%**, oppure **5% per i primi 5 anni** se si avvia una nuova attività con i requisiti di legge [L18, estratto]. **Coefficiente di redditività** e codice ATECO adatto (ATECO 2025 in vigore dal 1° aprile 2025 per gli adempimenti): **NON VERIFICATI**. Alcuni requisiti (limite di reddito da lavoro dipendente per il 2026, soglia delle spese) risultano in fonti discordanti: **parziale**.
- **Contributi INPS 2026:** Gestione Separata **26,07%** (circolare INPS n. 8 del 3 febbraio 2026) [L19]; commercianti **24,48%**, artigiani 24%, minimale 18.808 euro (circolare n. 14 del 9 febbraio 2026) [L20, estratto]. **Quale gestione si applichi** a chi incassa pubblicità e l'eventuale riduzione per i forfettari: **NON VERIFICATO**.
- **Incassi da Google:** i pagamenti sono effettuati da **Google Ireland Limited**; per AdSense/AdMob il servizio è soggetto a **reverse charge** (IVA a carico del committente) [L21, estratto; pagine datate]. Aliquota, natura IVA in fattura e ritenute per un publisher con partita IVA italiana: **NON VERIFICATI**. I forfettari usano di norma la natura «non soggetta» con riferimento alla L. 190/2014 [estratto, indicazioni generali AdE].
- **Con Altervista**, per i ricavi del *sito*, la controparte potrebbe essere **Altervista e non Google** (dopo l'attivazione dal pannello, Google riconosce ad Altervista una quota): **NON VERIFICATO**. AdMob dell'app è invece un contratto diretto con Google.

## 8. Altervista

[L23, estratto; versione inglese dei termini, quella italiana prevale e non è stata aperta]

- **Banner:** AdSense solo se collegato dal pannello Altervista; dopo l'attivazione Google riconosce ad Altervista una quota dei ricavi. La **percentuale** (si parla di 35% trattenuto) viene da post di utenti: **NON VERIFICATA** ufficialmente.
- **Pubblicità di terzi:** la wiki dice che non si può ospitare altra pubblicità oltre a quella di Altervista; un moderatore indica che il codice AdSense proprio non produce annunci. Il testo italiano dell'articolo sulla pubblicità **non è stato recuperato**.
- **Uso commerciale:** i termini parlano di non rivendere né fare «altro uso commerciale dei Servizi»; se valga per un'app con ricavi propri: **NON VERIFICATO**.
- **Risorse:** l'hosting standard indica 3 GB e 30 GB di traffico al mese [estratto]; limiti di CPU, database, cron, posta e sospensione per abuso **NON trovati**.
- **Conclusione:** per usare AdSense/AdMob propri e crescere serve **uscire da Altervista** (documento 02). Prima di qualsiasi decisione, leggere i termini italiani e chiedere conferma scritta ad Altervista.

## 9. Altri obblighi

| Tema | Cosa risulta | Stato |
|---|---|---|
| **Informazioni obbligatorie del sito** (D.Lgs. 70/2003, art. 7) | Dati del prestatore (nome o denominazione, sede, contatto, partita IVA ecc.) accessibili in modo «facile, diretto e permanente» | L'elenco esatto **NON VERIFICATO**: leggere l'art. 7 su Normattiva |
| **Termini d'uso e Codice del consumo** | Servono anche per la DSA (art. 14); recesso per contenuti digitali: non ricercato | **NON VERIFICATO** |
| **Marchio e dominio** | Il marchio richiede novità, capacità distintiva, liceità; ricerca di anteriorità consigliata; il nome «Calcetto Manager» può essere descrittivo | Nessuna ricerca di anteriorità fatta [L25, estratto] |
| **Accessibilità** (European Accessibility Act, D.Lgs. 82/2022) | Effetti dal 28 giugno 2025; microimprese (meno di 10 persone e fatturato o bilancio fino a 2 milioni) esentate per i servizi [L24, estratto] | Se si applichi a una persona fisica e a un'app gratuita: **NON VERIFICATO** |

## 10. Cose da fare (in ordine)

1. **Prima di tutto:** parere di un **avvocato/DPO** su ruoli (titolare, contitolari tra gestori di lega e piattaforma), basi giuridiche, minori, DPIA, contratti con i fornitori e condizioni d'uso. Un **commercialista** per partita IVA, regime, contributi e fatturazione verso Google/Altervista.
2. Scrivere: informativa privacy, informativa cookie, termini d'uso, procedura per i diritti e per i data breach, registro dei trattamenti.
3. Sviluppare nell'app: cancellazione account, segnalazione e blocco, verifica dell'età o consenso del genitore, banner di consenso (CMP certificata), pagina contatti.
4. Mantenere le scommesse **solo simulate**: nessuna vendita di KOIN, nessun premio reale; testi chiari nell'app. Ogni cambiamento va rivalutato.
5. Scegliere l'hosting in UE e firmare i contratti art. 28 con hosting e SMTP.
6. Per la pubblicità: aprire la partita IVA prima dei primi incassi e verificare Altervista/Google come controparte.
