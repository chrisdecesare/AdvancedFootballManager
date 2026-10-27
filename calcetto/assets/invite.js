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

  var lines = [['Username', d.username], ['Password', d.password], ['Ruolo', d.role], ['Lega', cut((d.leagues || []).join(', ') || '—', 36)]];
  if (d.match) { lines.push(['Partita', cut(d.match.date, 36)]); if (d.match.location) lines.push(['Campo', cut(d.match.location, 36)]); }
  var step = lines.length > 5 ? 44 : 52;
  var rows = lines.map(function (kv, i) {
    var y = 214 + i * step, mono = kv[0] === 'Username' || kv[0] === 'Password';
    return '<text x="64" y="' + y + '" font-size="17" fill="' + muted + '" font-weight="700">' + esc(kv[0]) + '</text>' +
      (mono ? '<text x="190" y="' + y + '" font-size="26" fill="' + ink + '" font-weight="800" font-family="ui-monospace, Menlo, Consolas, monospace">' + esc(kv[1]) + '</text>'
            : '<text x="190" y="' + y + '" font-size="20" fill="' + ink + '" font-weight="800">' + esc(kv[1]) + '</text>');
  }).join('');
  var foot = d.role === 'Ospite' ? 'Con questo account vedi la partita, la formazione e i voti.' : 'Al primo accesso puoi cambiare la password da Modifica profilo → Sicurezza.';
  var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="560" viewBox="0 0 900 560" font-family="Nunito, \'Trebuchet MS\', Arial, sans-serif">' +
    '<rect x="18" y="18" width="864" height="524" rx="26" fill="' + ink + '"/>' +
    '<rect x="8" y="8" width="864" height="524" rx="26" fill="' + paper + '" stroke="' + ink + '" stroke-width="5"/>' +
    '<rect x="8" y="8" width="864" height="96" rx="26" fill="' + yellow + '" stroke="' + ink + '" stroke-width="5"/><rect x="11" y="80" width="858" height="22" fill="' + yellow + '"/>' +
    '<line x1="8" y1="104" x2="872" y2="104" stroke="' + ink + '" stroke-width="5"/>' +
    '<circle cx="64" cy="56" r="26" fill="#fff" stroke="' + ink + '" stroke-width="4"/><text x="64" y="66" font-size="28" text-anchor="middle">⚽</text>' +
    '<text x="106" y="50" font-size="30" font-weight="900" fill="' + ink + '">Calcetto Manager</text>' +
    '<text x="106" y="80" font-size="17" font-weight="700" fill="' + ink + '">Sei stato invitato!</text>' +
    '<text x="64" y="154" font-size="30" font-weight="900" fill="' + ink + '">' + esc(cut('Ciao ' + d.name, 30)) + '</text>' + rows +
    '<rect x="616" y="136" width="236" height="236" rx="14" fill="#fff" stroke="' + ink + '" stroke-width="4"/>' +
    '<g transform="translate(624 144) scale(' + (220 / n) + ')"><path d="' + path + '" fill="' + ink + '"/></g>' +
    '<text x="734" y="398" font-size="15" text-anchor="middle" fill="' + muted + '" font-weight="700">Inquadra e accedi</text>' +
    '<rect x="616" y="416" width="236" height="44" rx="12" fill="' + green + '" stroke="' + ink + '" stroke-width="3"/>' +
    '<text x="734" y="444" font-size="16" text-anchor="middle" fill="' + ink + '" font-weight="800">' + esc(cut(d.loginUrl.replace(/^https?:\/\//, ''), 26)) + '</text>' +
    '<text x="64" y="' + (214 + lines.length * step + 8) + '" font-size="16" fill="' + muted + '" font-weight="700">' + esc(cut('Account creato da ' + d.by + (d.validDays ? ' · valido ' + d.validDays + ' giorni dopo la partita' : ''), 70)) + '</text>' +
    '<text x="64" y="508" font-size="14" fill="' + muted + '">' + esc(foot) + '</text></svg>';
  box.innerHTML = svg;
  box.setAttribute('role', 'img'); box.setAttribute('aria-label', 'Invito per ' + d.name + ': username ' + d.username);

  var text = ['Ciao ' + d.name + '! Ti ho creato un account su Calcetto Manager.', 'Accedi da: ' + d.loginUrl, 'Username: ' + d.username, 'Password: ' + d.password,
    'Ruolo: ' + d.role + ((d.leagues || []).length ? ' · lega ' + d.leagues.join(', ') : ''), d.match ? 'Partita: ' + d.match.date + (d.match.location ? ', ' + d.match.location : '') : '', '— ' + d.by]
    .filter(Boolean).join('\n');

  function png() {
    return new Promise(function (resolve) {
      var img = new Image();
      img.onload = function () {
        var cv = document.createElement('canvas'); cv.width = 1800; cv.height = 1120;
        cv.getContext('2d').drawImage(img, 0, 0, 1800, 1120);
        cv.toBlob(resolve, 'image/png');
      };
      img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    });
  }
  document.getElementById('invite-download').addEventListener('click', function () {
    png().then(function (b) { var a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'invito-' + d.username + '.png'; a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000); });
  });
  var copyBtn = document.getElementById('invite-copy');
  copyBtn.addEventListener('click', function () { navigator.clipboard.writeText(text).then(function () { copyBtn.textContent = 'Copiato'; }); });
  document.getElementById('invite-share').addEventListener('click', function () {
    png().then(function (b) {
      var file = b ? new File([b], 'invito-' + d.username + '.png', { type: 'image/png' }) : null;
      if (file && navigator.canShare && navigator.canShare({ files: [file] })) return navigator.share({ files: [file], text: text });
      if (navigator.share) return navigator.share({ text: text });
      return navigator.clipboard.writeText(text).then(function () { copyBtn.textContent = 'Copiato'; });
    }).catch(function () {});
  });
})();
