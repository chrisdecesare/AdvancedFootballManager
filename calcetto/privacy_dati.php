<?php
/*
 * Privacy e dati: scaricare i propri dati (GDPR art. 15 e 20), eliminare l'account (art. 17, richiesto anche da Google Play), vedere i giocatori
 * bloccati e l'esito delle proprie segnalazioni. Come /privacy-dati del sito Next; eliminazione e segnalazioni compaiono quando le accende Pannello → Funzioni.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'delete') {
    define('NO_REMEMBER', true);   // chi elimina l'account non deve ricevere un nuovo «resta collegato»
}
require __DIR__ . '/lib/bootstrap.php';
require_login();
$u = current_user();
$uid = (int) $u['id'];

if (!is_post() && isset($_GET['scarica'])) {   // esportazione: poche volte all'ora
    if (!rate_hit('__export_' . $uid, 5, 3600)) {
        flash('err', 'Hai già scaricato i dati di recente: riprova tra un\'ora.');
        redirect('privacy_dati.php');
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="calcetto-manager-miei-dati.json"');
    header('Cache-Control: no-store');
    echo json_encode(export_my_data($uid), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (is_post() && ($_POST['do'] ?? '') === 'delete') {
    if (!feature_on('account_delete')) {
        redirect('index.php');
    }
    $fail = function (string $m): void {
        flash('err', $m);
        redirect('privacy_dati.php#elimina');
    };
    if (rate_recent('__pwcheck_' . $uid, 900) >= 5) {
        $fail('Troppi tentativi con la password: riprova tra 15 minuti.');
    }
    $acc = q('SELECT password_hash FROM users WHERE id = ?', [$uid])->fetch();
    if (!$acc || !password_verify((string) ($_POST['current'] ?? ''), $acc['password_hash'])) {
        rate_record('__pwcheck_' . $uid);
        $fail('La password non è corretta.');
    }
    if (strtoupper(trim((string) ($_POST['phrase'] ?? ''))) !== DELETE_PHRASE) {
        $fail('Per confermare scrivi la parola ELIMINA.');
    }
    $email = verified_email($uid);
    $blockers = delete_own_account($uid);
    if ($blockers) {
        $fail(implode(' ', $blockers));
    }
    log_activity('account', 'un account è stato eliminato dal suo proprietario', null, null);
    if ($email) {
        send_mail($email, 'Il tuo account Calcetto Manager è stato eliminato', "Ciao,\n\nAbbiamo eliminato il tuo account e i tuoi dati personali, come hai chiesto.\n\nIl tuo giocatore resta nelle partite e nelle classifiche già giocate come «Ex giocatore», senza nome né foto. I dati di fatturazione, se ne hai, li conserviamo solo perché la legge lo richiede.\n\nSe non sei stato tu, scrivici subito rispondendo a questa email.\n\n— " . APP_NAME . "\n");
    }
    remember_forget();
    $_SESSION = [];
    session_destroy();
    redirect('elimina_account.php?fatto=1');
}

$canDelete = feature_on('account_delete');
$social = feature_on('report_block');
$blockers = $canDelete ? account_blockers($uid) : [];
$blocks = $social ? my_blocks($uid) : [];
$reports = $social ? my_reports($uid) : [];
layout_start('Privacy e dati');
?>
<div class="narrow">
  <div class="page-head"><h1>Privacy e dati</h1></div>
  <p class="muted">Qui decidi cosa ne facciamo dei tuoi dati. Il testo completo è nell'<a class="link" href="<?= h(NEXT_SITE) ?>/privacy">informativa privacy</a>.</p>

  <section class="card" id="scarica">
    <h2><i class="ti ti-download"></i> Scarica i tuoi dati</h2>
    <p class="muted small">Un file con quello che sappiamo di te: account, giocatore, leghe, partite, pensieri, proposte, segnalazioni, scommesse e consensi. Mai la password. Si può scaricare poche volte all'ora.</p>
    <a class="btn btn-primary btn-sm" href="privacy_dati.php?scarica=1"><i class="ti ti-download"></i> Scarica (JSON)</a>
  </section>

  <?php if ($social): ?>
  <section class="card" id="blocchi">
    <h2><i class="ti ti-ban"></i> Giocatori bloccati</h2>
    <?php if (!$blocks): ?><p class="muted">Non hai bloccato nessuno. Il pulsante «Blocca» è sul profilo di ogni giocatore.</p>
    <?php else: ?><ul class="plain-list"><?php foreach ($blocks as $b): ?>
      <li><?= h($b['name']) ?> <form method="post" action="segnala.php" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="unblock"><input type="hidden" name="player_id" value="<?= (int) $b['player_id'] ?>"><input type="hidden" name="back" value="privacy_dati.php"><button class="btn btn-ghost btn-sm">Sblocca</button></form></li>
    <?php endforeach; ?></ul><?php endif; ?>
  </section>

  <section class="card" id="segnalazioni">
    <h2><i class="ti ti-flag"></i> Le tue segnalazioni</h2>
    <?php if (!$reports): ?><p class="muted">Non hai fatto segnalazioni.</p>
    <?php else: ?><div class="table-wrap"><table class="table"><thead><tr><th>Quando</th><th>Cosa</th><th>Motivo</th><th>Esito</th></tr></thead><tbody>
      <?php foreach ($reports as $r): ?>
      <tr><td class="small"><?= h(substr((string) $r['created_at'], 0, 10)) ?></td><td><?= h(REPORT_TYPES[$r['target_type']] ?? $r['target_type']) ?></td><td class="small"><?= h(REPORT_REASONS[$r['reason']] ?? $r['reason']) ?></td>
        <td><?php if ($r['status'] === 'aperta'): ?><span class="tag">in esame</span><?php else: ?><span class="tag tag-ok"><?= h($r['status']) ?></span> <span class="small muted"><?= h($r['outcome']) ?></span><?php endif; ?></td></tr>
      <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($canDelete): ?>
  <section class="card" id="elimina">
    <h2><i class="ti ti-trash"></i> Elimina il mio account</h2>
    <p>Cosa succede: l'account, l'email, le sessioni, le notifiche, i pensieri e le proposte che hai scritto spariscono. Il tuo giocatore resta nelle partite e nelle classifiche già giocate come <b>«Ex giocatore»</b>, senza nome né foto, così le statistiche degli altri non cambiano. <b>Non si torna indietro.</b></p>
    <?php if ($blockers): ?><div class="flash flash-warn"><?php foreach ($blockers as $b): ?><p><?= h($b) ?></p><?php endforeach; ?></div>
    <?php else: ?>
    <form method="post" class="form form-grid"><?= csrf_field() ?><input type="hidden" name="do" value="delete">
      <label class="field"><span>La tua password</span><input type="password" name="current" required autocomplete="current-password"></label>
      <label class="field"><span>Per confermare scrivi ELIMINA</span><input name="phrase" required autocomplete="off" autocapitalize="characters"></label>
      <div><button class="btn btn-danger" data-confirm="Eliminare definitivamente l'account?"><i class="ti ti-trash"></i> Elimina definitivamente</button></div>
    </form>
    <?php endif; ?>
    <p class="muted small">Non riesci ad accedere? Usa la <a class="link" href="elimina_account.php">pagina pubblica</a>.</p>
  </section>
  <?php endif; ?>
</div>
<?php layout_end();
