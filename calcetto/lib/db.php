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

const SCHEMA_VERSION = 5;

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
    q("INSERT INTO meta (k, v) VALUES ('schema', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [SCHEMA_VERSION]);
}
