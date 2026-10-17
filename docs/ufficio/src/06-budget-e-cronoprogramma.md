---
title: 06 · Budget e cronoprogramma
subtitle: Costi per fase, scenario minimo e completo, tappe e criteri di uscita
toc: yes
---

> **Come leggere i numeri.** Le **spese in denaro** sono quelle dei documenti 02–05, con la stessa avvertenza: prezzi **indicativi** da riverificare. Il **lavoro** è espresso in **giornate** (stime interne del piano tecnico, non verificate); **non è stato valorizzato in euro** perché la tariffa interna non è nota. **Mancano preventivi** per consulenza legale e fiscale: non si è inventata nessuna cifra. Consultazione prezzi: **17 ottobre 2026**.

## 1. Cosa si chiede

Si chiede un budget di **prova** a fasi, con punti di decisione: ogni fase si può fermare senza lasciare nulla a metà, e il sito continua a funzionare.

| Scenario | Cosa comprende | Spesa in denaro, primo anno (indicativa) | Lavoro |
|---|---|---|---|
| **A · Minimo** | Trasloco su server proprio (fasi 0 e 1), dominio, posta e backup; app **Android** (TWA o Capacitor), requisiti privacy minimi | circa **90–310 €** + 25 USD (Google Play) | circa **12–17 giornate** (stima nostra, vedi paragrafo 3) |
| **B · Completo con app** | A + app **iPhone** (Capacitor), notifiche push native, cancellazione account e moderazione, parere legale e fiscale, pubblicità | A + 99 USD/anno (Apple) + Mac (prezzo non verificato) + **preventivi legali e fiscali da richiedere** | **22–33 giornate** (stima nostra, vedi paragrafo 3) |
| **C · Next.js** | B + migrazione graduale a Next.js (fasi 2–8) | B + **nessun costo di licenza**; il server resta lo stesso | **~50–55 giornate** in più (piano interno) |

## 2. Spese in denaro (dettaglio, primo anno)

| Voce | Importo | Stato verifica |
|---|---|---|
| Server VPS (4 vCPU / 8 GB, scala 1.000–10.000 utenti) | circa **5–12 €/mese** → 60–144 €/anno, **IVA esclusa** dove indicato; Aruba (dati in Italia) circa 11,79 € + IVA | Estratti di pagine dei provider [H1–H6]; da riverificare. A 50.000 utenti: 20–40 € più un server per il database (stima nostra) |
| Dominio proprio | circa 20 €/anno | Fonte non ufficiale |
| Deposito dei backup | circa 1–5 €/mese → 12–60 €/anno | Stime di terzi [T-B] |
| Posta transazionale (SMTP) | 0 € (piani gratuiti) o 9 $ al mese oltre 300 email/giorno | [H15–H18, estratto] |
| Monitoraggio | 0 € | [H13, H14] |
| Account Google Play | **25 USD una tantum** | [GP1, estratto] |
| Account Apple Developer (scenario B) | **99 USD/anno** | [AP1] |
| Mac per compilare l'app iPhone (scenario B) | **NON VERIFICATO**; alternativa: Xcode Cloud (25 ore al mese incluse nell'iscrizione) | [AP1] |
| Notifiche push (Firebase FCM), AdMob | prezzi **non controllati** | NON VERIFICATO |
| Consulenza legale (avvocato/DPO) e fiscale (commercialista) | **da preventivare** | Nessun prezzo trovato |
| **Totale scenario A** | **circa 92–308 € + 25 USD** | Somma di server, dominio, backup e posta; indicativo |
| Lavoro | Non valorizzato | |

**Ricavi attesi** (documento 05): con 10.000 utenti attivi, da circa 24 $ al mese (app, prudente) a circa 3.600 € (web, ottimistico). Nello scenario medio **coprono il costo del server e poco più**: la pubblicità non è, da sola, la ragione dell'investimento. L'obiettivo della prova è dimostrare **crescita e retention**.

## 3. Lavoro per fase (giornate)

Basato sul piano tecnico interno (`docs/piano-migrazione-nextjs.md`); **stime nostre**, con un assistente AI che scrive gran parte del codice.

| Fase | Contenuto | Giornate | Scenario |
|---|---|---|---|
| 0 | Preparazione: inventario, test di riferimento, copia anonimizzata del database | 2–3 | A |
| 1 | Nuovo server: VPS, Docker, HTTPS, SMTP, backup, copia dati, deploy automatico | 3 | A |
| S | **Requisiti per gli store e la privacy:** cancellazione account in app, segnalazione e blocco, pagine informativa/termini/contatti, banner di consenso, età | 4–6 | A (minimi), B |
| M-Android | Progetto TWA o Capacitor, icone e schede dello store, test chiuso con 12 tester per 14 giorni, produzione | 3–5 (più 14 giorni di attesa) | A |
| M-iOS | Capacitor iOS, notifiche push native (FCM/APNs), schermate, revisione Apple | 6–10 | B |
| P | Pubblicità: CMP certificata, AdSense/AdMob, pagine pubbliche con contenuti | 4–6 | B |
| 2–8 | Migrazione a Next.js | ~50–55 | C |

