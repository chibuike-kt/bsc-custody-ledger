CREATE TABLE IF NOT EXISTS account_holds (
  id CHAR(36) PRIMARY KEY,
  account_id CHAR(36) NOT NULL,
  reference VARCHAR(190) NOT NULL,
  amount_minor VARCHAR(80) NOT NULL,
  reason VARCHAR(80) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_at TIMESTAMP NULL,
  UNIQUE KEY uniq_hold_account_ref (account_id, reference),
  KEY idx_hold_status (status),
  CONSTRAINT fk_holds_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;
