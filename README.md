BSC Custody Ledger (Learning Build)

This repo is a learning-focused implementation of custodial crypto rails on BSC mainnet:
- user auth
- deposit address allocation
- on-chain deposit detection (USDT BEP-20)
- confirmations + ledger crediting (in progress)
- withdrawals (planned)

Security note:
- Do not store mnemonics/private keys in the database.
- Keep signing material isolated (dev mode uses env key only).
