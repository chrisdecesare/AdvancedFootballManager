-- Schema MySQL di Calcetto Manager (lo esegue install.php)

CREATE TABLE IF NOT EXISTS players (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  photo VARCHAR(255) NULL,
  shirt_number TINYINT UNSIGNED NULL,
  position VARCHAR(20) NOT NULL DEFAULT 'Centrocampista',   -- posizione preferita (mai Jolly)
  position2 VARCHAR(20) NULL,                      -- seconda posizione (facoltativa)
  foot VARCHAR(12) NOT NULL DEFAULT 'Destro',
  base_rating DECIMAL(3,1) NOT NULL DEFAULT 6.0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  -- correzioni manuali (es. statistiche precedenti al sito)
  adj_apps INT NOT NULL DEFAULT 0,
  adj_wins INT NOT NULL DEFAULT 0,
  adj_draws INT NOT NULL DEFAULT 0,
  adj_losses INT NOT NULL DEFAULT 0,
  adj_goals INT NOT NULL DEFAULT 0,
  adj_assists INT NOT NULL DEFAULT 0,
  adj_own_goals INT NOT NULL DEFAULT 0,
  adj_mvp INT NOT NULL DEFAULT 0,
  bg_color VARCHAR(7) NULL,                        -- sfondo del profilo: colore #rrggbb...
  bg_image VARCHAR(255) NULL,                      -- ...oppure immagine (uploads/players/b<id>_xxxx.jpg)
  bg_preset VARCHAR(16) NULL,                      -- ...oppure sfondo speciale comprato nel negozio (lib/shop.php)
  nick_key VARCHAR(16) NULL,                       -- nickname che porta adesso
  hat_key VARCHAR(16) NULL,                        -- copricapo che porta adesso
  border_key VARCHAR(16) NULL,                     -- bordo speciale che porta adesso
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','manager','player') NOT NULL DEFAULT 'player',   -- manager = crea e gestisce le partite dei suoi gruppi
  status ENUM('attivo','in_attesa') NOT NULL DEFAULT 'attivo',   -- in_attesa = iscrizione da approvare
  reg_name VARCHAR(80) NULL,                       -- dati inseriti all'iscrizione
  reg_json TEXT NULL,
  tour_done TINYINT(1) NOT NULL DEFAULT 0,         -- 1 = ha già visto (o saltato) il tutorial di benvenuto
  email VARCHAR(190) NULL,                         -- email confermata (serve al recupero della password)
  pending_email VARCHAR(190) NULL,                 -- email indicata ma non ancora confermata
  session_version INT NOT NULL DEFAULT 0,          -- cresce quando cambia la password: le sessioni con un numero diverso decadono
  player_id INT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE INDEX uq_users_email (email),
  FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS matches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  match_date DATETIME NOT NULL,
  location VARCHAR(120) NOT NULL DEFAULT '',
  team_a_name VARCHAR(40) NOT NULL DEFAULT '',
  team_b_name VARCHAR(40) NOT NULL DEFAULT '',
  formation_a VARCHAR(20) NOT NULL DEFAULT '',     -- modulo scelto (vuoto = automatico)
  formation_b VARCHAR(20) NOT NULL DEFAULT '',
  fee DECIMAL(6,2) NOT NULL DEFAULT 0,
  status ENUM('programmata','giocata') NOT NULL DEFAULT 'programmata',
  score_a TINYINT UNSIGNED NULL,
  score_b TINYINT UNSIGNED NULL,
  voting_open TINYINT(1) NOT NULL DEFAULT 0,
  voting_ends_at DATETIME NULL,                    -- quando terminano le votazioni (NULL = nessuna scadenza)
  notes TEXT NULL,
  group_id INT NOT NULL DEFAULT 1,                 -- gruppo (squadra/lega) a cui appartiene la partita
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (status, match_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS match_players (
  match_id INT NOT NULL,
  player_id INT NOT NULL,
  availability ENUM('in_attesa','confermato','assente') NOT NULL DEFAULT 'in_attesa',
  team ENUM('A','B') NULL,
  slot TINYINT UNSIGNED NULL,                      -- posizione nel modulo della squadra
  goals TINYINT UNSIGNED NOT NULL DEFAULT 0,
  assists TINYINT UNSIGNED NOT NULL DEFAULT 0,
  own_goals TINYINT UNSIGNED NOT NULL DEFAULT 0,
  paid TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (match_id, player_id),
  FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
  FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- voti 1-10 che ogni giocatore della partita dà agli altri
CREATE TABLE IF NOT EXISTS ratings (
  match_id INT NOT NULL,
  voter_id INT NOT NULL,
  rated_id INT NOT NULL,
  vote DECIMAL(3,1) NOT NULL,
  is_auto TINYINT(1) NOT NULL DEFAULT 0,           -- 1 = voto dato d'ufficio a chi non ha votato
  PRIMARY KEY (match_id, voter_id, rated_id),
  FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
  FOREIGN KEY (voter_id) REFERENCES players(id) ON DELETE CASCADE,
  FOREIGN KEY (rated_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mvp_votes (
  match_id INT NOT NULL,
  voter_id INT NOT NULL,
  voted_id INT NOT NULL,
  PRIMARY KEY (match_id, voter_id),
  FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
  FOREIGN KEY (voter_id) REFERENCES players(id) ON DELETE CASCADE,
  FOREIGN KEY (voted_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meta (
  k VARCHAR(40) PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- tentativi di accesso falliti (limita gli attacchi a forza bruta)
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(50) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip, created_at),
  INDEX (ip, username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- gruppi (es. YBQ, FANTA): ogni giocatore può far parte di più gruppi, ogni partita è di un gruppo
CREATE TABLE IF NOT EXISTS squad_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS player_groups (
  player_id INT NOT NULL,
  group_id INT NOT NULL,
  PRIMARY KEY (player_id, group_id),
  FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  FOREIGN KEY (group_id) REFERENCES squad_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO squad_groups (id, name) VALUES (1, 'Principale');

-- assist tra due giocatori in una partita (facoltativo): "assister ha servito scorer per n gol"
CREATE TABLE IF NOT EXISTS match_links (
  match_id INT NOT NULL,
  assister_id INT NOT NULL,
  scorer_id INT NOT NULL,
  n TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (match_id, assister_id, scorer_id),
  FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
  FOREIGN KEY (assister_id) REFERENCES players(id) ON DELETE CASCADE,
  FOREIGN KEY (scorer_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- notifiche push (Web Push): un record per ogni dispositivo che ha attivato le notifiche
CREATE TABLE IF NOT EXISTS push_subscriptions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- notifiche già mandate (per non ripetere i promemoria)
CREATE TABLE IF NOT EXISTS push_log (
  kind VARCHAR(20) NOT NULL,
  match_id INT NOT NULL,
  player_id INT NOT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (kind, match_id, player_id),
  FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- notifiche da spedire e gia' spedite (coda con riprove + registro di consegna: vedi lib/webpush.php)
CREATE TABLE IF NOT EXISTS push_queue (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- curiosità sui giocatori (le scrivono i giocatori stessi e l'admin)
CREATE TABLE IF NOT EXISTS curiosities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  player_id INT NOT NULL,
  body VARCHAR(300) NOT NULL,
  created_by INT NULL,                             -- id dell'account che l'ha scritta
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (player_id),
  FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO meta (k, v) VALUES ('push_last_run', '0');
-- "resta collegato": codici a lunga scadenza (nel cookie c'è selettore:codice, qui solo l'impronta del codice)
CREATE TABLE IF NOT EXISTS auth_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  selector CHAR(18) NOT NULL UNIQUE,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- codici monouso nei link delle email (conferma dell'indirizzo, recupero password): qui solo l'impronta del codice
CREATE TABLE IF NOT EXISTS mail_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  purpose VARCHAR(10) NOT NULL,                    -- 'verify' o 'reset'
  selector CHAR(18) NOT NULL UNIQUE,
  token_hash CHAR(64) NOT NULL,
  email VARCHAR(190) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, purpose),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- scommesse goliardiche (gettoni finti): una puntata per giocatore, partita e mercato
CREATE TABLE IF NOT EXISTS bets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  match_id INT NOT NULL,
  player_id INT NOT NULL,
  market VARCHAR(10) NOT NULL,                     -- esito, gol oppure mvp
  pick VARCHAR(12) NOT NULL,                       -- A, X, B oppure l'id del giocatore
  stake INT NOT NULL,
  odds DECIMAL(6,2) NOT NULL DEFAULT 2.00,         -- quota fissata quando si e' puntato
  status ENUM('aperta','vinta','persa','rimborsata') NOT NULL DEFAULT 'aperta',
  payout INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  settled_at DATETIME NULL,
  UNIQUE KEY uq_bet (match_id, player_id, market),
  INDEX (player_id),
  FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
  FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- il portafoglio e' la somma di queste mosse: benvenuto, sussidio, puntata, vincita, rimborso
CREATE TABLE IF NOT EXISTS wallet_moves (
  id INT AUTO_INCREMENT PRIMARY KEY,
  player_id INT NOT NULL,
  bet_id INT NULL,
  delta INT NOT NULL,
  kind VARCHAR(12) NOT NULL,
  ref VARCHAR(20) NULL,                            -- per le mosse che si danno una volta sola (benvenuto, sussidio settimanale)
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ref (player_id, ref),
  INDEX (bet_id),
  FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  FOREIGN KEY (bet_id) REFERENCES bets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- personalizzazioni comprate nel negozio con i gettoni (cosa si porta adesso sta in players)
CREATE TABLE IF NOT EXISTS player_items (
  player_id INT NOT NULL,
  item_key VARCHAR(16) NOT NULL,
  price INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id, item_key),
  FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO meta (k, v) VALUES ('schema', '17');
