-- WhatsApp messages (whatsapp.php). lib_whatsapp.php creates these itself
-- on first use; this file is for anyone who prefers to run it by hand.
CREATE TABLE IF NOT EXISTS wa_campaigns (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  mode VARCHAR(10) NOT NULL,
  message TEXT NOT NULL,
  template_name VARCHAR(120) NOT NULL DEFAULT '',
  template_lang VARCHAR(20) NOT NULL DEFAULT '',
  template_params VARCHAR(500) NOT NULL DEFAULT '',
  columns_json TEXT NOT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wa_recipients (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT '',
  vars_json TEXT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  error VARCHAR(255) NOT NULL DEFAULT '',
  sent_at DATETIME NULL,
  KEY ix_wa_campaign (campaign_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
