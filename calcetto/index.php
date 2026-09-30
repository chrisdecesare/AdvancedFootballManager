<?php
require __DIR__ . '/lib/bootstrap.php';
if (!tables_exist()) {
    redirect('install.php');
}
require_view();

$me = my_player_id();

// proposte per il sito dalla card del countdown: le legge solo l'admin (admin.php, lib/guess.php)
if (is_post() && ($_POST['do'] ?? '') === 'proposal') {
    require_login();
    $text = trim(preg_replace('/[ \t]+/u', ' ', (string) ($_POST['proposal'] ?? '')));
    if (is_guest()) {
        flash('err', 'Gli ospiti non possono mandare proposte.');
    } elseif ($text === '' || mb_strlen($text) > PROPOSAL_MAX) {
        flash('err', 'Scrivi la tua proposta (max ' . PROPOSAL_MAX . ' caratteri).');
    } elseif (proposal_recent_count((int) current_user()['id']) >= 10) {
        flash('err', 'Hai già mandato tante proposte oggi: riprova domani.');
    } else {
        proposal_add((int) current_user()['id'], $me, $text);
        flash('ok', 'Proposta inviata: la legge solo l\'admin. Grazie!');
    }
    redirect('index.php#proposte');
}
$players = all_players();

$next = next_match();
$groups = ['confermato' => [], 'in_attesa' => [], 'assente' => []];
$myStatus = null;
$roster = [];
if ($next) {
    sync_match_players((int) $next['id']);
    $roster = match_roster((int) $next['id']);
    foreach ($roster as $r) {
        if ((int) $r['player_id'] === $me) {
            $myStatus = $r['availability'];
        }
        if ($r['availability'] === 'confermato' || $players[(int) $r['player_id']]['active']) {
            $groups[$r['availability']][] = $r;
        }
    }
}
$hasTeams = (bool) array_filter($roster, fn($r) => $r['team']);

$last = last_played_match();
$slides = $last ? match_highlights($last) : [];
$lastRoster = $last ? match_roster((int) $last['id']) : [];
$iPlayedLast = false;
foreach ($lastRoster as $r) {
    if ((int) $r['player_id'] === $me && $r['team']) {
        $iPlayedLast = true;
    }
}
$mustVote = $last && $last['voting_open'] && $iPlayedLast &&
    !q('SELECT 1 FROM mvp_votes WHERE match_id = ? AND voter_id = ?', [$last['id'], $me])->fetch();

$facts = curiosities_for_home();

