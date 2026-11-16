-- Banco compartilhado do ecossistema PRB (Arsenal, Ronda Social, Visita Segura, Escala Certa).
-- Decisão do responsável (2026-11-16): versão de cada sistema e equipe ("Sobre o sistema") num banco único,
-- no mesmo modelo do banco `funcionarios` (banco próprio, só leitura para as aplicações).
--
-- - versoes: cada sistema registra a própria versão (arquivo backend/VERSION da release) na primeira consulta
--   ao /sobre depois do deploy (INSERT IGNORE). Histórico nunca é apagado.
-- - participacoes: quem participou de qual sistema e em qual período. `fim` preenchido = "Já fizeram parte":
--   a pessoa nunca é apagada, então quem foi substituído continua creditado.
--   sistema_id NULL = participa de todos os sistemas.
-- Idempotente: pode ser aplicado de novo sem duplicar.

CREATE DATABASE IF NOT EXISTS ecossistema CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE ecossistema;

CREATE TABLE IF NOT EXISTS sistemas (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  codigo VARCHAR(30) NOT NULL UNIQUE,
  nome VARCHAR(80) NOT NULL
);

CREATE TABLE IF NOT EXISTS versoes (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sistema_id SMALLINT UNSIGNED NOT NULL,
  versao VARCHAR(20) NOT NULL,
  git_sha CHAR(40) NOT NULL DEFAULT '',
  implantado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY versoes_unica (sistema_id, versao, git_sha),
  KEY versoes_recentes (sistema_id, implantado_em),
  CONSTRAINT versoes_sistema FOREIGN KEY (sistema_id) REFERENCES sistemas (id)
);

CREATE TABLE IF NOT EXISTS pessoas (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  nome VARCHAR(120) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS participacoes (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  pessoa_id INT UNSIGNED NOT NULL,
  sistema_id SMALLINT UNSIGNED NULL,
  grupo ENUM('GOVERNANCA', 'DESENVOLVIMENTO') NOT NULL,
  cargo VARCHAR(120) NOT NULL,
  atribuicao VARCHAR(255) NULL,
  inicio DATE NULL,
  fim DATE NULL,
  ordem SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY participacao_unica (pessoa_id, sistema_id, grupo, cargo),
  CONSTRAINT participacoes_pessoa FOREIGN KEY (pessoa_id) REFERENCES pessoas (id),
  CONSTRAINT participacoes_sistema FOREIGN KEY (sistema_id) REFERENCES sistemas (id)
);

INSERT IGNORE INTO sistemas (codigo, nome) VALUES
  ('ARSENAL', 'Arsenal (SIGARB)'), ('RONDA_SOCIAL', 'Ronda Social'), ('VISITA_SEGURA', 'Visita Segura'), ('ESCALA_CERTA', 'Escala Certa');

INSERT IGNORE INTO pessoas (nome) VALUES
  ('Josine Lima dos Santos'), ('Cristiano Gleiky Lima de Santana'), ('José Paulo Neto Filho'),
  ('Victor Seixas dos Santos Zuza'), ('Albert Douglas'), ('Marivan Olivares');

-- Carga inicial = conteúdo das páginas Sobre em 2026-11-16 (todos os sistemas).
INSERT IGNORE INTO participacoes (pessoa_id, sistema_id, grupo, cargo, atribuicao, ordem)
SELECT p.id, NULL, x.grupo, x.cargo, x.atribuicao, x.ordem FROM (
  SELECT 'Josine Lima dos Santos' nome, 'GOVERNANCA' grupo, 'Superintendente · TC PMAL' cargo, 'Direcionamento estratégico e governança institucional.' atribuicao, 1 ordem
  UNION ALL SELECT 'Cristiano Gleiky Lima de Santana', 'GOVERNANCA', 'Gestor de TI · SGT PMAL', 'Coordenação técnica, infraestrutura e segurança da informação.', 2
  UNION ALL SELECT 'José Paulo Neto Filho', 'DESENVOLVIMENTO', 'Desenvolvedor', NULL, 1
  UNION ALL SELECT 'Victor Seixas dos Santos Zuza', 'DESENVOLVIMENTO', 'Desenvolvedor', NULL, 2
  UNION ALL SELECT 'Albert Douglas', 'DESENVOLVIMENTO', 'Suporte Técnico', NULL, 3
) x JOIN pessoas p ON p.nome = x.nome
-- UNIQUE não barra duplicata com sistema_id NULL (MySQL aceita vários NULL): checagem explícita.
WHERE NOT EXISTS (SELECT 1 FROM participacoes pa WHERE pa.pessoa_id = p.id AND pa.sistema_id IS NULL AND pa.grupo = x.grupo AND pa.cargo = x.cargo);

-- Escala Certa: crédito do rodapé antigo (Marivan Olivares, desenvolvedor). Período não informado; fim marcado
-- em 2026-11-16 só para aparecer em "Já fizeram parte" — corrigir as datas quando forem conhecidas.
INSERT IGNORE INTO participacoes (pessoa_id, sistema_id, grupo, cargo, fim, ordem)
SELECT p.id, s.id, 'DESENVOLVIMENTO', 'Desenvolvedor', '2026-11-16', 10
FROM pessoas p JOIN sistemas s ON s.codigo = 'ESCALA_CERTA' WHERE p.nome = 'Marivan Olivares';

-- Runtime das aplicações: lê tudo e só insere versões.
GRANT SELECT ON ecossistema.* TO 'sigarb_app'@'192.168.200.8', 'rs_app'@'192.168.200.8', 'vs_app'@'192.168.200.8', 'escalas_app'@'192.168.200.8';
GRANT INSERT ON ecossistema.versoes TO 'sigarb_app'@'192.168.200.8', 'rs_app'@'192.168.200.8', 'vs_app'@'192.168.200.8', 'escalas_app'@'192.168.200.8';
