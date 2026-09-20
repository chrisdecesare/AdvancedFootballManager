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

const SCHEMA_VERSION = 9;

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
    q("INSERT INTO meta (k, v) VALUES ('schema', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [SCHEMA_VERSION]);
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
