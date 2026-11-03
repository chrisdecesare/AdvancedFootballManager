# Locandine e biglietti con QR code

`python3 stampa.py` (serve `pip install segno==1.6.6 fonttools==4.55.3 brotli==1.1.0` e `typst`) scrive in `uscita/`:

| File | Cosa è | Come stamparlo |
|---|---|---|
| `locandina-A4.pdf` | Locandina per i giocatori | A4, «dimensione reale» (non «adatta alla pagina»), margini 0 se la stampante lo permette |
| `locandina-strappi-A4.pdf` | Locandina con 8 linguette da strappare, ognuna con il suo QR | A4, poi taglia solo le linee tratteggiate verticali in basso (non il foglio) |
| `biglietti-foglio-A4.pdf` | 10 biglietti da 85 × 55 mm con logo, motto e QR | A4, taglia lungo i crocini; carta da 200-300 g/m² |
| `cartoline-A6-tipografia.pdf` | **Cartolina A6 orizzontale** 148 × 105 mm, 2 pagine (fronte e retro), 3 mm di abbondanza e crocini | Per la tipografia: chiedi stampa fronte-retro, carta 300 g/m², conversione in CMYK |
| `cartoline-foglio-A4-casa.pdf` | 4 cartoline (solo fronte, ridotte al 94%) su un A4 orizzontale | Stampante di casa, «dimensione reale»; taglia lungo le linee tratteggiate |

L'indirizzo e il link di Google Play stanno in `stampa.json`. Quando l'app è su Google Play, scrivi il link in `google_play` e rigenera: compare «Disponibile su Google Play» al posto di «si apre dal browser».

**Il QR cambia per formato**: `?utm_source=locandina`, `strappo`, `biglietto` o `cartolina`. Nelle statistiche del sito (Vercel Analytics, Google Analytics se c'è il consenso) si vede quale funziona di più. Tutti e tre sono stati letti da un decodificatore a 200 dpi dai PDF finali.

**Prima di stampare:**
- Il QR porta al sito (oggi la PWA). Quando l'app è su Google Play, un QR che porta alla scheda dello store convertirà meglio sui telefoni Android: rigenera e ristampa.
- **Dove appendere:** in molti comuni l'affissione fuori dagli spazi autorizzati è sanzionata (regole del singolo Comune: **non verificate**). Chiedi il permesso ai centri sportivi, ai bar, alle palestre e alle bacheche dell'università, o usa gli spazi di affissione comunali.
- Stampa dalla stampante: colori RGB. Per una tipografia chiedi la conversione in CMYK; il formato con abbondanza e crocini esiste per la locandina del centro in `../poster/`, qui non c'è.
- I due personaggi sono gli avatar dell'app (`../poster/avatars.json`); font Lilita One e Nunito (licenza OFL).
