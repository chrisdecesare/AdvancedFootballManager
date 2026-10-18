# Locandina A4 per i centri sportivi

1. Scrivi l'indirizzo del sito e i contatti in `poster.json` (finché ci sono i segnaposto compare il timbro BOZZA e il file non va stampato).
2. `python3 poster.py` (serve `pip install segno pypdf`, Node con Playwright e Chromium; gli avatar sono in `avatars.json`, si rigenerano con `php avatars.php` dalla radice del repository).
3. Escono due PDF:
   - `locandina-A4-casa.pdf`: A4 per la stampante di casa o d'ufficio, con un piccolo bordo bianco di sicurezza.
   - `locandina-A4-tipografia.pdf`: pagina 226 × 313 mm, con 3 mm di abbondanza e crocini di taglio; TrimBox 210 × 297 mm e BleedBox impostati. Colori in RGB: chiedi alla tipografia la conversione in CMYK.

Font: Lilita One e Nunito (licenza OFL, in `fonts/`). Il QR code è generato con correzione d'errore alta.
