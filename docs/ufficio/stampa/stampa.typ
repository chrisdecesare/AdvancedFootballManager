// Locandine e biglietti di Calcetto Manager (stile «album di figurine»). Si compila con stampa.py, che prepara QR, font e avatar.
#let ink = rgb("#1f1a2e")
#let yellow = rgb("#ffd23f")
#let paper = rgb("#fff6e0")
#let grass = rgb("#38d178")
#let grass2 = rgb("#33c26f")
#let sky = rgb("#53c8f5")
#let doc = sys.inputs.at("doc", default: "locandina")
#let site = sys.inputs.at("site", default: "calcettomanager.win")
#let play = sys.inputs.at("play", default: "")   // se c'è il link di Google Play, compare «Disponibile su Google Play»
#let display(body, size: 12pt, fill: ink) = text(font: "Lilita One", size: size, fill: fill, weight: "regular", body)
#let nun(body, size: 10pt, fill: ink, w: "bold") = text(font: "Nunito", size: size, fill: fill, weight: w, body)

// titolo bianco con contorno scuro: lo stesso testo due volte, quello col contorno sotto
#let outlined(body, size: 40pt, width: 130mm, line: 1mm) = block(width: width, {
  place(top + left, text(font: "Lilita One", size: size, fill: ink, stroke: (paint: ink, thickness: 2 * line, join: "round"), weight: "regular", body))
  text(font: "Lilita One", size: size, fill: white, weight: "regular", body)
})

#let qrbox(file, size, pad: 3mm) = box(fill: white, stroke: 1mm + yellow, radius: 4mm, inset: pad, image(file, width: size))

#let cardbox(title, text_, big: false) = box(width: 100%, fill: white, stroke: 0.9mm + ink, radius: 3.5mm, inset: (x: 4mm, y: 3.6mm), {
  display(title, size: if big { 18pt } else { 14pt })
  v(1.2mm)
  nun(text_, size: if big { 12pt } else { 9.5pt })
})

#let playline(size: 10pt, fill: white) = if play != "" { nun([Disponibile su *Google Play*], size: size, fill: fill, w: "black") } else { nun([Gratis per chi gioca · si apre dal browser, senza installare niente], size: size, fill: fill) }

// campo da calcio a strisce (parte alta della locandina)
#let pitch(w, h) = box(width: w, height: h, clip: true, {
  for i in range(0, 12) { place(top + left, dx: i * w / 11, rect(width: w / 11 + 0.3mm, height: h, fill: if calc.even(i) { grass } else { grass2 }, stroke: none)) }
  place(top + left, dx: 11mm, dy: 11mm, rect(width: w - 22mm, height: h - 22mm, fill: none, stroke: 1.4mm + white.transparentize(15%), radius: 2.5mm))
  place(top + center, dy: h - 11mm - 35mm, circle(radius: 35mm, fill: none, stroke: 1.4mm + white.transparentize(15%)))
})

#let hero(w, h, title: 41pt, av: 46mm) = block(width: w, height: h, {
  pitch(w, h)
  place(top + left, dx: 14mm, dy: 14mm, box(fill: yellow, stroke: 1.2mm + ink, radius: 5mm, inset: (x: 5mm, y: 3.5mm), display("Calcetto Manager", size: 26pt)))
  place(top + left, dx: 14mm, dy: 42mm, outlined([Chi viene stasera? \ Lo sa #text(fill: yellow)[l'app.]], size: title, width: 182mm))
  place(top + left, dx: 14mm, dy: h - 36mm, box(width: if title > 50pt { 92mm } else { 78mm }, fill: white, stroke: 0.9mm + ink, radius: 3mm, inset: 3.5mm,
    nun([Presenze, squadre equilibrate, voti e classifica per il tuo gruppo di calcetto. *Gratis* per chi gioca.], size: if title > 50pt { 13pt } else { 11pt }, w: "black")))
  place(bottom + left, dx: 96mm, dy: 0mm, image("av0.svg", width: av))
  place(bottom + left, dx: 96mm + av * 0.88, dy: 0mm, image("av1.svg", width: av))
})

#let features(big: false) = grid(columns: (1fr, 1fr, 1fr), column-gutter: 5mm,
  cardbox(big: big, "Chi c'è, chi no", "Ognuno risponde dal telefono con un tocco. Niente più messaggi nel gruppo."),
  cardbox(big: big, "Squadre giuste", "Due squadre equilibrate in base al livello dei giocatori."),
  cardbox(big: big, "Classifica e voti", "Gol, assist, voti tra amici, MVP e la Gazzetta della settimana."))

