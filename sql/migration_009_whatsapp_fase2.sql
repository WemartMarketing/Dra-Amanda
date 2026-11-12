-- WeMart Sales — Migração 009: WhatsApp — Fase 2 (integração com a Cloud API).
-- Rode depois da 008. Idempotente, MySQL 5.7+/8 e MariaDB 10.3+.
--
--   whatsapp_contas.webhook_assinado_em  quando o app foi inscrito na WABA
--                                          (sem isso a Meta não manda os webhooks)
--   whatsapp_contas.ultimo_webhook_em    último aviso recebido (diagnóstico)

SET NAMES utf8mb4;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_contas' AND COLUMN_NAME = 'webhook_assinado_em');
SET @sql := IF(@x = 0, 'ALTER TABLE whatsapp_contas ADD COLUMN webhook_assinado_em DATETIME NULL AFTER token_validado_em', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_contas' AND COLUMN_NAME = 'ultimo_webhook_em');
SET @sql := IF(@x = 0, 'ALTER TABLE whatsapp_contas ADD COLUMN ultimo_webhook_em DATETIME NULL AFTER webhook_assinado_em', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
