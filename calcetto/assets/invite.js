// La cartolina d'invito (invito.php): stesso disegno della versione Next.js (components/InviteCard.tsx). SVG 900x560 con il QR della pagina di accesso;
// username e password sono scritti sulla cartolina, mai nel QR. Condividi (WhatsApp come immagine), scarica PNG, copia il testo.
(function () {
  var box = document.getElementById('invite-card');
  if (!box || typeof window.qrcode !== 'function') return;
  var d = JSON.parse(box.getAttribute('data-invite'));
  var ink = '#1f1a2e', paper = '#fff6e0', yellow = '#ffd23f', green = '#38d178', muted = '#6d6585';
  var esc = function (s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); };
  var cut = function (s, n) { var a = Array.from(String(s)); return a.length > n ? a.slice(0, n - 1).join('') + '…' : String(s); };

  var qr = window.qrcode(0, 'M'); qr.addData(d.loginUrl); qr.make();
  var n = qr.getModuleCount(), path = '';
  for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) if (qr.isDark(r, c)) path += 'M' + c + ' ' + r + 'h1v1h-1z';

  var lines = [['Username', d.username], ['Password', d.password], ['Ruolo', d.role], ['Lega', cut((d.leagues || []).join(', ') || '—', 30)]];
  if (d.match) { lines.push(['Partita', cut(d.match.date, 30)]); if (d.match.location) lines.push(['Campo', cut(d.match.location, 30)]); }
  var y0 = 600, step = 44;
  // ogni riga è un solo testo centrato: etichetta in grigio e valore in grassetto (a spazio fisso per username e password)
  var rows = lines.map(function (kv, i) {
    var y = y0 + i * step, mono = kv[0] === 'Username' || kv[0] === 'Password';
    return '<text x="360" y="' + y + '" text-anchor="middle"><tspan font-size="17" fill="' + muted + '" font-weight="700">' + esc(kv[0]) + '  </tspan>' +
      '<tspan dx="14" font-size="' + (mono ? 26 : 21) + '" fill="' + ink + '" font-weight="800"' + (mono ? ' font-family="ui-monospace, Menlo, Consolas, monospace"' : '') + '>' + esc(kv[1]) + '</tspan></text>';
  }).join('');
  var yEnd = y0 + lines.length * step;
  var foot = d.role === 'Ospite' ? 'Con questo account vedi la partita, la formazione e i voti.' : 'Al primo accesso puoi cambiare la password da Modifica profilo → Sicurezza.';
  var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="720" height="984" viewBox="0 0 720 984" font-family="Nunito, \'Trebuchet MS\', Arial, sans-serif">' +
    '<rect x="16" y="16" width="696" height="960" rx="26" fill="' + ink + '"/>' +
    '<rect x="8" y="8" width="696" height="960" rx="26" fill="' + paper + '" stroke="' + ink + '" stroke-width="5"/>' +
    '<rect x="8" y="8" width="696" height="104" rx="26" fill="' + yellow + '" stroke="' + ink + '" stroke-width="5"/><rect x="11" y="86" width="690" height="24" fill="' + yellow + '"/>' +
    '<line x1="8" y1="112" x2="704" y2="112" stroke="' + ink + '" stroke-width="5"/>' +
    '<circle cx="217" cy="60" r="28" fill="#fff" stroke="' + ink + '" stroke-width="4"/><text x="217" y="71" font-size="30" text-anchor="middle">⚽</text>' +
    '<text x="263" y="56" font-size="32" font-weight="900" fill="' + ink + '">Calcetto Manager</text>' +
    '<text x="263" y="86" font-size="17" font-weight="700" fill="' + ink + '">Sei stato invitato!</text>' +
    '<text x="360" y="172" font-size="32" font-weight="900" text-anchor="middle" fill="' + ink + '">' + esc(cut('Ciao ' + d.name, 28)) + '</text>' +
    '<rect x="236" y="204" width="240" height="240" rx="16" fill="#fff" stroke="' + ink + '" stroke-width="4"/>' +
    '<g transform="translate(244 212) scale(' + (224 / n) + ')"><path d="' + path + '" fill="' + ink + '"/></g>' +
    '<text x="360" y="476" font-size="16" text-anchor="middle" fill="' + muted + '" font-weight="700">Inquadra e accedi</text>' +
    '<rect x="210" y="492" width="292" height="44" rx="12" fill="' + green + '" stroke="' + ink + '" stroke-width="3"/>' +
    '<text x="360" y="520" font-size="16" text-anchor="middle" fill="' + ink + '" font-weight="800">' + esc(cut(d.loginUrl.replace(/^https?:\/\//, ''), 32)) + '</text>' +
    '<line x1="120" y1="560" x2="600" y2="560" stroke="' + muted + '" stroke-opacity="0.35" stroke-width="2" stroke-dasharray="6 6"/>' + rows +
    '<text x="360" y="' + (yEnd + 24) + '" font-size="16" text-anchor="middle" fill="' + muted + '" font-weight="700">' + esc(cut('Account creato da ' + d.by, 50)) + '</text>' +
    (d.validDays ? '<text x="360" y="' + (yEnd + 48) + '" font-size="14" text-anchor="middle" fill="' + muted + '">' + esc('Valido ' + d.validDays + ' giorni dopo la partita') + '</text>' : '') +
    '<text x="360" y="' + (yEnd + (d.validDays ? 76 : 52)) + '" font-size="13" text-anchor="middle" fill="' + muted + '">' + esc(foot) + '</text></svg>';
  box.innerHTML = svg;
  box.setAttribute('role', 'img'); box.setAttribute('aria-label', 'Invito per ' + d.name + ': username ' + d.username);

  var text = ['Ciao ' + d.name + '! Ti ho creato un account su Calcetto Manager.', 'Accedi da: ' + d.loginUrl, 'Username: ' + d.username, 'Password: ' + d.password,
    'Ruolo: ' + d.role + ((d.leagues || []).length ? ' · lega ' + d.leagues.join(', ') : ''), d.match ? 'Partita: ' + d.match.date + (d.match.location ? ', ' + d.match.location : '') : '', '— ' + d.by]
    .filter(Boolean).join('\n');

  // JPG per scaricare e condividere (sfondo pieno: il JPG non ha trasparenza); PNG per gli appunti, gli unici che i browser accettano
  function image(type) {
    return new Promise(function (resolve) {
      var img = new Image();
      img.onload = function () {
        var cv = document.createElement('canvas'); cv.width = 1440; cv.height = 1968;
        var ctx = cv.getContext('2d');
        if (type === 'image/jpeg') { ctx.fillStyle = paper; ctx.fillRect(0, 0, 1440, 1968); }
        ctx.drawImage(img, 0, 0, 1440, 1968);
        cv.toBlob(resolve, type, 0.92);
      };
      img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    });
  }
  document.getElementById('invite-download').addEventListener('click', function () {
    image('image/jpeg').then(function (b) { var a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'invito-' + d.username + '.jpg'; a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000); });
  });
  var copyBtn = document.getElementById('invite-copy');
  copyBtn.textContent = "Copia l'invito";
  // copia l'invito intero (l'immagine); se il browser non lo permette, il testo
  copyBtn.addEventListener('click', function () {
    var done = function () { copyBtn.textContent = 'Copiato'; };
    image('image/png').then(function (b) {
      if (b && window.ClipboardItem) return navigator.clipboard.write([new ClipboardItem({ 'image/png': b })]);
      return navigator.clipboard.writeText(text);
    }).then(done).catch(function () { navigator.clipboard.writeText(text).then(done); });
  });
  document.getElementById('invite-share').addEventListener('click', function () {
    image('image/jpeg').then(function (b) {
      var file = b ? new File([b], 'invito-' + d.username + '.jpg', { type: 'image/jpeg' }) : null;
      if (file && navigator.canShare && navigator.canShare({ files: [file] })) return navigator.share({ files: [file], text: text });
      if (navigator.share) return navigator.share({ text: text });
      return navigator.clipboard.writeText(text).then(function () { copyBtn.textContent = 'Copiato'; });
    }).catch(function () {});
  });
})();
