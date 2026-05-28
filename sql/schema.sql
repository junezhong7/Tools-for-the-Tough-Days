-- Tools for the Tough Days — Database Schema
-- Run this once to initialise the database.
-- Compatible with MySQL 8.0+ and MariaDB 10.5+

CREATE DATABASE IF NOT EXISTS tttd CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tttd;

-- ─────────────────────────────────────────────
-- USERS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  email           VARCHAR(255)    NOT NULL,
  password_hash   VARCHAR(255)    NOT NULL,
  full_name       VARCHAR(255)        NULL DEFAULT NULL,
  is_business_user TINYINT(1)     NOT NULL DEFAULT 0,
  business_name   VARCHAR(255)        NULL DEFAULT NULL,
  status          ENUM('active','suspended','unverified') NOT NULL DEFAULT 'active',
  stripe_customer_id VARCHAR(64)  NULL DEFAULT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_stripe_customer (stripe_customer_id),
  KEY idx_users_is_business_user (is_business_user),
  CONSTRAINT chk_users_business_name
    CHECK (
      (is_business_user = 0 AND business_name IS NULL)
      OR
      (is_business_user = 1 AND business_name IS NOT NULL AND TRIM(business_name) <> '')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- SESSIONS  (server-side, replaces PHP default file sessions)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_sessions (
  id              CHAR(64)        NOT NULL,   -- random hex token stored in cookie
  user_id         INT UNSIGNED    NOT NULL,
  ip_address      VARCHAR(45)         NULL,
  user_agent      VARCHAR(512)        NULL,
  last_active     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at      DATETIME        NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- PASSWORD RESET TOKENS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  token_hash      CHAR(64)        NOT NULL,
  user_id         INT UNSIGNED    NOT NULL,
  requested_ip    VARCHAR(45)         NULL,
  user_agent      VARCHAR(512)        NULL,
  expires_at      DATETIME        NOT NULL,
  used_at         DATETIME            NULL DEFAULT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_hash),
  KEY idx_prt_user (user_id),
  KEY idx_prt_expiry (expires_at),
  KEY idx_prt_used (used_at),
  CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- SUBSCRIPTIONS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS subscriptions (
  id                          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  user_id                     INT UNSIGNED    NOT NULL,
  stripe_subscription_id      VARCHAR(64)         NULL DEFAULT NULL,
  stripe_customer_id          VARCHAR(64)         NULL DEFAULT NULL,
  product_key                 VARCHAR(64)     NOT NULL,  -- e.g. 'individual_monthly'
  plan_type                   ENUM('individual','business','counselling') NOT NULL,
  status                      ENUM('active','cancelled','past_due','unpaid','trialing','paused','pending') NOT NULL DEFAULT 'pending',
  current_period_start        DATETIME            NULL DEFAULT NULL,
  current_period_end          DATETIME            NULL DEFAULT NULL,
  cancel_at_period_end        TINYINT(1)      NOT NULL DEFAULT 0,
  cancelled_at                DATETIME            NULL DEFAULT NULL,
  stripe_checkout_session_id  VARCHAR(128)        NULL DEFAULT NULL,
  created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sub_stripe_id (stripe_subscription_id),
  UNIQUE KEY uq_sub_checkout_session (stripe_checkout_session_id),
  KEY idx_sub_user (user_id),
  KEY idx_sub_customer (stripe_customer_id),
  CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- PAYMENTS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payments (
  id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  user_id                 INT UNSIGNED    NOT NULL,
  subscription_id         INT UNSIGNED        NULL DEFAULT NULL,
  stripe_payment_intent_id VARCHAR(64)        NULL DEFAULT NULL,
  stripe_invoice_id       VARCHAR(64)         NULL DEFAULT NULL,
  amount_cents            INT UNSIGNED    NOT NULL,
  currency                CHAR(3)         NOT NULL DEFAULT 'aud',
  status                  ENUM('pending','succeeded','failed','refunded') NOT NULL DEFAULT 'pending',
  description             VARCHAR(255)        NULL DEFAULT NULL,
  created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pay_user (user_id),
  KEY idx_pay_subscription (subscription_id),
  UNIQUE KEY uq_pay_intent (stripe_payment_intent_id),
  CONSTRAINT fk_pay_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_sub  FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- AUDIT LOG
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED        NULL DEFAULT NULL,
  action      VARCHAR(128)    NOT NULL,
  details     JSON                NULL DEFAULT NULL,
  ip_address  VARCHAR(45)         NULL DEFAULT NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_action (action),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- MOOD TRACKER
-- ─────────────────────────────────────────────
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
