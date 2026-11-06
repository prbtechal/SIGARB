# Protótipos de segurança (NÃO usados pela aplicação)

Experimentos isolados para subsidiar decisões institucionais. Nenhum está ligado ao código de produção,
nenhum foi instalado em servidor institucional e todos usam apenas dados sintéticos.

| ID | Tema | Resultado | Decisão necessária |
|---|---|---|---|
| PE-06 | Sanitização/inspeção de PDF | `pe06/RESULTADO.md` | Aprovar qpdf em container isolado no servidor de aplicação; definir antivírus |
| PE-07 | Âncora independente da auditoria | `pe07/RESULTADO.md` | Onde guardar a chave de assinatura e as âncoras; periodicidade; quem verifica |
