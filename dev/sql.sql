CREATE DATABASE winston99db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON winston99db.* TO 'winston99db'@'localhost' IDENTIFIED BY 'winston99dbpassword';
FLUSH PRIVILEGES;

USE winston99db;

CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('writer','observer','editor','supervisor','admin') NOT NULL,
  `username` VARCHAR(32) NOT NULL,
  `email` VARCHAR(90) NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `project` VARCHAR(80) DEFAULT NULL,
  `level` BIGINT UNSIGNED DEFAULT 0,
  `groups` JSON NOT NULL,
  `blocks` JSON NOT NULL,
  `observing` JSON NOT NULL,
  `editor` BIGINT UNSIGNED DEFAULT NULL,
  `status` ENUM('signup','active','dormant','grad') NOT NULL,
  `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pass` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;
-- a NULL JSON column will break JSON_CONTAINS CONCAT statements

CREATE TABLE IF NOT EXISTS `notes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `writer_id` BIGINT UNSIGNED DEFAULT NULL,
  `editor_id` BIGINT UNSIGNED DEFAULT NULL,
  `editor_set_writer_id` BIGINT UNSIGNED NOT 0,
  `editor_set_block` BIGINT UNSIGNED NOT 0,
  `body` TEXT DEFAULT NULL,
  `pinned` BOOLEAN NOT NULL DEFAULT 0,
  `group` BIGINT UNSIGNED NOT NULL,
  `save_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `blocks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `editor_id` BIGINT UNSIGNED NOT NULL,
  `name` TINYTEXT DEFAULT NULL,
  `code` VARCHAR(10) DEFAULT NULL,
  `status` ENUM('open', 'closed') NOT NULL,
  `project` BIGINT UNSIGNED DEFAULT 0,
  `series` BIGINT UNSIGNED DEFAULT 0,
  `group` BIGINT UNSIGNED DEFAULT 0,
  `level` BIGINT UNSIGNED DEFAULT 0,
  `creation_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `writs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `writer_id` BIGINT UNSIGNED NOT NULL,
  `project` BIGINT UNSIGNED DEFAULT 0,
  `block` BIGINT UNSIGNED DEFAULT 0,
  `level` BIGINT UNSIGNED DEFAULT 0,
  `type` ENUM('writ', 'task', 'test') NOT NULL,
  `task` BIGINT UNSIGNED DEFAULT 0,
  `instructions` MEDIUMTEXT DEFAULT NULL,
  `term_status` ENUM('current', 'archived') NOT NULL,
  `review_status` ENUM('current', 'archived') NOT NULL,
  `title` VARCHAR(122) DEFAULT NULL,
  `work` VARCHAR(122) DEFAULT NULL,
  `score` INT UNSIGNED DEFAULT NULL,
  `outof` INT UNSIGNED DEFAULT 100,
  `task_title` TEXT DEFAULT NULL,
  `task_content` MEDIUMTEXT DEFAULT NULL,
  `draft` MEDIUMTEXT DEFAULT NULL,
  `draft_wordcount` INT UNSIGNED DEFAULT 0,
  `correction_ontime` ENUM('ontime', 'late') NOT NULL,
  `draft_ontime` ENUM('ontime', 'late') NOT NULL,
  `draft_status` ENUM('saved', 'submitted', 'reviewed') NOT NULL,
  `edits` MEDIUMTEXT DEFAULT NULL,
  `edits_wordcount` INT UNSIGNED DEFAULT 0,
  `edit_notes` TEXT DEFAULT NULL,
  `edits_status` ENUM('drafting', 'viewed', 'saved', 'submitted', 'scored') NOT NULL,
  `correction` MEDIUMTEXT DEFAULT NULL,
  `correction_wordcount` INT UNSIGNED DEFAULT 0,
  `scoring` TEXT DEFAULT NULL,
  `notes` MEDIUMTEXT DEFAULT NULL,
  `draft_open_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `draft_save_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `draft_submit_date` TIMESTAMP NULL DEFAULT NULL,
  `edits_date` TIMESTAMP NULL DEFAULT NULL,
  `edits_viewed_date` TIMESTAMP NULL DEFAULT NULL,
  `corrected_save_date` TIMESTAMP NULL DEFAULT NULL,
  `corrected_submit_date` TIMESTAMP NULL DEFAULT NULL,
  `scoring_date` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `clickathon` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username_list` MEDIUMTEXT NOT NULL,
  `ip` MEDIUMTEXT NOT NULL,
  `time_stamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `time_epoch` INT UNSIGNED NOT NULL,
  `unlocked` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- Future dev

CREATE TABLE IF NOT EXISTS `tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `editor_id` BIGINT UNSIGNED NOT NULL,
  `block` BIGINT UNSIGNED DEFAULT 0,
  `name` TINYTEXT DEFAULT NULL,
  `code` VARCHAR(10) DEFAULT NULL,
  `type` ENUM('block', 'open') NOT NULL,
  `status` ENUM('current', 'archived') NOT NULL,
  `title` VARCHAR(122) DEFAULT NULL,
  `work` VARCHAR(122) DEFAULT NULL,
  `instructions` MEDIUMTEXT DEFAULT NULL,
  `project` BIGINT UNSIGNED DEFAULT 0,
  `series` BIGINT UNSIGNED DEFAULT 0,
  `issue` BIGINT UNSIGNED DEFAULT 0,
  `level` BIGINT UNSIGNED DEFAULT 0,
  `save_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `task_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `editor_id` BIGINT UNSIGNED NOT NULL,
  `block` BIGINT UNSIGNED DEFAULT 0,
  `name` TINYTEXT DEFAULT NULL,
  `code` VARCHAR(10) DEFAULT NULL,
  `type` ENUM('editor','super') NOT NULL,
  `status` ENUM('current', 'archived') NOT NULL,
  `title` VARCHAR(122) DEFAULT NULL,
  `work` VARCHAR(122) DEFAULT NULL,
  `instructions` MEDIUMTEXT DEFAULT NULL,
  `project` BIGINT UNSIGNED DEFAULT 0,
  `series` BIGINT UNSIGNED DEFAULT 0,
  `issue` BIGINT UNSIGNED DEFAULT 0,
  `level` BIGINT UNSIGNED DEFAULT 0,
  `save_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `groups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `editor_id` BIGINT UNSIGNED NOT NULL,
  `name` TINYTEXT DEFAULT NULL,
  `code` VARCHAR(10) DEFAULT NULL,
  `status` ENUM('current', 'closed') NOT NULL,
  `description` MEDIUMTEXT DEFAULT NULL,
  `blocks` JSON NOT NULL,
  `editors` JSON NOT NULL,
  `levels` JSON NOT NULL,
  `save_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `group_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` BIGINT UNSIGNED NOT NULL,
  `writer_id` BIGINT UNSIGNED NOT NULL,
  `save_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- Dev cheatsheet
-- UPDATE users SET groups='[""]' WHERE groups IS NULL;
-- ALTER TABLE `winston99db`.`clickathon` ADD `time_epoch`  INT UNSIGNED NOT NULL;
ALTER TABLE `winston99db`.`clickathon` ADD `unlocked` TIMESTAMP NULL DEFAULT NULL;

-- Select my observees
-- SELECT id FROM users w WHERE EXISTS (SELECT 1 FROM users u WHERE JSON_CONTAINS(u.observing, CONCAT('\"', w.id, '\"')) AND u.id = '$userid');
-- Return user 1 ID if observing user 2
-- SELECT id FROM users WHERE JSON_CONTAINS(observing, CONCAT('\"', 2, '\"')) AND id='1';
