-- ==================================================================
-- Migração 019 — Inteligência de Vendas, Fase 6 (LGPD)
-- ==================================================================
-- Não destrutiva e idempotente.
--   vendas_externas.anonimizada_em/por: venda cujos dados pessoais foram
--   apagados a pedido do titular. Uma nova importação do mesmo "id" NÃO
--   devolve nome, telefone, e-mail nem CPF.
-- ==================================================================

SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'vendas_externas' AND column_name = 'anonimizada_em');
SET @s := IF(@c = 0, 'ALTER TABLE vendas_externas ADD COLUMN anonimizada_em DATETIME NULL AFTER atualizado_em, ADD COLUMN anonimizada_por INT UNSIGNED NULL AFTER anonimizada_em', 'SELECT 1');
PREPARE p FROM @s;
EXECUTE p;
DEALLOCATE PREPARE p;
