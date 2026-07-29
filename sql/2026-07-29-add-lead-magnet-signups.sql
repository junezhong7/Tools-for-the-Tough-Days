USE tttd;

CREATE TABLE IF NOT EXISTS lead_magnet_signups (
  id                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  email             VARCHAR(255)    NOT NULL,
  lead_magnet       VARCHAR(64)     NOT NULL DEFAULT '8_tools_guide',
  newsletter_opt_in TINYINT(1)      NOT NULL DEFAULT 0,
  email_sent        TINYINT(1)      NOT NULL DEFAULT 0,
  utm_source        VARCHAR(64)         NULL DEFAULT NULL,
  utm_medium        VARCHAR(64)         NULL DEFAULT NULL,
  utm_campaign      VARCHAR(128)        NULL DEFAULT NULL,
  utm_term          VARCHAR(255)        NULL DEFAULT NULL,
  utm_content       VARCHAR(255)        NULL DEFAULT NULL,
  gclid             VARCHAR(128)        NULL DEFAULT NULL,
  fbclid            VARCHAR(128)        NULL DEFAULT NULL,
  landing_page      VARCHAR(512)        NULL DEFAULT NULL,
  signup_referrer   VARCHAR(512)        NULL DEFAULT NULL,
  ip_address        VARCHAR(45)         NULL DEFAULT NULL,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lead_magnet_email (email),
  KEY idx_lead_magnet_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
