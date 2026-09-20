-- Winston 99 current schema. InnoDB + utf8mb4. Installer applies this.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS schema_version (
  version INT UNSIGNED NOT NULL PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS facilities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(16) DEFAULT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type ENUM('writer','observer','editor','supervisor','admin','superintendent') NOT NULL,
  facility_id BIGINT UNSIGNED DEFAULT NULL,
  username VARCHAR(32) NOT NULL,
  email VARCHAR(120) NOT NULL,
  name VARCHAR(80) NOT NULL,
  project VARCHAR(80) DEFAULT NULL,
  level BIGINT UNSIGNED DEFAULT 0,
  groups_json JSON NOT NULL,
  blocks_json JSON NOT NULL,
  observing_json JSON NOT NULL,
  editor_id BIGINT UNSIGNED DEFAULT NULL,
  status ENUM('signup','active','dormant','grad') NOT NULL DEFAULT 'active',
  pass VARCHAR(255) DEFAULT NULL,
  pass_login TINYINT(1) NOT NULL DEFAULT 1,
  totp_secret VARCHAR(64) DEFAULT NULL,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  notify_prefs JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_seen TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY username (username),
  UNIQUE KEY email (email),
  KEY facility_id (facility_id),
  KEY editor_id (editor_id),
  CONSTRAINT users_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS oauth_identities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(16) NOT NULL,
  subject VARCHAR(191) NOT NULL,
  email VARCHAR(160) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY provider_subject (provider, subject),
  KEY user_id (user_id),
  CONSTRAINT oauth_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS passkeys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  credential_id VARBINARY(255) NOT NULL,
  public_key TEXT NOT NULL,
  sign_count INT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(80) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY credential_id (credential_id),
  KEY user_id (user_id),
  CONSTRAINT passkeys_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS totp_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  selector CHAR(16) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY selector (selector),
  KEY user_id (user_id),
  CONSTRAINT totp_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY user_id (user_id),
  CONSTRAINT resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type ENUM('note','memo','task') NOT NULL DEFAULT 'note',
  status ENUM('live','draft','archived') NOT NULL DEFAULT 'live',
  writer_id BIGINT UNSIGNED DEFAULT NULL,
  editor_id BIGINT UNSIGNED DEFAULT NULL,
  editor_set_writer_id BIGINT UNSIGNED DEFAULT 0,
  editor_set_block BIGINT UNSIGNED DEFAULT 0,
  body MEDIUMTEXT,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  seen_writer ENUM('new','read','archived') NOT NULL DEFAULT 'new',
  seen_observer ENUM('new','read','archived') NOT NULL DEFAULT 'new',
  writing_time INT UNSIGNED DEFAULT 0,
  save_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY writer_id (writer_id),
  KEY editor_id (editor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS blocks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  facility_id BIGINT UNSIGNED DEFAULT NULL,
  editor_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) DEFAULT NULL,
  code VARCHAR(10) DEFAULT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  project BIGINT UNSIGNED DEFAULT 0,
  series BIGINT UNSIGNED DEFAULT 0,
  group_id BIGINT UNSIGNED DEFAULT 0,
  level BIGINT UNSIGNED DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY facility_id (facility_id),
  KEY editor_id (editor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  editor_id BIGINT UNSIGNED NOT NULL,
  facility_id BIGINT UNSIGNED DEFAULT NULL,
  block_id BIGINT UNSIGNED DEFAULT 0,
  title VARCHAR(122) DEFAULT NULL,
  source MEDIUMTEXT NOT NULL,
  parsed JSON NOT NULL,
  status ENUM('draft','current','archived') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY editor_id (editor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS writs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  writer_id BIGINT UNSIGNED NOT NULL,
  facility_id BIGINT UNSIGNED DEFAULT NULL,
  project BIGINT UNSIGNED DEFAULT 0,
  block_id BIGINT UNSIGNED DEFAULT 0,
  level BIGINT UNSIGNED DEFAULT 0,
  kind ENUM('writ','assignment','test') NOT NULL DEFAULT 'writ',
  memo_id BIGINT UNSIGNED DEFAULT NULL,
  test_id BIGINT UNSIGNED DEFAULT NULL,
  instructions MEDIUMTEXT,
  term_status ENUM('current','archived') NOT NULL DEFAULT 'current',
  review_status ENUM('current','archived') NOT NULL DEFAULT 'current',
  title VARCHAR(122) DEFAULT NULL,
  work VARCHAR(122) DEFAULT NULL,
  score INT UNSIGNED DEFAULT NULL,
  outof INT UNSIGNED DEFAULT 100,
  draft MEDIUMTEXT,
  draft_wordcount INT UNSIGNED DEFAULT 0,
  drafts JSON NOT NULL,
  redrafts JSON NOT NULL,
  draft_status ENUM('saved','submitted','reviewed','redraft') NOT NULL DEFAULT 'saved',
  edits MEDIUMTEXT,
  edits_wordcount INT UNSIGNED DEFAULT 0,
  edit_notes TEXT,
  edits_status ENUM('drafting','viewed','saved','submitted','scored') NOT NULL DEFAULT 'drafting',
  correction MEDIUMTEXT,
  correction_wordcount INT UNSIGNED DEFAULT 0,
  scoring TEXT,
  notes MEDIUMTEXT,
  writing_time INT UNSIGNED DEFAULT 0,
  test_answers JSON DEFAULT NULL,
  test_auto_score INT UNSIGNED DEFAULT NULL,
  draft_open_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  draft_save_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  draft_submit_date TIMESTAMP NULL DEFAULT NULL,
  edits_date TIMESTAMP NULL DEFAULT NULL,
  edits_viewed_date TIMESTAMP NULL DEFAULT NULL,
  corrected_save_date TIMESTAMP NULL DEFAULT NULL,
  corrected_submit_date TIMESTAMP NULL DEFAULT NULL,
  scoring_date TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY writer_id (writer_id),
  KEY block_id (block_id),
  KEY kind (kind),
  KEY draft_status (draft_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  title VARCHAR(160) NOT NULL,
  body VARCHAR(255) DEFAULT NULL,
  link VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY user_created (user_id, created_at),
  CONSTRAINT notify_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS writ_comments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  writ_id BIGINT UNSIGNED NOT NULL,
  observer_id BIGINT UNSIGNED NOT NULL,
  body MEDIUMTEXT,
  save_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY writ_id (writ_id),
  KEY observer_id (observer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clickathon (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username_list MEDIUMTEXT NOT NULL,
  ip VARCHAR(45) NOT NULL,
  time_stamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  time_epoch INT UNSIGNED NOT NULL,
  unlocked TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ip_epoch (ip, time_epoch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  actor_id BIGINT UNSIGNED DEFAULT NULL,
  actor_name VARCHAR(120) DEFAULT NULL,
  action VARCHAR(32) NOT NULL,
  detail VARCHAR(255) DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY user_id (user_id),
  KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_version (version, note) VALUES (1, 'initial pdo/oop schema');

SET FOREIGN_KEY_CHECKS = 1;