// ---- cartoline A6 orizzontali (148 x 105 mm): fronte e retro ----
// `off` = quanto spazio c'è attorno al taglio (abbondanza, e nella versione per la tipografia anche i crocini)
#let cart-front-core(off: 0mm, bleed: 0mm) = {
  let w = 148mm + 2 * off
  let h = 105mm + 2 * off
  block(width: w, height: h, {
    // il campo riempie tutta la pagina, anche l'abbondanza
    place(top + left, dx: off - bleed, dy: off - bleed, pitch(148mm + 2 * bleed, 105mm + 2 * bleed))
    place(top + left, dx: off + 9mm, dy: off + 8mm, box(fill: yellow, stroke: 1mm + ink, radius: 4mm, inset: (x: 3.5mm, y: 2mm),
      stack(dir: ltr, spacing: 2.5mm, image("logo.svg", width: 11mm), pad(top: 1.2mm, display("Calcetto Manager", size: 17pt)))))
    place(top + left, dx: off + 9mm, dy: off + 31mm, outlined([Chi viene stasera? \ Lo sa #text(fill: yellow)[l'app.]], size: 33pt, width: 100mm, line: 0.7mm))
    place(bottom + left, dx: off + 82mm, dy: -(off + 25mm - 0.5mm), image("av0.svg", width: 30mm))
    place(bottom + left, dx: off + 82mm + 26mm, dy: -(off + 25mm - 0.5mm), image("av1.svg", width: 30mm))
    // fascia in basso con il QR
    place(bottom + left, dx: off - bleed, dy: -(off - bleed), rect(width: 148mm + 2 * bleed, height: 25mm + bleed, fill: ink, stroke: none))
    place(bottom + left, dx: off - bleed, dy: -(off + 25mm), rect(width: 148mm + 2 * bleed, height: 1.6mm, fill: yellow, stroke: none))
    place(bottom + left, dx: off + 9mm, dy: -(off + 5mm), qrbox("qr-cartolina.svg", 20mm, pad: 1.6mm))
    place(bottom + left, dx: off + 42mm, dy: -(off + 7mm), block(width: 100mm, {
      display("Inquadra e inizia.", size: 18pt, fill: yellow)
      linebreak()
      nun(site, size: 11pt, fill: white, w: "black")
      linebreak()
      playline(size: 8.5pt)
    }))
  })
}
#let cart-retro-core(off: 0mm, bleed: 0mm) = {
  let w = 148mm + 2 * off
  let h = 105mm + 2 * off
  block(width: w, height: h, {
    place(top + left, dx: off - bleed, dy: off - bleed, rect(width: 148mm + 2 * bleed, height: 105mm + 2 * bleed, fill: paper, stroke: none))
    place(top + left, dx: off - bleed, dy: off - bleed, rect(width: 148mm + 2 * bleed, height: 7mm + bleed, fill: yellow, stroke: none))
    place(top + left, dx: off + 10mm, dy: off + 14mm, block(width: 90mm, {
      display("Il calcetto tra amici,", size: 21pt)
      linebreak()
      display("organizzato.", size: 21pt)
      v(5mm)
      for (t1, t2) in (("Chi c'è, chi no", "Ognuno risponde con un tocco dal telefono."), ("Squadre giuste", "Due squadre equilibrate per livello."), ("Classifica e voti", "Gol, assist, voti tra amici, MVP e Gazzetta.")) {
        grid(columns: (6mm, 1fr), column-gutter: 2.5mm, box(width: 5mm, height: 5mm, fill: grass, stroke: 0.7mm + ink, radius: 1.2mm, align(center + horizon, nun([✓], size: 9pt, w: "black"))), block(below: 4mm, {
          display(t1, size: 13pt); linebreak(); nun(t2, size: 9.5pt)
        }))
      }
    }))
    place(top + right, dx: -(off + 10mm), dy: off + 14mm, block(width: 40mm, align(center, {
      qrbox("qr-cartolina.svg", 30mm, pad: 2mm)
      v(1.5mm)
      nun(site, size: 10pt, w: "black")
      linebreak()
      playline(size: 7.5pt, fill: ink)
    })))
    place(bottom + left, dx: off - bleed, dy: -(off - bleed), rect(width: 148mm + 2 * bleed, height: 7mm + bleed, fill: ink, stroke: none))
    place(bottom + left, dx: off + 10mm, dy: -(off + 2mm), nun([Calcetto Manager · nessuna commissione sulle partite né sulle quote], size: 7pt, fill: white))
  })
}

// cornice da figurina: bordo scuro di 3 mm, angoli interni arrotondati, taglio dritto
#let cart-framed(core, off, bleed) = block(width: 148mm + 2 * off, height: 105mm + 2 * off, {
  place(top + left, dx: off - bleed, dy: off - bleed, rect(width: 148mm + 2 * bleed, height: 105mm + 2 * bleed, fill: ink, stroke: none))
  place(top + left, dx: off + 3mm, dy: off + 3mm, block(width: 142mm, height: 99mm, radius: 4mm, clip: true, core(off: -3mm, bleed: 0mm)))
})
#let cart-front(off: 0mm, bleed: 0mm) = cart-framed(cart-front-core, off, bleed)
#let cart-retro(off: 0mm, bleed: 0mm) = cart-framed(cart-retro-core, off, bleed)

