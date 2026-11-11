-- SIGARB — identidades separadas (ADR-013). Modelo; @BANCO@, @SENHA_APP@ e @SENHA_MIGR@ são substituídos pelo script.
-- Runtime: apenas DML. Sem CREATE/ALTER/DROP/INDEX/TRIGGER/REFERENCES/GRANT. DELETE só onde o fluxo normal exige.
-- Migração: DDL completo, usado SOMENTE em operação de migração explicitamente autorizada.
CREATE USER IF NOT EXISTS 'sigarb_app'@'%' IDENTIFIED BY '@SENHA_APP@';
CREATE USER IF NOT EXISTS 'sigarb_migr'@'%' IDENTIFIED BY '@SENHA_MIGR@';
ALTER USER 'sigarb_app'@'%' IDENTIFIED BY '@SENHA_APP@';
ALTER USER 'sigarb_migr'@'%' IDENTIFIED BY '@SENHA_MIGR@';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'sigarb_app'@'%';
GRANT ALL PRIVILEGES ON `@BANCO@`.* TO 'sigarb_migr'@'%';
GRANT SELECT, INSERT, UPDATE ON `@BANCO@`.* TO 'sigarb_app'@'%';
-- DELETE apenas em tabelas operacionais (sessões, cache, filas e papéis revogados).
GRANT DELETE ON `@BANCO@`.`sessions` TO 'sigarb_app'@'%';
GRANT DELETE ON `@BANCO@`.`cache` TO 'sigarb_app'@'%';
GRANT DELETE ON `@BANCO@`.`cache_locks` TO 'sigarb_app'@'%';
GRANT DELETE ON `@BANCO@`.`jobs` TO 'sigarb_app'@'%';
GRANT DELETE ON `@BANCO@`.`job_batches` TO 'sigarb_app'@'%';
GRANT DELETE ON `@BANCO@`.`failed_jobs` TO 'sigarb_app'@'%';
-- Retirar permissão de um papel pela tela de permissões.
GRANT DELETE ON `@BANCO@`.`papel_permissao` TO 'sigarb_app'@'%';
GRANT DELETE ON `@BANCO@`.`atribuicoes_papel` TO 'sigarb_app'@'%';
FLUSH PRIVILEGES;
