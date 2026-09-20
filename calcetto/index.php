<?php
require __DIR__ . '/lib/bootstrap.php';
if (!tables_exist()) {
    redirect('install.php');
}
require_view();

$me = my_player_id();
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

$fact = curiosity_of_the_day();

layout_start('Home', 'home');
?>
<?= group_bar('index.php') ?>
<div class="home">

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
          <span><i class="ti ti-map-pin"></i> <?= h($next['location'] ?: 'Campo da definire') ?></span>
          <?= group_tag((int) $next['group_id']) ?>
          <?php if ((float) $next['fee'] > 0): ?><span><i class="ti ti-currency-euro"></i> <?= fmt_money($next['fee']) ?></span><?php endif; ?>
        </div>
        <div class="hero-vs"><span class="team-a"><?= h(team_name('A', $next)) ?></span> <b>vs</b> <span class="team-b"><?= h(team_name('B', $next)) ?></span></div>
        <div class="hero-count"><?= countdown_html($next['match_date'], 'Mancano ', 'Si gioca!', 86400, false, 'hourglass-high', 'countdown-big') ?></div>
        <div class="hero-cal"><?= gcal_button($next) ?></div>
      </div>
      <?= availability_buttons($next, $myStatus, 'index.php') ?>

      <?php if ($hasTeams): ?>
        <h3 class="sub-title"><i class="ti ti-soccer-field"></i> Le formazioni</h3>
        <?= render_pitch($next, $roster) ?>
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

  <?php if ($fact): // le curiosità stanno in fondo: prima vengono le informazioni sulle partite ?>
  <section class="card fact">
    <div class="card-head">
      <span class="eyebrow"><i class="ti ti-bulb"></i> Curiosità dalla rosa</span>
      <a class="link" href="curiosities.php">Tutte le curiosità <i class="ti ti-arrow-right"></i></a>
    </div>
    <div class="fact-body">
      <a href="player.php?id=<?= (int) $fact['player_id'] ?>" title="<?= h($fact['name']) ?>"><?= avatar($fact, 'md') ?></a>
      <div><a class="fact-who" href="player.php?id=<?= (int) $fact['player_id'] ?>"><?= h($fact['name']) ?></a>
        <p><?= h($fact['body']) ?></p></div>
    </div>
  </section>
  <?php endif; ?>

</div>
<?php
layout_end();
