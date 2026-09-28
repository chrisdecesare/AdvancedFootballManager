<?php
/*
 * "Indovina la funzionalità": sezione segreta (non è nel menu, si arriva dalla card del countdown in Home) dove ogni giocatore
 * scrive la sua idea su quale sarà la prossima novità del sito. Le idee degli altri restano segrete: si vede solo quante sono
 * arrivate. Quando esce la novità, l'admin le legge (admin.php) e regala gettoni a chi si è avvicinato di più.
 */
require __DIR__ . '/lib/bootstrap.php';
require_view();

$me = my_player_id();
$round = guess_round();
$passed = guess_drop_passed();

if (is_post()) {
    require_login();
    if (!$me) {
        flash('err', 'Il tuo account non è collegato a un giocatore: chiedi all\'admin.');
    } elseif ($passed) {
        flash('err', 'Il tempo è scaduto: questo round è chiuso.');
    } else {
        $text = trim((string) ($_POST['guess'] ?? ''));
        if ($text === '' || mb_strlen($text) > 300) {
            flash('err', 'Scrivi un\'idea (max 300 caratteri).');
        } else {
            guess_set($me, $text);
            flash('ok', 'Idea registrata! La puoi cambiare finché non scade il tempo.');
        }
    }
    redirect('guess.php');
}

$mine = $me ? guess_mine($me) : null;
$count = guess_count();

layout_start('Indovina la funzionalità', '');
?>
<a class="back" href="index.php"><i class="ti ti-arrow-left"></i> Home</a>
<div class="page-head"><h1><i class="ti ti-help-circle"></i> Indovina la funzionalità</h1></div>

<section class="card">
  <p><i class="ti ti-gift"></i> <?= $passed ? 'Ci siamo quasi: la nuova funzionalità sta per uscire.' : 'Giovedì esce una nuova funzionalità.' ?>
    <?= countdown_html(date('Y-m-d H:i:s', guess_drop_at()), 'Manca ', 'Ci siamo!', 0, false, 'hourglass-high') ?></p>
  <?php if (guess_teaser() !== ''): ?><p class="muted small">Indizio dell'admin: «<?= h(guess_teaser()) ?>»</p><?php endif; ?>
  <p class="muted small">Scrivi la tua idea su cosa sarà: resta segreta, solo l'admin la legge. Quando esce la novità, chi si è avvicinato di più riceve dei gettoni in regalo.
    <?= $count ? ($count === 1 ? 'Per ora è arrivata 1 idea.' : 'Per ora sono arrivate ' . $count . ' idee.') : 'Nessuno ha ancora provato: sii il primo!' ?></p>
</section>

<?php if (!$me): ?>
  <p class="empty card">Il tuo account non è collegato a un giocatore: puoi guardare il countdown ma non lasciare un'idea.</p>
<?php else: ?>
<section class="card">
  <h2><?= $mine !== null ? 'La tua idea' : 'Scrivi la tua idea' ?></h2>
  <?php if ($passed): ?>
    <p class="muted small">Il tempo è scaduto: questo round è chiuso, si riapre al prossimo drop.</p>
    <?php if ($mine !== null): ?><p class="guess-mine">«<?= h($mine) ?>»</p><?php endif; ?>
  <?php else: ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label class="field"><span>Cosa pensi che sarà?</span>
        <textarea name="guess" maxlength="300" rows="3" placeholder="Es. Un torneo a eliminazione diretta tra i giocatori..."><?= h($mine ?? '') ?></textarea></label>
      <div class="btn-row"><button class="btn btn-primary"><i class="ti ti-send"></i> <?= $mine !== null ? 'Aggiorna idea' : 'Invia idea' ?></button></div>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php
layout_end();
