CREATE TABLE IF NOT EXISTS users (
  id CHAR(36) PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wallet_addresses (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  chain VARCHAR(20) NOT NULL,
  address CHAR(42) NOT NULL,
  derivation_index BIGINT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_chain_address (chain, address),
  UNIQUE KEY uniq_user_chain (user_id, chain),
  CONSTRAINT fk_wallet_addresses_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
