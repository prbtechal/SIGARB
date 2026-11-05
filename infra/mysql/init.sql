-- Somente desenvolvimento/teste local. Banco de teste separado do banco de demonstração.
CREATE DATABASE IF NOT EXISTS sigarb_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON sigarb_test.* TO 'sigarb'@'%';
FLUSH PRIVILEGES;
CREATE DATABASE IF NOT EXISTS sigarb_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON sigarb_e2e.* TO 'sigarb'@'%';
FLUSH PRIVILEGES;