layout_start('Home', 'home');
?>
<?= group_bar('index.php') ?>
<div class="home">

  <?php $launched = avatar_public() && avatar_launch_round() === guess_round(); // il round del countdown è quello che ha aperto il Personaggio ?>
  <section class="card drop-hype<?= $launched ? ' is-launched' : '' ?>">
    <?php if ($launched): ?>
    <span class="drop-hype-tag"><i class="ti ti-sparkles"></i> Novità</span>
    <div class="drop-hype-row">
      <i class="ti ti-user-star drop-hype-icon" aria-hidden="true"></i>
      <div class="drop-hype-txt">
        <h2>È arrivato il Personaggio!</h2>
        <p>Il tuo giocatore in pixel art: capelli, maglie, bandiere, pet ed esultanze da comprare con i KOIN. Altri oggetti sono in arrivo, un po' alla volta.</p>
      </div>
    </div>
    <?php if ($me && !is_guest()): ?><a class="btn btn-primary drop-hype-cta" href="avatar.php"><i class="ti ti-user-star"></i> Crea il tuo personaggio</a><?php endif; ?>
    <?php elseif (guess_revealed() && !guess_drop_passed()): ?>
    <span class="drop-hype-tag"><i class="ti ti-eye"></i> Svelato</span>
    <div class="drop-hype-row">
      <i class="ti ti-user-star drop-hype-icon" aria-hidden="true"></i>
      <div class="drop-hype-txt">
        <h2><?= h(guess_reveal_text()) ?></h2>
        <p>Esce <?= h(fmt_date_long(date('Y-m-d H:i:s', guess_drop_at()))) ?> alle <?= date('H:i', guess_drop_at()) ?>. Chi aveva indovinato riceverà dei KOIN.</p>
      </div>
      <?= countdown_html(date('Y-m-d H:i:s', guess_drop_at()), 'Manca ', 'È il momento!', 0, true, 'hourglass-high', 'countdown-big drop-hype-count') ?>
    </div>
    <?php else: ?>
    <span class="drop-hype-tag"><i class="ti ti-eye-off"></i> Top secret</span>
    <div class="drop-hype-row">
      <i class="ti ti-gift drop-hype-icon" aria-hidden="true"></i>
      <div class="drop-hype-txt">
        <h2><?= guess_drop_passed() ? 'Ci siamo: sta per uscire!' : (date('Y-m-d', guess_drop_at()) === date('Y-m-d') ? 'Oggi' : ucfirst(GIORNI[(int) date('w', guess_drop_at())])) . ' cambia tutto.' ?></h2>
        <p>Una novità è in arrivo e nessuno, tranne l'admin, sa cosa sia davvero.<?= guess_teaser() !== '' ? ' Indizio: «' . h(guess_teaser()) . '»' : '' ?></p>
      </div>
      <?= countdown_html(date('Y-m-d H:i:s', guess_drop_at()), 'Manca ', 'È il momento!', 0, true, 'hourglass-high', 'countdown-big drop-hype-count') ?>
    </div>
    <a class="btn btn-primary drop-hype-cta" href="guess.php"><i class="ti ti-help-circle"></i> Prova a indovinare cosa sarà: in palio dei KOIN</a>
    <?php endif; ?>

  </section>

  <?php if (current_user() && !is_guest()): // proposte per il sito: una card a parte, accanto al countdown (sotto, sui telefoni) ?>
  <section class="card ideas-card" id="proposte">
    <h2><i class="ti ti-bulb"></i> Hai un'idea per il sito?</h2>
    <p class="muted small">Una funzione nuova, un'esultanza, una statistica che manca, un torneo... Proponila: la legge solo l'admin.</p>
    <form method="post" class="form ideas-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="proposal">
      <textarea name="proposal" maxlength="<?= PROPOSAL_MAX ?>" rows="3" required aria-label="La tua proposta" placeholder="Es. Un nuovo tipo di esultanza, una statistica che manca, un torneo..."></textarea>
      <div class="btn-row"><button class="btn btn-primary btn-sm"><i class="ti ti-send"></i> Invia proposta</button></div>
    </form>
    <?php if (is_admin()): $newProposals = proposal_unread_count(); ?>
    <a class="btn btn-ghost btn-sm ideas-read" href="admin.php#proposte"><i class="ti ti-inbox"></i> Leggi le proposte dei giocatori<?= $newProposals ? ' (' . $newProposals . ' nuove)' : '' ?></a>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?= push_card(true) ?>

  <?php if ($me && empty(current_user()['email'])): ?>
  <section class="card push-banner email-banner" data-email-banner hidden>
    <i class="ti ti-mail-heart push-ic"></i>
    <div class="push-txt"><strong><?= empty(current_user()['pending_email']) ? 'Aggiungi la tua email' : 'Conferma la tua email' ?></strong>
      <span class="muted small"><?= empty(current_user()['pending_email']) ? 'Se dimentichi la password potrai recuperarla da solo.' : 'Apri il link nell\'email che ti abbiamo mandato (controlla anche lo spam).' ?></span></div>
    <div class="btn-row"><a class="btn btn-primary btn-sm" href="account.php#email"><?= empty(current_user()['pending_email']) ? 'Aggiungi' : 'Gestisci' ?></a>
      <button type="button" class="btn btn-ghost btn-sm" data-email-dismiss>Non ora</button></div>
  </section>
  <?php endif; ?>

  <section class="card hero">
    <div class="hero-head">
      <span class="eyebrow"><i class="ti ti-calendar-event"></i> Prossima partita</span>
      <?php if ($next): ?><a class="btn btn-ghost btn-sm" href="match.php?id=<?= (int) $next['id'] ?>">Dettagli <i class="ti ti-arrow-right"></i></a><?php endif; ?>
    </div>
    <?php if ($next): ?>
      <div class="hero-when">
        <div class="hero-date"><?= h(ucfirst(fmt_date_long($next['match_date']))) ?></div>
        <div class="hero-meta">
          <span><i class="ti ti-clock"></i> <?= fmt_time($next['match_date']) ?></span>
          <?= place_chip($next['location']) ?>
          <?= group_tag((int) $next['group_id']) ?>
          <?php if ((float) $next['fee'] > 0): ?><span><?= fmt_money($next['fee']) ?></span><?php endif; ?>
        </div>
        <div class="hero-vs"><span class="team-a"><?= h(team_name('A', $next)) ?></span> <b>vs</b> <span class="team-b"><?= h(team_name('B', $next)) ?></span></div>
        <div class="hero-count"><?= countdown_html($next['match_date'], 'Mancano ', 'Si gioca!', 86400, false, 'hourglass-high', 'countdown-big') ?></div>
        <div class="hero-cal"><?= gcal_button($next) ?></div>
      </div>
      <?= availability_buttons($next, $myStatus, 'index.php') ?>

      <?php if ($hasTeams): ?>
        <?php if (avatar_visible()): // la vista con i Personaggi si apre a tutti insieme al Personaggio (lib/guess.php: avatar_public) ?>
        <div class="sub-title-row">
          <h3 class="sub-title"><i class="ti ti-soccer-field"></i> Le formazioni</h3>
          <div class="pitch-view-toggle" role="group" aria-label="Vista formazioni">
            <button type="button" class="btn btn-ghost btn-sm is-on" data-pitch-view-toggle="2d"><i class="ti ti-circles"></i> Cerchi</button>
            <button type="button" class="btn btn-ghost btn-sm" data-pitch-view-toggle="fig"><i class="ti ti-users"></i> Personaggi</button>
          </div>
        </div>
        <div data-pitch-view="2d"><?= render_pitch($next, $roster) ?></div>
        <div data-pitch-view="fig" hidden><?= render_pitch_figures($next, $roster) ?></div>
        <script src="assets/avatar_px.js?v=<?= h(substr((string) @md5_file(__DIR__ . '/assets/avatar_px.js'), 0, 10)) ?>"></script>
        <script>
        document.querySelectorAll('[data-pitch-view-toggle]').forEach(btn => btn.addEventListener('click', () => {
          const view = btn.dataset.pitchViewToggle;
          document.querySelectorAll('[data-pitch-view-toggle]').forEach(b => b.classList.toggle('is-on', b === btn));
          document.querySelectorAll('[data-pitch-view]').forEach(el => el.hidden = el.dataset.pitchView !== view);
          try { localStorage.setItem('pitchView', view); } catch (e) {}
        }));
        try {
          const saved = localStorage.getItem('pitchView');
          if (saved === 'fig') { document.querySelector('[data-pitch-view-toggle="fig"]')?.click(); }
        } catch (e) {}
        </script>
        <?php else: ?>
          <h3 class="sub-title"><i class="ti ti-soccer-field"></i> Le formazioni</h3>
          <?= render_pitch($next, $roster) ?>
        <?php endif; ?>
      <?php endif; ?>

      <div class="avail-cols">
        <div class="avail-col">
          <h3><i class="ti ti-user-check"></i> Confermati <span class="count count-yes"><?= count($groups['confermato']) ?></span></h3>
          <?php foreach ($groups['confermato'] as $r): ?><?= player_line($r) ?><?php endforeach; ?>
          <?php if (!$groups['confermato']): ?><p class="muted small">Ancora nessuno.</p><?php endif; ?>
        </div>
        <div class="avail-col">
          <h3><i class="ti ti-user-question"></i> Da confermare <span class="count"><?= count($groups['in_attesa']) ?></span></h3>
          <?php foreach ($groups['in_attesa'] as $r): ?><?= player_line($r) ?><?php endforeach; ?>
          <?php if (!$groups['in_attesa']): ?><p class="muted small">Hanno risposto tutti.</p><?php endif; ?>
        </div>
        <div class="avail-col">
          <h3><i class="ti ti-user-x"></i> Assenti <span class="count count-no"><?= count($groups['assente']) ?></span></h3>
          <?php foreach ($groups['assente'] as $r): ?><?= player_line($r) ?><?php endforeach; ?>
          <?php if (!$groups['assente']): ?><p class="muted small">Nessun assente.</p><?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <p class="empty">Nessuna partita in programma.</p>
      <?php if (can_manage_matches() && manageable_groups()): ?><a class="btn btn-primary" href="matches.php#nuova">+ Crea partita</a><?php endif; ?>
    <?php endif; ?>
  </section>

  <?php if ($last): ?>
  <section class="card recap">
    <div class="card-head">
      <span class="eyebrow"><i class="ti ti-history"></i> L'ultima partita</span>
      <a class="link" href="match.php?id=<?= (int) $last['id'] ?>">Tabellino <i class="ti ti-arrow-right"></i></a>
    </div>
    <div class="score">
      <div class="score-team team-a"><?= h(team_name('A', $last)) ?></div>
      <div class="score-num"><?= (int) $last['score_a'] ?> <span>–</span> <?= (int) $last['score_b'] ?></div>
      <div class="score-team team-b"><?= h(team_name('B', $last)) ?></div>
    </div>
    <p class="muted center small"><?= h(ucfirst(fmt_date_long($last['match_date']))) ?><?= $last['location'] ? ' · ' . h($last['location']) : '' ?></p>

    <?php if ($mustVote): ?>
      <a class="btn btn-primary btn-block" href="match.php?id=<?= (int) $last['id'] ?>#voti"><i class="ti ti-writing"></i> Vota i compagni e l'MVP</a>
      <?php if ($last['voting_ends_at']): ?><p class="small center muted vote-deadline"><i class="ti ti-alarm"></i> Hai tempo fino a <?= h(push_when($last['voting_ends_at'])) ?> <?= countdown_html($last['voting_ends_at'], '(mancano ', 'chiusura in corso…', 0, true, 'hourglass', 'countdown-small') ?></p><?php endif; ?>
    <?php elseif ($last['voting_open']): ?>
      <p class="small center muted"><i class="ti ti-hourglass"></i> Votazioni in corso: MVP e voti arrivano alla chiusura<?= $last['voting_ends_at'] ? ' (' . h(push_when($last['voting_ends_at'])) . ')' : '' ?>.</p>
    <?php endif; ?>

    <?php if ($slides): ?>
      <div class="slider" data-slider>
        <div class="slides" data-slides>
          <?php foreach ($slides as $sl): ?>
            <article class="slide slide-<?= h($sl['kind']) ?>">
              <div class="slide-title"><i class="ti ti-<?= h($sl['icon']) ?>"></i> <?= h($sl['title']) ?></div>
              <div class="slide-faces">
                <?php foreach (array_slice($sl['players'], 0, 4) as $p): ?>
                  <a href="player.php?id=<?= (int) $p['player_id'] ?>" title="<?= h($p['name']) ?>"><?= avatar($p, count($sl['players']) > 1 ? 'md' : 'lg') ?></a>
                <?php endforeach; ?>
              </div>
              <div class="slide-name"><?= h(implode(', ', array_column(array_slice($sl['players'], 0, 4), 'name'))) ?><?= count($sl['players']) > 4 ? ' e altri' : '' ?></div>
              <?php if ($sl['big'] !== ''): ?><div class="slide-big"><?= h($sl['big']) ?></div><?php endif; ?>
              <div class="slide-text"><?= h($sl['text']) ?></div>
            </article>
          <?php endforeach; ?>
        </div>
        <?php if (count($slides) > 1): ?>
          <div class="slider-nav">
            <button type="button" class="icon-btn" data-prev aria-label="Precedente"><i class="ti ti-chevron-left"></i></button>
            <div class="dots" data-dots>
              <?php foreach ($slides as $i => $_): ?><button type="button" class="dot" aria-label="Vai alla scheda <?= $i + 1 ?>"></button><?php endforeach; ?>
            </div>
            <button type="button" class="icon-btn" data-next aria-label="Successiva"><i class="ti ti-chevron-right"></i></button>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($facts): // le curiosità stanno in fondo: prima vengono le informazioni sulle partite. Cambiano da sole ogni FACT_SECONDS secondi ?>
  <section class="card fact" data-facts data-seconds="<?= FACT_SECONDS ?>">
    <div class="card-head">
      <span class="eyebrow"><i class="ti ti-bulb"></i> Curiosità dalla rosa</span>
      <a class="link" href="curiosities.php">Tutte le curiosità <i class="ti ti-arrow-right"></i></a>
    </div>
    <div class="fact-slides" aria-live="off">
      <?php foreach ($facts as $i => $fact): ?>
      <div class="fact-body<?= $i === 0 ? ' is-active' : '' ?>" data-fact>
        <a href="player.php?id=<?= (int) $fact['player_id'] ?>" title="<?= h($fact['name']) ?>"><?= avatar($fact, 'md') ?></a>
        <div><a class="fact-who" href="player.php?id=<?= (int) $fact['player_id'] ?>"><?= h($fact['name']) ?></a>
          <p class="fact-text" data-clamp><?= h($fact['body']) ?></p></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if (count($facts) > 1): ?><div class="fact-timer" aria-hidden="true"><span data-fact-bar></span></div><?php endif; ?>
  </section>
  <?php endif; ?>

</div>
<?php
layout_end();
