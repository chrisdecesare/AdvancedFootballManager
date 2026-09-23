<?php
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . (defined('DB_PORT') ? ';port=' . DB_PORT : '') . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (PDOException $e) {
            http_response_code(500);
            die('Connessione al database non riuscita: controlla config.php.');
        }
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function tables_exist(): bool
{
    return (bool) q("SHOW TABLES LIKE 'users'")->fetch();
}

const SCHEMA_VERSION = 20;

/** Aggiorna il database di un'installazione precedente (aggiunge colonne nuove). */
function ensure_schema(): void
{
    try {
        $v = (int) q("SELECT v FROM meta WHERE k = 'schema'")->fetchColumn();
    } catch (PDOException $e) {
        db()->exec('CREATE TABLE IF NOT EXISTS meta (k VARCHAR(40) PRIMARY KEY, v VARCHAR(255) NOT NULL)
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $v = 1;
    }
    if ($v >= SCHEMA_VERSION) {
        return;
    }
    try {
    $add = function (string $table, string $col, string $def) {
        if (!q("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch()) {
            db()->exec("ALTER TABLE `$table` ADD COLUMN $col $def");
        }
    };
    if ($v < 2) {
        $add('players', 'position2', 'VARCHAR(20) NULL AFTER position');
        $add('matches', 'team_a_name', "VARCHAR(40) NOT NULL DEFAULT '' AFTER location");
        $add('matches', 'team_b_name', "VARCHAR(40) NOT NULL DEFAULT '' AFTER team_a_name");
        $add('match_players', 'slot', 'TINYINT UNSIGNED NULL AFTER team');
    }
    if ($v < 3) {
        $add('matches', 'formation_a', "VARCHAR(20) NOT NULL DEFAULT '' AFTER team_b_name");
        $add('matches', 'formation_b', "VARCHAR(20) NOT NULL DEFAULT '' AFTER formation_a");
        $add('users', 'status', "ENUM('attivo','in_attesa') NOT NULL DEFAULT 'attivo' AFTER role");
        $add('users', 'reg_name', 'VARCHAR(80) NULL AFTER status');
        $add('users', 'reg_json', 'TEXT NULL AFTER reg_name');
    }
    if ($v < 4) {
        db()->exec('CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            username VARCHAR(50) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (ip, created_at),
            INDEX (ip, username, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if ($v < 5) {
        // il Jolly ora è solo seconda preferenza: chi lo aveva come prima passa alla sua vecchia seconda
        // scelta (o a Centrocampista se non ne aveva una) e tiene Jolly come seconda
        db()->exec("UPDATE players SET position = COALESCE(NULLIF(position2, 'Jolly'), 'Centrocampista'), position2 = 'Jolly'
                    WHERE position = 'Jolly'");
        db()->exec("ALTER TABLE players ALTER COLUMN `position` SET DEFAULT 'Centrocampista'");
    }
    if ($v < 6) {
        // tutorial di benvenuto: chi ha già un account attivo non è "nuovo", quindi non lo rivede da solo
        // (chi è ancora in attesa di approvazione lo vedrà al primo accesso)
        $add('users', 'tour_done', 'TINYINT(1) NOT NULL DEFAULT 0');
        db()->exec("UPDATE users SET tour_done = 1 WHERE status = 'attivo'");
    }
    if ($v < 7) {
        // gruppi: tutto quello che esiste già finisce nel gruppo "Principale" (l'admin lo rinomina, es. YBQ)
        db()->exec('CREATE TABLE IF NOT EXISTS squad_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(40) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        db()->exec('CREATE TABLE IF NOT EXISTS player_groups (
            player_id INT NOT NULL,
            group_id INT NOT NULL,
            PRIMARY KEY (player_id, group_id),
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES squad_groups(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        db()->exec("INSERT IGNORE INTO squad_groups (id, name) VALUES (1, 'Principale')");
        $add('matches', 'group_id', 'INT NOT NULL DEFAULT 1');
        db()->exec('INSERT IGNORE INTO player_groups (player_id, group_id) SELECT id, 1 FROM players');
        db()->exec('CREATE TABLE IF NOT EXISTS match_links (
            match_id INT NOT NULL,
            assister_id INT NOT NULL,
            scorer_id INT NOT NULL,
            n TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (match_id, assister_id, scorer_id),
            FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
            FOREIGN KEY (assister_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (scorer_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if ($v < 8) {
        $add('players', 'bg_color', 'VARCHAR(7) NULL');
        $add('players', 'bg_image', 'VARCHAR(255) NULL');
    }
    if ($v < 9) {
        // ruolo "manager" (crea e gestisce le partite dei suoi gruppi), notifiche push e curiosita' dei giocatori
        db()->exec("ALTER TABLE users MODIFY role ENUM('admin','manager','player') NOT NULL DEFAULT 'player'");
        db()->exec('CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL UNIQUE,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(120) NOT NULL,
            auth VARCHAR(40) NOT NULL,
            ua VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        db()->exec('CREATE TABLE IF NOT EXISTS push_log (
            kind VARCHAR(20) NOT NULL,
            match_id INT NOT NULL,
            player_id INT NOT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (kind, match_id, player_id),
            FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        db()->exec('CREATE TABLE IF NOT EXISTS curiosities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            player_id INT NOT NULL,
            body VARCHAR(300) NOT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (player_id),
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        db()->exec("INSERT IGNORE INTO meta (k, v) VALUES ('push_last_run', '0')");
    }
    if ($v < 10) {
        // "resta collegato": un codice a lunga scadenza nel cookie, così non si rifà l'accesso ogni volta
        db()->exec('CREATE TABLE IF NOT EXISTS auth_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            selector CHAR(18) NOT NULL UNIQUE,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if ($v < 11) {
        // voti dati d'ufficio (DEFAULT_VOTE) a chi non ha votato entro la chiusura delle votazioni
        $add('ratings', 'is_auto', 'TINYINT(1) NOT NULL DEFAULT 0');
    }
    if ($v < 12) {
        // orario in cui terminano le votazioni (NULL = nessuna scadenza: le chiude chi gestisce la partita)
        $add('matches', 'voting_ends_at', 'DATETIME NULL');
    }
    if ($v < 13) {
        // email dell'account (confermata = in `email`, da confermare = in `pending_email`), invalidazione delle sessioni e codici monouso
        $add('users', 'email', 'VARCHAR(190) NULL');
        $add('users', 'pending_email', 'VARCHAR(190) NULL');
        $add('users', 'session_version', 'INT NOT NULL DEFAULT 0');
        if (!q("SHOW INDEX FROM users WHERE Key_name = 'uq_users_email'")->fetch()) {
            db()->exec('ALTER TABLE users ADD UNIQUE INDEX uq_users_email (email)');
        }
        db()->exec('CREATE TABLE IF NOT EXISTS mail_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            purpose VARCHAR(10) NOT NULL,
            selector CHAR(18) NOT NULL UNIQUE,
            token_hash CHAR(64) NOT NULL,
            email VARCHAR(190) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id, purpose),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if ($v < 14) {
        // scommesse goliardiche con gettoni finti (vedi lib/bets.php)
        db()->exec("CREATE TABLE IF NOT EXISTS bets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            match_id INT NOT NULL,
            player_id INT NOT NULL,
            market VARCHAR(10) NOT NULL,
            pick VARCHAR(12) NOT NULL,
            stake INT NOT NULL,
            status ENUM('aperta','vinta','persa','rimborsata') NOT NULL DEFAULT 'aperta',
            payout INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            settled_at DATETIME NULL,
            UNIQUE KEY uq_bet (match_id, player_id, market),
            INDEX (player_id),
            FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec('CREATE TABLE IF NOT EXISTS wallet_moves (
            id INT AUTO_INCREMENT PRIMARY KEY,
            player_id INT NOT NULL,
            bet_id INT NULL,
            delta INT NOT NULL,
            kind VARCHAR(12) NOT NULL,
            ref VARCHAR(20) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ref (player_id, ref),
            INDEX (bet_id),
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (bet_id) REFERENCES bets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if ($v < 15) {
        // scommesse a quota fissa e negozio delle personalizzazioni del profilo (vedi lib/bets.php e lib/shop.php)
        $add('bets', 'odds', 'DECIMAL(6,2) NOT NULL DEFAULT 2.00 AFTER stake');
        $add('players', 'bg_preset', 'VARCHAR(16) NULL');
        $add('players', 'nick_key', 'VARCHAR(16) NULL');
        $add('players', 'hat_key', 'VARCHAR(16) NULL');
        db()->exec('CREATE TABLE IF NOT EXISTS player_items (
            player_id INT NOT NULL,
            item_key VARCHAR(16) NOT NULL,
            price INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (player_id, item_key),
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if ($v < 16) {
        // bordi speciali del profilo (negozio)
        $add('players', 'border_key', 'VARCHAR(16) NULL');
    }
    if ($v < 17) {
        // regalo una tantum: 60 gettoni al giocatore dell'admin (il codice 'gift-adm-60' impedisce di darli due volte)
        db()->exec("INSERT IGNORE INTO wallet_moves (player_id, delta, kind, ref)
                    SELECT player_id, 60, 'regalo', 'gift-adm-60' FROM users WHERE role = 'admin' AND status = 'attivo' AND player_id IS NOT NULL");
    }
    if ($v < 18) {
        // coda delle notifiche: si riprova a spedirle quando il servizio push non risponde, e resta il registro
        db()->exec("CREATE TABLE IF NOT EXISTS push_queue (
              id INT AUTO_INCREMENT PRIMARY KEY,
              kind VARCHAR(20) NOT NULL,                       -- nuova partita, iscrizione, promemoria... (serve al registro in Admin)
              user_id INT NOT NULL,
              sub_id INT NULL,                                 -- dispositivo destinatario (NULL se nel frattempo e' sparito)
              title VARCHAR(120) NOT NULL,
              payload TEXT NOT NULL,                           -- il messaggio in JSON, come arriva al browser
              urgency VARCHAR(10) NOT NULL DEFAULT 'normal',
              status ENUM('in_attesa','consegnata','fallita') NOT NULL DEFAULT 'in_attesa',
              attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
              next_try DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              claim CHAR(12) NULL,                             -- chi la sta spedendo adesso (evita invii doppi)
              last_code SMALLINT NOT NULL DEFAULT 0,           -- risposta del servizio push (0 = nessuna)
              last_error VARCHAR(190) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              sent_at DATETIME NULL,
              INDEX (status, next_try),
              INDEX (created_at),
              FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              FOREIGN KEY (sub_id) REFERENCES push_subscriptions(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if ($v < 19) {
        // scommesse multiple (combo): più selezioni in un'unica giocata, quota combinata = prodotto delle quote (vedi lib/bets.php)
        db()->exec('CREATE TABLE IF NOT EXISTS combo_bets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            player_id INT NOT NULL,
            stake INT NOT NULL,
            odds DECIMAL(8,2) NOT NULL,
            status ENUM(\'aperta\',\'vinta\',\'persa\',\'rimborsata\') NOT NULL DEFAULT \'aperta\',
            payout INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            settled_at DATETIME NULL,
            INDEX (player_id),
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        db()->exec('CREATE TABLE IF NOT EXISTS combo_legs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            combo_id INT NOT NULL,
            match_id INT NOT NULL,
            market VARCHAR(10) NOT NULL,
            pick VARCHAR(12) NOT NULL,
            odds DECIMAL(6,2) NOT NULL,
            status ENUM(\'aperta\',\'vinta\',\'persa\',\'rimborsata\') NOT NULL DEFAULT \'aperta\',
            UNIQUE KEY uq_leg (combo_id, match_id, market),
            INDEX (match_id, market),
            FOREIGN KEY (combo_id) REFERENCES combo_bets(id) ON DELETE CASCADE,
            FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $add('wallet_moves', 'combo_id', 'INT NULL AFTER bet_id');
        if (!q("SHOW INDEX FROM wallet_moves WHERE Key_name = 'combo_id'")->fetch()) {
            db()->exec('ALTER TABLE wallet_moves ADD INDEX (combo_id)');
        }
        if (!q("SHOW KEYS FROM wallet_moves WHERE Key_name = 'fk_wallet_combo'")->fetch()) {
            db()->exec('ALTER TABLE wallet_moves ADD CONSTRAINT fk_wallet_combo FOREIGN KEY (combo_id) REFERENCES combo_bets(id) ON DELETE CASCADE');
        }
    }
    if ($v < 20) {
        // ora si può puntare su più scelte dello stesso mercato della stessa partita (es. due marcatori diversi), non solo una:
        // il limite "una per mercato" diventa "una per scelta", sia per le singole che per le gambe delle multiple
        if (q("SHOW KEYS FROM bets WHERE Key_name = 'uq_bet'")->fetch()) {
            db()->exec('ALTER TABLE bets DROP INDEX uq_bet');
        }
        if (!q("SHOW KEYS FROM bets WHERE Key_name = 'uq_bet_pick'")->fetch()) {
            db()->exec('ALTER TABLE bets ADD UNIQUE KEY uq_bet_pick (match_id, player_id, market, pick)');
        }
        if (q("SHOW KEYS FROM combo_legs WHERE Key_name = 'uq_leg'")->fetch()) {
            db()->exec('ALTER TABLE combo_legs DROP INDEX uq_leg');
        }
        if (!q("SHOW KEYS FROM combo_legs WHERE Key_name = 'uq_leg_pick'")->fetch()) {
            db()->exec('ALTER TABLE combo_legs ADD UNIQUE KEY uq_leg_pick (combo_id, match_id, market, pick)');
        }
    }
    q("INSERT INTO meta (k, v) VALUES ('schema', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [SCHEMA_VERSION]);
    } catch (Throwable $e) {
        // una migrazione non è andata a buon fine (es. un lock, un permesso mancante): il sito continua a funzionare
        // con lo schema attuale invece di rompersi su ogni pagina; si riprova al prossimo caricamento.
        error_log('ensure_schema: ' . $e->getMessage());
    }
}

function meta_get(string $k): ?string
{
    $v = q('SELECT v FROM meta WHERE k = ?', [$k])->fetchColumn();
    return $v === false ? null : (string) $v;
}

function meta_set(string $k, string $v): void
{
    q('INSERT INTO meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}
