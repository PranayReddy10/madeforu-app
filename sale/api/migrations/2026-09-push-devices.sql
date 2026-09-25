-- Devices that receive real-time push (lib_push.php creates this itself
-- on first use; this file is here for anyone who prefers to run it).
CREATE TABLE IF NOT EXISTS push_devices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  api_token_id INT NULL,
  platform VARCHAR(10) NOT NULL,
  token VARCHAR(255) NOT NULL,
  device VARCHAR(120) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_push_token (token),
  KEY ix_push_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
