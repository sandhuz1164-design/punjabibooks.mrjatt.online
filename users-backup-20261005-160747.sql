-- users table backup before password migration
CREATE TABLE users_bak_20261005_160747 LIKE users;
INSERT INTO users_bak_20261005_160747 SELECT * FROM users;