La somma dello scenario A (fasi 0, 1, S, M-Android) è **12–17 giornate**; per B si aggiungono M-iOS e P (**+10–16**).

## 4. Cronoprogramma (indicativo)

| Periodo | Attività | Si chiude quando |
|---|---|---|
| **Mese 1** | Decisioni e budget; fasi 0 e 1 (trasloco su VPS); avvio parere legale (avvocato/DPO) e commercialista | Il sito gira sul nuovo server, identico; Altervista resta spento ma intatto qualche settimana |
| **Mese 2** | Fase S (requisiti privacy e store); reclutamento di 12 tester; app Android in test chiuso | 14 giorni consecutivi di test chiuso completati |
| **Mese 3** | Produzione Android; avvio iPhone (se approvato lo scenario B); misura di leghe, attivazione e retention | App Android pubblicata; primi dati reali |
| **Mese 4** | Decisione su pubblicità e premium con i dati; pubblicazione iPhone | Decisione scritta su pubblicità/premium |
| **Mesi 5–12** | Solo se approvato lo scenario C: migrazione graduale a Next.js | Una settimana solo con Next.js, senza errori |

**Margini:** la revisione Google richiede in genere da poche ore a 7 giorni [GP10], quella Apple meno di 48 ore per il 90% delle app [AP4]; prevedere comunque margine per rifiuti e correzioni.

## 5. Punti di decisione e criteri di uscita

| Punto | Domanda | Se no |
|---|---|---|
| **Dopo la fase 1** | Il sito sul nuovo server è identico e stabile? | Si torna ad Altervista (intatto): perdita limitata a pochi euro e alle giornate |
| **Dopo il test chiuso Android** | Si sono raggiunti 12 tester per 14 giorni e l'app ha superato la revisione? | Si corregge e si ripete; nessun costo aggiuntivo oltre ai 25 USD |
| **Prima della pubblicità** | Ci sono dati su utenti attivi e retention? Il parere legale conferma le regole? | Si resta senza annunci e si valuta premium/sponsor |
| **Prima di iPhone** | La revisione Apple (4.2) e il rischio «scommesse» sono accettabili? | Si resta su Android e PWA su iPhone |
| **Prima di Next.js** | Gli utenti crescono abbastanza da giustificare ~50 giornate? | Si migliora il PHP (tipi, test, SMTP) con una frazione del lavoro [piano interno] |

**Punti di non ritorno:** praticamente nessuno prima di Next.js (fasi 2–8). L'unica azione difficile da annullare è la **pubblicazione su Apple**, che impegna 99 USD all'anno e un'eventuale rimozione; la vendita di KOIN **non** è prevista e richiede un parere legale a parte.

## 6. Obiettivi proposti per la prova (da concordare)

Sono **proposte nostre**, non dati: il valore reale va deciso con l'ufficio.

| Indicatore | Proposta | Perché |
|---|---|---|
| Leghe attive create da persone nuove | Un numero minimo da fissare (es. alcune decine entro 3 mesi) | Misura l'attrazione del prodotto |
| Giocatori per lega | Almeno 8–10 | A 5 contro 5 serve una lega «piena» |
| Retention a 30 giorni | Confronto con i riferimenti di terzi (D30 circa 5–7% per le app in generale [T5]) | Una lega che continua a giocare è il successo vero |
| Tempo di disponibilità del servizio | Almeno il 99% (monitor gratuito) | Cron e notifiche puntuali |
| Spesa ricorrente | Entro 18 €/mese per la parte server (scenario A) | Contenere il rischio |

## 7. Rischi principali

| Rischio | Impatto | Contromisura |
|---|---|---|
| Apple respinge l'app (regola 4.2 «sito riconfezionato», gioco simulato) | Ritardi di giorni o settimane | Funzioni native vere, note di revisione chiare, account demo, prima Android |
| Parere legale sfavorevole su minori o scommesse simulate | Modifiche alle funzioni | Parere **prima** delle app e della pubblicità; KOIN senza valore reale |
| Approvazione AdSense negata o ricavi molto bassi | Nessun ritorno diretto | La pubblicità non è la base del business case; alternative premium/sponsor |
| Sicurezza e dati dei minori | Sanzioni e danno d'immagine | Verifica dell'età, informative, cancellazione, backup, contratti art. 28 |
| Dipendenza dal lavoro di una sola persona | Ritardi | Documentazione, deploy automatico, immagini Docker portabili |
| Costi del server superiori | Pochi euro al mese | Fatturazione a canone fisso; piani gratuiti per posta e monitor |

## 8. Cosa non si è potuto verificare

- Preventivi legali, fiscali e di sviluppo esterno; prezzo di un Mac; costi Firebase e AdMob.
- Prezzi ufficiali aggiornati e IVA dei provider; disponibilità dei piani Hetzner.
- Le giornate di lavoro sono **stime interne** non misurate; le percentuali di ricavo sono **ipotesi** (documento 05).
