# Product Marketing Context

**Document version:** v1 (bozza ricavata dal codice e dai documenti del repo, da correggere)
**Last updated:** 2026-10-18

> Tutto ciò che è segnato **[NEED: …]** non è noto e va compilato da chi gestisce il prodotto. Nessun dato di utenti o risultati è dichiarato: non ce ne sono di verificati.

## Product Overview
**One-liner:** L'app che organizza il calcetto tra amici: presenze, squadre equilibrate, voti e classifica.
**What it does:** Ogni giocatore conferma dal telefono; l'app manda i promemoria, forma due squadre equilibrate, tiene risultati, voti, MVP e classifica, e ogni settimana racconta la partita con la Gazzetta. Si gioca anche con KOIN finti (scommesse simulate, negozio di oggetti virtuali, Fanta) e con avatar in pixel art.
**Product category:** App per organizzare gruppi di calcetto e calcio amatoriale (gestione partite + gioco di gruppo).
**Product type:** Sito/PWA (PHP) con notifiche push; app per gli store in preparazione.
**Business model:** Oggi gratuito, senza pubblicità. Ipotesi allo studio (docs/ufficio): premium per lega, servizi per i centri sportivi, pubblicità, cosmetici in euro. **I KOIN non si vendono.** [NEED: decisione sui piani e sui prezzi]

## Target Audience
**Target:** Gruppi di amici o colleghi che giocano a calcetto con regolarità (tipicamente una volta a settimana), in Italia.
**Decision-makers:** L'organizzatore o il capitano del gruppo (chi oggi tiene le chat e i fogli).
**Primary use case:** Mettere ordine in chi c'è, chi no, le squadre e il risultato, senza una chat infinita.
**Jobs to be done:**
- Sapere in tempo chi viene alla partita.
- Fare squadre che non si lamentano.
- Tenere viva la rivalità del gruppo (classifica, voti, MVP, sfottò).
**Use cases:**
- Una lega con 10–14 giocatori che gioca ogni settimana nello stesso campo.
- Gruppi aziendali o di circolo con tornei interni.
- Centri sportivi che vogliono tenere unite le leghe che giocano da loro. [NEED: parlare con un gestore prima di promettere]

## Personas
| Persona | Cares about | Challenge | Value we promise |
|---------|-------------|-----------|------------------|
| Organizzatore/capitano | Partite che non saltano; fare le squadre in 2 minuti | Rincorrere le risposte in chat, buchi dell'ultimo minuto | Presenze con promemoria e squadre equilibrate |
| Giocatore | Giocare, sfottere, vedere il proprio nome in classifica | Non sapere chi c'è; discussioni sulle squadre | Voti, MVP, classifica, Gazzetta, avatar |
| Gestore del campo | Campi pieni, gruppi che tornano | Gruppi che spariscono, prenotazioni all'ultimo | [NEED: da verificare con un gestore] |

## Problems & Pain Points
**Core problem:** Organizzare il calcetto con chat e fogli sparsi costa tempo e discussioni.
**Why alternatives fall short:**
- Le chat di gruppo non ricordano chi ha confermato, non fanno squadre né classifica.
- Le app generiche di organizzazione non hanno la parte «di gruppo» (voti, MVP, rivalità).
**What it costs them:** Tempo di chi organizza e partite saltate o con squadre sbilanciate. [NEED: dati reali]
**Emotional tension:** La paura del buco dell'ultimo minuto; la discussione su chi ha giocato meglio.

## Competitive Landscape
**Direct:** altre app per il calcetto (Fubles, Goalcup, SquadraPiù, SportEasy, Mr. Calcetto, Ten App; fonti di terzi nel documento 05 del repo): [NEED: analisi diretta di funzioni e prezzi].
**Secondary:** App di gestione squadre non specifiche per il calcetto.
**Indirect:** Chat WhatsApp + foglio di calcolo.

## Differentiation
**Key differentiators:**
- Squadre equilibrate dall'app in base al livello.
- Avatar in pixel art con esultanze, KOIN finti, Fanta e Gazzetta: l'app è anche un gioco di gruppo.
- Gratis per i giocatori. [NEED: confermare dopo la scelta dei piani]
**Why customers choose us:** [NEED: parole dei giocatori]

## Objections
| Objection | Response |
|-----------|----------|
| «Basta WhatsApp» | WhatsApp non tiene presenze, squadre e classifica; qui si risponde con un tocco e ricevi i promemoria |
| «Una app in più da installare» | Si usa dal browser, senza scaricare nulla [NEED: confermare per gli store] |
| «Le scommesse?» | KOIN finti, nessun denaro e nessun premio reale |

**Anti-persona:** Chi cerca prenotazione di campi o pagamenti online come funzione principale (oggi non c'è) e chi vuole scommesse con denaro vero.

## Switching Dynamics
**Push:** Chat infinita, buchi a ultimo minuto, squadre contestate.
**Pull:** Una schermata che dice chi c'è; classifica e Gazzetta che fanno parlare il gruppo.
**Habit:** «Si è sempre fatto con WhatsApp».
**Anxiety:** Convincere tutto il gruppo a usarla; i dati dei giocatori.

## Customer Language
**How customers describe the problem:** [NEED: parole vere dei giocatori; es. «chi c'è giovedì?», «siamo in 9?»]
**Words to use:** squadre, classifica, MVP, Gazzetta, lega, giocatori, partita.
**Words to avoid:** «scommesse» da sole (specificare «KOIN finti»); «gioco d'azzardo»; promesse su funzioni non ancora disponibili (pagina del centro, tornei).
**Glossary:** KOIN = valuta finta dell'app; Gazzetta = riepilogo della settimana; lega = gruppo di giocatori.

## Brand Voice
- **Diretta e da spogliatoio:** frasi corte, ironia leggera, niente gergo aziendale. *Esempio dal prodotto:* «Chi non risica...» (messaggio delle multiple).
- **Goliardica ma onesta:** si scherza sui KOIN, non su soldi veri.
- **Chiara prima che furba:** una promessa per volta.
**Tono nei contesti:** lancio = energico; errori = semplice e rassicurante; pagamenti e privacy = serio e preciso.
**Colori e stile:** giallo #ffd23f, inchiostro #1f1a2e, verde campo; pixel art; font Lilita One + Nunito.

## Proof Points
- Funzioni già presenti nell'app (presenze, squadre, risultati, voti, MVP, classifica, Gazzetta, avatar, notifiche push).
- **Numeri di utenti, leghe e partite:** [NEED: non verificati, non citare].

## Goals
**Primary business goal:** Crescere per leghe attive (non per installazioni). [NEED: obiettivi numerici concordati, vedi docs/ufficio/06]
**Key conversion action:** Creare una lega e invitare il gruppo con il link.
**Current metrics:** [NEED]

## Learnings
(vuoto)
