# Copertine e testi di marketing

Materiali di marketing di Calcetto Manager, fatti con le skill di [coreyhaines31/marketingskills](https://github.com/coreyhaines31/marketingskills) (licenza MIT, copiate in `.claude/skills/`) e con il contesto di prodotto in `.agents/product-marketing.md`.

## Cosa c'è

| File | Formato | Uso |
|---|---|---|
| `copertine/facebook-copertina-1640x624.png` | 1640×624 | Copertina di pagina Facebook (le parti importanti stanno al centro: sui telefoni i lati si tagliano) |
| `copertine/quadrato-1080x1080.png` | 1080×1080 | Post Instagram, Facebook, WhatsApp |
| `copertine/storia-1080x1920.png` | 1080×1920 | Storie Instagram/Facebook, stato WhatsApp (zona sicura: niente testo nei primi e ultimi 250 px) |
| `copertine/google-play-immagine-1024x500.png` | 1024×500 | Immagine in primo piano della scheda Google Play |
| `copertine/copertina-fascicolo-A4.pdf` | A4 | Copertina dei documenti per l'ufficio |
| `copertine/fascicolo-ufficio-completo.pdf` | A4 | Copertina + documenti 01–09 in un solo PDF con segnalibri |
| `../ufficio/poster/locandina-A4-*.pdf` | A4 | Locandina per i centri sportivi (BOZZA finché mancano indirizzo e contatti) |

Si rigenerano con `python3 covers.py` (serve Node con Playwright; gli avatar vengono da `../ufficio/poster/avatars.php`).

## Come sono scritti i testi (skill copywriting)

- **Una promessa, una scena**: «Chi viene stasera? Lo sa l'app.» è la domanda che il gruppo si fa ogni settimana, e la risposta è il prodotto.
- **Prova delle tre domande**: si vede la scena (la chat, la domanda); è verificabile (l'app mostra chi ha confermato); la frase non è intercambiabile con quella di un'app qualunque.
- **Solo cose vere oggi**: presenze, promemoria, squadre equilibrate, voti, MVP, classifica, Gazzetta, avatar, Fanta, KOIN finti. **Niente numeri di utenti, niente risultati inventati, niente funzioni non ancora fatte** (pagina del centro, tornei, prenotazione).
- **Un solo invito all'azione** per immagine: «Crea la tua lega gratis» sui social; QR sulla locandina.
- **Da evitare**: la parola «scommesse» da sola (si dice «KOIN finti»), promesse di soldi, claim senza dati.

## Testi per gli store (bozza da verificare con gli store)

Limiti dalla skill ASO (`.claude/skills/aso/references/`); i conteggi sono caratteri (byte per le parole chiave).

| Campo | Testo | Lunghezza |
|---|---|---|
| Titolo Google Play (max 30) | Calcetto Manager: squadre | 25 |
| Descrizione breve Google Play (max 80) | Chi viene stasera? Presenze, squadre e classifica per il tuo calcetto. | 70 |
| Sottotitolo App Store (max 30) | Presenze, squadre, classifica | 29 |
| Testo promozionale App Store (max 170) | Chi viene stasera? Conferma dal telefono, ricevi il promemoria, fai squadre equilibrate e tieni la classifica del tuo gruppo. Gratis per i giocatori. | 149 |
| Parole chiave App Store (max 100 byte) | calcetto,calcio,partite,squadre,classifica,fanta,amici,formazioni,presenze,organizza,gruppo | 91 |

**Descrizione lunga (bozza):**

```
Chi viene stasera? Lo sa l'app.

Calcetto Manager organizza il calcetto tra amici al posto delle chat infinite:
• Presenze con promemoria: ognuno conferma dal telefono, chi non risponde viene avvisato.
• Squadre equilibrate: l'app forma due squadre in base al livello dei giocatori.
• Risultati, voti e MVP: gol, assist, voti dopo la partita e classifica del gruppo.
• La Gazzetta della settimana: il racconto delle partite, con le curiosità dello spogliatoio.
• Avatar in pixel art con le esultanze, per dare un volto a ogni giocatore.
• Il Fanta e i KOIN: un gioco di gruppo con monete finte. Nessun denaro vero, nessun premio in denaro.

Gratis per i giocatori. Crea la tua lega e invita il gruppo con un link.
```

Prima di pubblicare: dichiarare nei questionari degli store che i KOIN sono finti e non convertibili, e verificare la classificazione per età (documento 03 in `docs/ufficio`).

## Da fare quando ci sono i dati

- Indirizzo del sito, nome, telefono ed email per la locandina (`../ufficio/poster/poster.json`).
- Schermate dell'app per le schede degli store (le prime tre sono quelle che si vedono nella ricerca).
- Parole vere dei giocatori per il testo (campo **Customer Language** in `.agents/product-marketing.md`).
