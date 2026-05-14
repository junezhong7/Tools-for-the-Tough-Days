-- Add mood tracking and alert tables.
-- Run this once on existing environments after taking a backup.

CREATE TABLE IF NOT EXISTS mood_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED    NOT NULL,
  mood_score    TINYINT UNSIGNED NOT NULL,
  source_page   VARCHAR(64)         NULL DEFAULT NULL,
  client_ts     DATETIME            NULL DEFAULT NULL,
  checkin_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mood_events_user_time (user_id, checkin_at),
  KEY idx_mood_events_user_created (user_id, created_at),
  CONSTRAINT fk_mood_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_mood_score_range CHECK (mood_score BETWEEN 1 AND 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mood_alerts (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED    NOT NULL,
  alert_type        VARCHAR(32)     NOT NULL,
  status            ENUM('open','resolved') NOT NULL DEFAULT 'open',
  rule_window_start DATE                NULL DEFAULT NULL,
  rule_window_end   DATE                NULL DEFAULT NULL,
  meta              JSON                NULL DEFAULT NULL,
  triggered_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at       DATETIME            NULL DEFAULT NULL,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mood_alerts_user_status_time (user_id, status, triggered_at),
  KEY idx_mood_alerts_user_type_status (user_id, alert_type, status),
  CONSTRAINT fk_mood_alerts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
