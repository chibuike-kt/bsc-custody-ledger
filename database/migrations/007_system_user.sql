-- A fixed system user to own treasury accounts
INSERT IGNORE INTO users (id, email, password_hash)
VALUES (
  '00000000-0000-0000-0000-000000000000',
  'system@local',
  '$argon2id$v=19$m=65536,t=3,p=1$ZHVtbXlzYWx0$ZHVtbXloYXNo' -- not used
);
