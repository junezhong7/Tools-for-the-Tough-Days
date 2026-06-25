-- Add password reset token support

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
