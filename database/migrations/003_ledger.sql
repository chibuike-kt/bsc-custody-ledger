CREATE TABLE IF NOT EXISTS accounts (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  type VARCHAR(30) NOT NULL,
  currency VARCHAR(20) NOT NULL,
  balance_minor VARCHAR(80) NOT NULL DEFAULT '0',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_type_currency (user_id, type, currency),
  CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS journals (
  id CHAR(36) PRIMARY KEY,
  type VARCHAR(40) NOT NULL,
  reference VARCHAR(190) NOT NULL,
  status VARCHAR(20) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_journals_reference (reference)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS postings (
  id CHAR(36) PRIMARY KEY,
  journal_id CHAR(36) NOT NULL,
  account_id CHAR(36) NOT NULL,
  direction ENUM('debit','credit') NOT NULL,
  amount_minor VARCHAR(80) NOT NULL,
  currency VARCHAR(20) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_postings_account (account_id),
  KEY idx_postings_journal (journal_id),
  CONSTRAINT fk_postings_journal FOREIGN KEY (journal_id) REFERENCES journals(id),
  CONSTRAINT fk_postings_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;