#if doc == "locandina" {
  set page(width: 210mm, height: 297mm, margin: 0pt, fill: paper)
  hero(210mm, 160mm, title: 58pt, av: 56mm)
  pad(x: 14mm, top: 10mm, features(big: true))
  place(bottom + left, block(width: 210mm, height: 70mm, fill: ink, {
    place(top + left, rect(width: 100%, height: 2.4mm, fill: yellow, stroke: none))
    place(left + horizon, dx: 14mm, dy: 1mm, qrbox("qr-locandina.svg", 46mm))
    place(left + horizon, dx: 78mm, dy: 1mm, block(width: 120mm, {
      display("Inquadra e inizia.", size: 34pt, fill: yellow)
      v(1.5mm)
      nun(site, size: 17pt, fill: white, w: "black")
      v(2mm)
      playline(size: 11pt)
    }))
  }))
} else if doc == "cartoline-tipografia" {
  // per la tipografia: pagina 170 x 127 mm, taglio a 11 mm dai bordi, abbondanza 3 mm, crocini fuori dall'abbondanza
  set page(width: 170mm, height: 127mm, margin: 0pt, fill: white)
  let marks = {
    for x in (11mm, 159mm) { for (y0, y1) in ((0.8mm, 6mm), (121mm, 126.2mm)) {
      place(top + left, dx: x, dy: y0, line(length: y1 - y0, angle: 90deg, stroke: 0.25pt + black)) } }
    for y in (11mm, 116mm) { for (x0, x1) in ((0.8mm, 6mm), (164mm, 169.2mm)) {
      place(top + left, dx: x0, dy: y, line(length: x1 - x0, stroke: 0.25pt + black)) } }
  }
  cart-front(off: 11mm, bleed: 3mm)
  marks
  pagebreak()
  cart-retro(off: 11mm, bleed: 3mm)
  marks
} else if doc == "cartoline-casa" {
  // per la stampante di casa: A4 orizzontale, 4 cartoline (fronte) ridotte al 94%, con linee di taglio
  set page(width: 297mm, height: 210mm, margin: 0pt, fill: white)
  let cw = 148mm * 0.94
  let ch = 105mm * 0.94
  let cell = box(width: cw, height: ch, clip: true, scale(94%, origin: top + left, reflow: false, cart-front()))
  place(top + left, dx: (297mm - 2 * cw) / 2, dy: (210mm - 2 * ch) / 2, grid(columns: (cw, cw), rows: (ch, ch), cell, cell, cell, cell))
  place(top + left, dx: 148.5mm, dy: 3mm, line(length: 204mm, angle: 90deg, stroke: (paint: black, thickness: 0.25pt, dash: "dashed")))
  place(top + left, dx: 3mm, dy: 105mm, line(length: 291mm, stroke: (paint: black, thickness: 0.25pt, dash: "dashed")))
} else if doc == "strappi" {
  set page(width: 210mm, height: 297mm, margin: 0pt, fill: paper)
  hero(210mm, 150mm, title: 50pt, av: 50mm)
  pad(x: 14mm, top: 8mm, features(big: false))
  place(top + left, dx: 14mm, dy: 214mm, block(width: 182mm, {
    display("Inquadra il QR oppure strappa una linguetta.", size: 17pt)
    v(1mm)
    nun(site, size: 12pt, w: "black")
    h(3mm)
    playline(fill: ink, size: 10pt)
  }))
  // 8 linguette da strappare: QR e indirizzo in verticale
  place(bottom + left, grid(columns: 8 * (210mm / 8,), rows: 55mm,
    ..range(8).map(i => block(width: 210mm / 8, height: 55mm, stroke: (left: if i > 0 { (paint: ink, thickness: 0.3pt, dash: "dashed") } else { none }, top: (paint: ink, thickness: 0.3pt, dash: "dashed")), fill: if calc.even(i) { white } else { yellow.lighten(60%) }, {
      align(center, pad(top: 3mm, stack(dir: ttb, spacing: 2.5mm, image("qr-strappo.svg", width: 22mm),
        rotate(-90deg, reflow: true, nun(site, size: 8pt, w: "black")))))
    }))))
} else {
  // 10 biglietti 85 x 55 mm su un A4, con linee di taglio
  set page(width: 210mm, height: 297mm, margin: 0pt, fill: white)
  let inner = block(width: 85mm - 3.6mm, height: 55mm - 3.6mm, fill: yellow, radius: 3mm, clip: true, {
    place(bottom + left, rect(width: 100%, height: 12mm, fill: grass, stroke: none))
    // logo: pallone + nome
    place(top + left, dx: 5mm, dy: 4.5mm, stack(dir: ltr, spacing: 2mm, image("logo.svg", width: 13mm), pad(top: 2mm, display("Calcetto\nManager", size: 12pt))))
    // il motto
    place(top + left, dx: 5mm, dy: 21.5mm, block(width: 47mm, {
      display("Chi viene stasera?", size: 14.5pt)
      linebreak()
      box(fill: white, stroke: 0.7mm + ink, radius: 1.6mm, inset: (x: 1.8mm, y: 0.6mm), outset: (y: 0.4mm), display("Lo sa l'app.", size: 17pt))
    }))
    place(top + right, dx: -5mm, dy: 4mm, qrbox("qr-biglietto.svg", 26mm, pad: 2mm))
    place(top + right, dx: -5mm, dy: 37.5mm, box(width: 30mm, align(center, nun([Inquadra e inizia], size: 7.5pt, w: "black"))))
    place(bottom + left, dx: 5mm, dy: -3.4mm, nun(site, size: 9.5pt, fill: ink, w: "black"))
    place(bottom + right, dx: -5mm, dy: -3.6mm, if play != "" { nun([Disponibile su Google Play], size: 7pt, w: "black") } else { nun([Gratis per chi gioca], size: 7.5pt, w: "bold") })
  })
  // cornice da figurina: bordo scuro di 1,8 mm, angoli interni arrotondati, taglio dritto
  let card = block(width: 85mm, height: 55mm, fill: ink, inset: 1.8mm, inner)
  place(top + left, dx: 20mm, dy: 11mm, grid(columns: (85mm, 85mm), rows: 5 * (55mm,), ..range(10).map(_ => card)))
  // linee di taglio fuori dai biglietti (corte) a ogni incrocio
  for c in (0, 1, 2) { for r in range(0, 6) {
    place(top + left, dx: 20mm + c * 85mm - 0.15mm, dy: 11mm + r * 55mm - 0.15mm, {
      place(dx: -4mm, dy: 0mm, line(length: 3mm, stroke: 0.25pt + black))
      place(dx: 0mm, dy: -4mm, line(length: 3mm, angle: 90deg, stroke: 0.25pt + black))
    })
  } }
}
