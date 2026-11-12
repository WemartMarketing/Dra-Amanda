-- WeMart Sales — Migração 010: Jornadas — Fase 4 (boas-vindas e book).
-- Rode depois da 009. Idempotente, MySQL 5.7+/8 e MariaDB 10.3+.
--
-- Autorização de contato pelo WhatsApp vinda do formulário do Meta Lead Ads:
-- preencher o formulário NÃO é autorização. Só conta quando o formulário tem
-- uma pergunta específica (ex.: "Aceito receber contato pelo WhatsApp") e o
-- cliente respondeu que sim. Aqui fica qual é essa pergunta e o texto do aviso
-- exibido no formulário (guardado como evidência junto da autorização).

SET NAMES utf8mb4;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meta_formularios' AND COLUMN_NAME = 'wa_consentimento_campo');
SET @sql := IF(@x = 0, 'ALTER TABLE meta_formularios ADD COLUMN wa_consentimento_campo VARCHAR(100) NULL, ADD COLUMN wa_consentimento_texto VARCHAR(500) NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
