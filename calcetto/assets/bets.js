/* Schedina e selettore dell'over/under della pagina Scommesse (bets.php). */
// Schedina: si clicca su una quota per aggiungerla (come in un'app di scommesse vera). Da qui si punta ogni selezione
// da sola (scheda «Singole», una puntata indipendente ciascuna) oppure tutte insieme in una sola multipla (scheda «Multipla»,
// quota = prodotto delle quote). Sopravvive alla navigazione tra le schede della pagina (sessionStorage).
(() => {
  const KEY = 'betslipCart';
  const slip = document.getElementById('betslip');
  if (!slip) return;
  const balance = parseInt(slip.dataset.balance, 10) || 0;
  const legsBox = document.getElementById('slip-legs');
  const countOut = document.getElementById('slip-count');
  const tabSingole = document.querySelector('[data-slip-tab="singole"]');
  const tabMulti = document.getElementById('slip-tab-multi');
  const panelSingole = document.getElementById('slip-singles-form');
  const panelMulti = document.getElementById('slip-multi-form');
  const multiLegsBox = document.getElementById('slip-multi-legs');
  const multiOddsOut = document.getElementById('slip-multi-odds');
  const singlesSubmit = document.getElementById('slip-singles-submit');

  const load = () => { try { return JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { return []; } };
  const save = cart => { try { sessionStorage.setItem(KEY, JSON.stringify(cart)); } catch (e) {} };
  let cart = load();
  let tab = 'singole';

  const hidden = (name, value) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = name; i.value = value; return i; };
  const win = (stake, odds) => Math.floor(stake * odds + 1e-9);

  const hint = document.getElementById('slip-hint');
  const singlesTotal = document.getElementById('slip-singles-total');
  const multiStake = document.getElementById('slip-multi-stake');
  const multiWin = document.getElementById('slip-multi-win');

  // Singole: ogni selezione ha il suo importo ed è una scommessa a sé. Multipla: un solo importo per tutte le selezioni,
  // la quota è il prodotto delle quote e si viene pagati solo se sono giuste TUTTE (basta un errore e si perde tutto).
  const showTab = t => {
    tab = t;
    slip.dataset.mode = t;
    tabSingole.classList.toggle('active', t === 'singole');
    tabMulti.classList.toggle('active', t === 'multipla');
    tabSingole.setAttribute('aria-selected', t === 'singole');
    tabMulti.setAttribute('aria-selected', t === 'multipla');
    panelSingole.hidden = t !== 'singole';
    panelMulti.hidden = t !== 'multipla';
    updateTotals();
  };

  const multiOddsNow = () => cart.reduce((o, l) => o * l.odds, 1);
  const updateTotals = () => {
    let staked = 0, maxWin = 0;
    cart.forEach(l => { const n = l.stake || 0; staked += n; maxWin += n > 0 ? win(n, l.odds) : 0; });
    singlesTotal.textContent = cart.length ? 'Totale puntato ' + staked + ' · se vincono tutte incassi ' + maxWin : '';
    const n = parseInt(multiStake.value, 10) || 0;
    multiWin.textContent = cart.length >= 2 && n > 0
      ? 'Vinci ' + win(n, multiOddsNow()) + ' solo se sono giuste tutte le ' + cart.length + ' scelte'
      : '';
    hint.textContent = tab === 'singole'
      ? 'Singole: ogni scelta è una scommessa separata con il suo importo, si paga ognuna per conto suo.'
      : 'Multipla: un solo importo su tutte le scelte insieme, le quote si moltiplicano. Si viene pagati solo se sono giuste tutte: basta un errore e si perde la puntata.';
  };
  multiStake.addEventListener('input', updateTotals);

  const render = () => {
    legsBox.innerHTML = '';
    multiLegsBox.innerHTML = '';
    countOut.textContent = cart.length;
    let multiOdds = 1;

    cart.forEach((leg, i) => {
      multiOdds *= leg.odds;

      const row = document.createElement('div');
      row.className = 'slip-leg';
      row.innerHTML = '<span class="slip-leg-txt">' + leg.matchLabel + ': <b>' + leg.label + '</b> ×' + leg.odds.toFixed(2) + '</span>';

      // scheda «Singole»: uno stake per selezione, aggiornato in tempo reale. La lista è fuori dal <form> (resta visibile
      // anche nella scheda Multipla), quindi questi campi si legano al form con l'attributo form="..." invece che con il nesting,
      // altrimenti il browser non li invia e "Punta le singole" risulta come se non si fosse puntato nulla.
      const legIn = hidden('legs[]', leg.matchId + ':' + leg.market + ':' + leg.pick);
      legIn.setAttribute('form', 'slip-singles-form');
      const stakeIn = document.createElement('input');
      stakeIn.type = 'number'; stakeIn.name = 'stakes[]'; stakeIn.className = 'slip-leg-stake';
      stakeIn.min = 1; stakeIn.max = Math.max(1, balance); stakeIn.inputMode = 'numeric';
      stakeIn.value = leg.stake || Math.min(10, Math.max(1, balance));
      stakeIn.setAttribute('aria-label', 'Gettoni su questa selezione');
      stakeIn.setAttribute('form', 'slip-singles-form');
      row.appendChild(legIn);
      row.appendChild(stakeIn);
      const winOut = document.createElement('span');
      winOut.className = 'slip-leg-win small';
      row.appendChild(winOut);
      const updateWin = () => {
        const n = parseInt(stakeIn.value, 10) || 0;
        leg.stake = n; save(cart);
        winOut.textContent = n > 0 ? 'vinci ' + win(n, leg.odds) : '';
        updateTotals();
      };
      stakeIn.addEventListener('input', updateWin);
      updateWin();

      const rm = document.createElement('button');
      rm.type = 'button'; rm.className = 'slip-leg-rm'; rm.textContent = '×'; rm.title = 'Togli dalla schedina';
      rm.addEventListener('click', () => { cart.splice(i, 1); save(cart); render(); });
      row.appendChild(rm);
      legsBox.appendChild(row);

      multiLegsBox.appendChild(hidden('legs[]', leg.matchId + ':' + leg.market + ':' + leg.pick));
    });

    multiOddsOut.textContent = '×' + (cart.length ? multiOdds.toFixed(2) : '0');
    // «chi vince», «over/under» e «MVP»: una sola scelta per partita nella multipla (si escludono a vicenda); i marcatori invece
    // si sommano, ma lo stesso giocatore su «segna», «doppietta» e «tripletta» no (una comprende l'altra). Stesse regole del server.
    const SCORER = ['gol', 'doppietta', 'tripletta'];
    const seen = {};
    let clash = null;
    cart.forEach(l => {
      const scorer = SCORER.includes(l.market);
      const k = l.matchId + '|' + (scorer ? 'scorer|' + l.pick : l.market);
      if (seen[k] && !clash) clash = scorer ? 'scorer' : 'excl';
      seen[k] = true;
    });
    const multiWarn = document.getElementById('slip-multi-warn');
    multiWarn.hidden = !clash;
    multiWarn.textContent = clash === 'excl' ? 'Due scelte di «chi vince», «over/under» o «MVP» della stessa partita si escludono: togline una per fare la multipla (restano valide come singole).'
      : clash === 'scorer' ? 'Lo stesso giocatore può stare in uno solo tra «segna», «doppietta» e «tripletta» nella multipla (una comprende l\'altra): togline uno (restano valide come singole).' : '';
    document.getElementById('slip-multi-submit').disabled = clash || cart.length < 2;
    singlesSubmit.disabled = cart.length === 0;
    tabMulti.disabled = cart.length < 2;
    tabMulti.title = cart.length < 2 ? 'Servono almeno 2 selezioni' : '';
    if (cart.length < 2 && tab === 'multipla') showTab('singole');
    slip.hidden = cart.length === 0;
    updateTotals();

    refreshPicked();
  };

  // evidenzia i pulsanti-quota già nella schedina (e, per l'over/under, quelli su cui ho già puntato alla soglia mostrata)
  const refreshPicked = () => {
    document.querySelectorAll('[data-slip-add]').forEach(btn => {
      btn.classList.toggle('is-picked', cart.some(l => l.matchId === btn.dataset.match && l.market === btn.dataset.market && l.pick === btn.dataset.pick));
    });
    document.querySelectorAll('.ou-picker').forEach(box => {
      const mine = JSON.parse(box.dataset.mine || '[]');
      box.querySelectorAll('[data-ou-side]').forEach(b => b.classList.toggle('is-mine', mine.includes(b.dataset.pick)));
    });
  };

  // over/under: chi punta sceglie la soglia (0,5 / 1,5 / ...; se scrive un intero N vale "più di N gol", cioè N,5);
  // la quota di ogni soglia l'ha già calcolata il server, qui si mostra quella giusta e si aggiornano i pulsanti Over/Under
  document.querySelectorAll('.ou-picker').forEach(box => {
    const table = JSON.parse(box.dataset.ou);
    const lines = Object.keys(table).map(Number).sort((a, b) => a - b);
    const input = box.querySelector('.ou-line');
    const fmt = n => n.toFixed(2).replace('.', ',');
    const set = v => {
      let line = Math.floor(Number.isFinite(v) ? v : lines[0]) + 0.5;
      line = Math.min(lines[lines.length - 1], Math.max(lines[0], line));
      input.value = line;
      const odds = table[line.toFixed(1)];
      box.querySelectorAll('[data-ou-side]').forEach(b => {
        const over = b.dataset.ouSide === 'O';
        const o = odds[over ? 0 : 1];
        b.dataset.pick = b.dataset.ouSide + line.toFixed(1);
        b.dataset.label = (over ? 'Over ' : 'Under ') + String(line).replace('.', ',') + ' gol';
        b.dataset.odds = o;
        b.innerHTML = (over ? 'Over' : 'Under') + ' <b>×' + fmt(o) + '</b>';
      });
      box.querySelector('.ou-over-txt').textContent = Math.ceil(line) + ' o più gol';
      box.querySelector('.ou-under-txt').textContent = Math.floor(line) + ' o meno';
      refreshPicked();
    };
    box.querySelectorAll('[data-ou-step]').forEach(b => b.addEventListener('click', () => set(parseFloat(input.value) + parseInt(b.dataset.ouStep, 10))));
    input.addEventListener('change', () => set(parseFloat(String(input.value).replace(',', '.'))));
  });

  document.querySelectorAll('[data-slip-add]').forEach(btn => btn.addEventListener('click', () => {
    // si può puntare su più scelte dello stesso mercato (es. due marcatori diversi): si toglie solo ri-toccando la STESSA quota.
    const matchId = btn.dataset.match, market = btn.dataset.market, pick = btn.dataset.pick;
    const already = cart.findIndex(l => l.matchId === matchId && l.market === market && l.pick === pick);
    if (already !== -1) { cart.splice(already, 1); save(cart); render(); return; }
    cart.push({ matchId, market, pick, label: btn.dataset.label, odds: parseFloat(btn.dataset.odds) || 1, matchLabel: btn.dataset.matchLabel });
    save(cart); render();
  }));
  tabSingole.addEventListener('click', () => showTab('singole'));
  tabMulti.addEventListener('click', () => { if (!tabMulti.disabled) showTab('multipla'); });
  const clearAll = () => { cart = []; save(cart); render(); };
  document.getElementById('slip-clear').addEventListener('click', clearAll);
  document.getElementById('slip-clear-2').addEventListener('click', clearAll);
  panelSingole.addEventListener('submit', () => { sessionStorage.removeItem(KEY); });
  panelMulti.addEventListener('submit', () => { sessionStorage.removeItem(KEY); });

  render();
})();
