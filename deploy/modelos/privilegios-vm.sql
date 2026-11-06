-- SIGARB — banco e identidades na VM .9 (MODELO; o DBA executa na janela aprovada).
-- Schema exclusivo; NENHUM grant em schemas de outros sistemas. Senhas geradas pelo DBA, nunca neste arquivo.
CREATE DATABASE IF NOT EXISTS sigarb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'sigarb_app'@'192.168.200.8' IDENTIFIED BY '<SENHA_APP>' WITH MAX_USER_CONNECTIONS 15;
CREATE USER IF NOT EXISTS 'sigarb_migr'@'192.168.200.8' IDENTIFIED BY '<SENHA_MIGR>' ACCOUNT LOCK;  -- desbloquear só na janela
GRANT ALL PRIVILEGES ON `sigarb`.* TO 'sigarb_migr'@'192.168.200.8';
GRANT SELECT, INSERT, UPDATE ON `sigarb`.* TO 'sigarb_app'@'192.168.200.8';
GRANT DELETE ON `sigarb`.`sessions` TO 'sigarb_app'@'192.168.200.8';
GRANT DELETE ON `sigarb`.`cache` TO 'sigarb_app'@'192.168.200.8';
GRANT DELETE ON `sigarb`.`cache_locks` TO 'sigarb_app'@'192.168.200.8';
GRANT DELETE ON `sigarb`.`jobs` TO 'sigarb_app'@'192.168.200.8';
GRANT DELETE ON `sigarb`.`job_batches` TO 'sigarb_app'@'192.168.200.8';
GRANT DELETE ON `sigarb`.`failed_jobs` TO 'sigarb_app'@'192.168.200.8';
GRANT DELETE ON `sigarb`.`atribuicoes_papel` TO 'sigarb_app'@'192.168.200.8';
-- Pré-requisitos de servidor (conferir, não alterar sem janela): innodb_flush_log_at_trx_commit=1, sync_binlog=1,
-- log_bin_trust_function_creators=1 (triggers de imutabilidade com binlog), bind 192.168.200.9, 3306 só de .8 + admin.
-- Janela de migração: ALTER USER 'sigarb_migr'@'192.168.200.8' ACCOUNT UNLOCK;  ... depois: ACCOUNT LOCK;
