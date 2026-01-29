CREATE TABLE IF NOT EXISTS chain_deposits (
  id CHAR(36) PRIMARY KEY,
  chain VARCHAR(20) NOT NULL,
  token_contract CHAR(42) NOT NULL,
  tx_hash CHAR(66) NOT NULL,
  log_index INT NOT NULL,
  from_address CHAR(42) NOT NULL,
  to_address CHAR(42) NOT NULL,
  amount_raw VARCHAR(80) NOT NULL,
  block_number BIGINT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'detected',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_chain_tx_log (chain, tx_hash, log_index),
  KEY idx_to_address (to_address),
  KEY idx_status_block (status, block_number)
) ENGINE=InnoDB;
