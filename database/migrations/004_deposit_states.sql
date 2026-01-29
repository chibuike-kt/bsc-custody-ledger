ALTER TABLE chain_deposits
  ADD COLUMN confirmations INT NOT NULL DEFAULT 0,
  ADD COLUMN confirmed_at TIMESTAMP NULL,
  ADD COLUMN credited_at TIMESTAMP NULL,
  ADD COLUMN ledger_journal_id CHAR(36) NULL,
  ADD COLUMN external_ref VARCHAR(190) NULL;

CREATE INDEX idx_deposits_status ON chain_deposits(status);
CREATE UNIQUE INDEX uniq_deposits_external_ref ON chain_deposits(external_ref);
