-- SIGARB — leitura do cadastro de funcionários (banco `funcionarios` no bde02). MODELO: o DBA executa; NÃO executar daqui.
-- Usado por `php artisan sigarb:efetivo:sincronizar` (agendado a cada minuto). Somente SELECT, só nas 4 tabelas lidas.
-- Seções (escalas.setores) NÃO são lidas: nenhum grant no schema `escalas`.
CREATE USER IF NOT EXISTS 'sigarb_func'@'192.168.200.8' IDENTIFIED BY '<SENHA_FUNC>' WITH MAX_USER_CONNECTIONS 2;
GRANT SELECT ON `funcionarios`.`funcionarios` TO 'sigarb_func'@'192.168.200.8';
GRANT SELECT ON `funcionarios`.`perimetros`   TO 'sigarb_func'@'192.168.200.8';
GRANT SELECT ON `funcionarios`.`bases`        TO 'sigarb_func'@'192.168.200.8';
GRANT SELECT ON `funcionarios`.`unidades`     TO 'sigarb_func'@'192.168.200.8';
-- Firewall do bde02: liberar 3306 de 192.168.200.8 se ainda não estiver.
-- Conferência (da VM .8): mysql -h <bde02> -u sigarb_func -p -e "SELECT COUNT(*) FROM funcionarios.funcionarios"
