-- WeMart Sales — Migração 007: dashboards e relatórios (Etapa 6 do prompt mestre).
--
-- Seção 12 pede, entre outros: taxa de primeiro contato, tempo médio de
-- primeiro contato, leads sem atendimento e resultados por origem de
-- campanha. Para isso o lead passa a guardar:
--   atribuido_em         quando foi entregue ao corretor atual (roleta,
--                        transferência, importação ou cadastro);
--   primeiro_contato_em  o PRIMEIRO feedback válido do corretor (mesma
--                        regra da recuperação: mudar etapa, registrar
--                        atendimento, visita/retorno realizado ou cliente
--                        ausente). Receber o lead NÃO conta como contato;
--   campanha             nome da campanha de origem (Meta Ads ou informado);
--   contrato_assinado_em quando o lead entrou na etapa "Contrato assinado"
--                        (some se ele sair dela), para contar contratos
--                        pela data da assinatura e não pela data de entrada.
-- Também cria índices para os relatórios rodarem rápido em hospedagem
-- compartilhada, e a permissão de ver a auditoria.
--
-- Compatível com MySQL 5.7+/8 e MariaDB 10.3+, idempotente, sem procedures.

SET NAMES utf8mb4;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'atribuido_em');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD COLUMN atribuido_em DATETIME NULL AFTER responsavel_id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'primeiro_contato_em');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD COLUMN primeiro_contato_em DATETIME NULL AFTER atribuido_em', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'campanha');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD COLUMN campanha VARCHAR(120) NULL AFTER origem', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'contrato_assinado_em');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD COLUMN contrato_assinado_em DATETIME NULL AFTER primeiro_contato_em', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Preenchimento a partir do histórico já existente --------------------------
-- atribuido_em: última entrega pela roleta; sem ela, a criação do lead.
UPDATE leads l
   SET l.atribuido_em = COALESCE(
         (SELECT MAX(d.distribuido_em) FROM roleta_distribuicoes d WHERE d.lead_id = l.id AND d.usuario_id = l.responsavel_id),
         l.criado_em)
 WHERE l.atribuido_em IS NULL AND l.responsavel_id IS NOT NULL;

-- primeiro_contato_em: primeiro evento de atendimento feito por uma pessoa.
UPDATE leads l
   SET l.primeiro_contato_em = (
         SELECT MIN(e.criado_em) FROM lead_eventos e
          WHERE e.lead_id = l.id AND e.usuario_id IS NOT NULL
            AND e.tipo IN ('contato', 'mudanca_etapa', 'visita'))
 WHERE l.primeiro_contato_em IS NULL;

-- contrato_assinado_em: última mudança de etapa registrada; sem ela, a última alteração do lead.
UPDATE leads l
   SET l.contrato_assinado_em = COALESCE(
         (SELECT MAX(e.criado_em) FROM lead_eventos e WHERE e.lead_id = l.id AND e.tipo = 'mudanca_etapa'),
         l.atualizado_em)
 WHERE l.contrato_assinado_em IS NULL AND l.etapa = 'contrato_assinado';

-- Índices para relatórios ------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_empresa_criado');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD INDEX idx_leads_empresa_criado (empresa_id, criado_em)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_empresa_resp');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD INDEX idx_leads_empresa_resp (empresa_id, responsavel_id, etapa)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_empresa_emp');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD INDEX idx_leads_empresa_emp (empresa_id, empreendimento_id, criado_em)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_distribuicoes' AND INDEX_NAME = 'idx_dist_empresa_data');
SET @sql := IF(@x = 0, 'ALTER TABLE roleta_distribuicoes ADD INDEX idx_dist_empresa_data (empresa_id, distribuido_em)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_transferencias' AND INDEX_NAME = 'idx_transf_empresa_data');
SET @sql := IF(@x = 0, 'ALTER TABLE lead_transferencias ADD INDEX idx_transf_empresa_data (empresa_id, motivo, criado_em)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Permissão: ver a auditoria (seção 16 — menu de administradores) -------------
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agenda_compromissos' AND INDEX_NAME = 'idx_agenda_empresa_tipo_criado');
SET @sql := IF(@x = 0, 'ALTER TABLE agenda_compromissos ADD INDEX idx_agenda_empresa_tipo_criado (empresa_id, tipo, criado_em)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria' AND INDEX_NAME = 'idx_auditoria_empresa_data');
SET @sql := IF(@x = 0, 'ALTER TABLE auditoria ADD INDEX idx_auditoria_empresa_data (empresa_id, criado_em)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO permissoes (chave, rotulo, categoria) VALUES
    ('visualizar_auditoria', 'Ver registro de auditoria', 'administracao')
ON DUPLICATE KEY UPDATE rotulo = VALUES(rotulo), categoria = VALUES(categoria);

INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
SELECT f.id, 'visualizar_auditoria' FROM funcoes f
WHERE f.nome = 'Master da empresa' AND f.padrao_sistema = 1;
