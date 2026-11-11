-- WeMart Sales — Migração 006: integrações e oferta ativa (Etapa 5 do prompt mestre).
--
-- 1. Meta Lead Ads (seção 10): páginas conectadas por empresa, mapeamento
--    formulário → empreendimento, e uma caixa de entrada de eventos do
--    webhook (meta_eventos) com deduplicação por leadgen_id, registro de
--    falhas e reprocessamento. App secret e verify token da plataforma
--    ficam em includes/config.php (nunca no banco nem no código público);
--    o token de cada página fica no banco CIFRADO com a chave APP_KEY do
--    config.php.
-- 2. Oferta ativa e importação de planilhas (seção 11): listas importadas,
--    vinculadas a empreendimentos, cujos contatos viram leads de origem
--    'importacao' com referência à lista.
-- 3. Recuperação automática (seção 11): regras por empresa e a data do
--    último feedback VÁLIDO do lead (leads.ultimo_feedback_em) — só ações
--    que comprovam atendimento atualizam essa data, anotações não.
-- 4. Redistribuição: um lead pode passar pela roleta mais de uma vez
--    (recuperação), então roleta_distribuicoes deixa de ter UNIQUE em
--    lead_id; a fila ganha a origem 'recuperacao' e o corretor a evitar.
--
-- Compatível com MySQL 5.7+/8 e MariaDB 10.3+, idempotente, sem procedures.

SET NAMES utf8mb4;

-- ------------------------------------------------------------------
-- leads: lista de oferta ativa, último feedback válido, id do Meta
-- ------------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'oferta_lista_id');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD COLUMN oferta_lista_id INT UNSIGNED NULL AFTER origem', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'ultimo_feedback_em');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD COLUMN ultimo_feedback_em DATETIME NULL AFTER ultima_atividade_em', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'meta_leadgen_id');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD COLUMN meta_leadgen_id VARCHAR(40) NULL AFTER oferta_lista_id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'uq_leads_meta_leadgen');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD UNIQUE KEY uq_leads_meta_leadgen (meta_leadgen_id)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_feedback');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD INDEX idx_leads_feedback (empresa_id, etapa, ultimo_feedback_em)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE leads
   SET ultimo_feedback_em = COALESCE(ultimo_contato_em, ultima_atividade_em, criado_em)
 WHERE ultimo_feedback_em IS NULL;

-- ------------------------------------------------------------------
-- Redistribuição: lead pode passar pela roleta mais de uma vez
-- ------------------------------------------------------------------
-- A FK em lead_id precisa de um índice; cria o comum antes de remover o UNIQUE.
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_distribuicoes' AND INDEX_NAME = 'idx_dist_lead');
SET @sql := IF(@x = 0, 'ALTER TABLE roleta_distribuicoes ADD INDEX idx_dist_lead (lead_id)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_distribuicoes' AND INDEX_NAME = 'uq_dist_lead');
SET @sql := IF(@x > 0, 'ALTER TABLE roleta_distribuicoes DROP INDEX uq_dist_lead', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Só amplia a lista se ainda não tiver os valores novos: rodar de novo
-- uma migração antiga nunca pode encolher um ENUM já ampliado depois.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_distribuicoes' AND COLUMN_NAME = 'origem' AND COLUMN_TYPE LIKE '%''recuperacao''%');
SET @sql := IF(@x = 0, 'ALTER TABLE roleta_distribuicoes MODIFY origem ENUM(''manual'',''meta_ads'',''importacao'',''fila'',''reprocessamento'',''recuperacao'') NOT NULL DEFAULT ''manual''', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Só amplia a lista se ainda não tiver os valores novos: rodar de novo
-- uma migração antiga nunca pode encolher um ENUM já ampliado depois.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_fila' AND COLUMN_NAME = 'origem' AND COLUMN_TYPE LIKE '%''recuperacao''%');
SET @sql := IF(@x = 0, 'ALTER TABLE roleta_fila MODIFY origem ENUM(''manual'',''meta_ads'',''importacao'',''reprocessamento'',''recuperacao'') NOT NULL DEFAULT ''manual''', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Corretor que não deve receber de novo (o que perdeu o lead por inatividade)
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_fila' AND COLUMN_NAME = 'evitar_usuario_id');
SET @sql := IF(@x = 0, 'ALTER TABLE roleta_fila ADD COLUMN evitar_usuario_id INT UNSIGNED NULL AFTER origem', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------------
-- Meta Lead Ads
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meta_paginas (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id              INT UNSIGNED NOT NULL,
    page_id                 VARCHAR(40) NOT NULL,
    nome                    VARCHAR(190) NULL,
    access_token_cifrado    TEXT NULL,          -- token da página, cifrado com APP_KEY
    token_validado_em       DATETIME NULL,
    ativa                   TINYINT(1) NOT NULL DEFAULT 1,
    criado_em               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meta_paginas_page (page_id),  -- uma página pertence a uma única empresa
    CONSTRAINT fk_meta_paginas_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meta_formularios (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id          INT UNSIGNED NOT NULL,
    meta_pagina_id      INT UNSIGNED NOT NULL,
    form_id             VARCHAR(40) NOT NULL,
    nome                VARCHAR(190) NULL,
    empreendimento_id   INT UNSIGNED NULL,      -- NULL = o lead entra sem empreendimento (vai para a fila geral)
    ativo               TINYINT(1) NOT NULL DEFAULT 1,
    criado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meta_form (form_id),
    CONSTRAINT fk_meta_form_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_meta_form_pagina FOREIGN KEY (meta_pagina_id) REFERENCES meta_paginas(id) ON DELETE CASCADE,
    CONSTRAINT fk_meta_form_emp FOREIGN KEY (empreendimento_id) REFERENCES empreendimentos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Caixa de entrada do webhook: cada aviso da Meta é gravado antes de
-- qualquer processamento, para nunca perder um cadastro.
CREATE TABLE IF NOT EXISTS meta_eventos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NULL,          -- NULL = página não conectada a nenhuma empresa
    page_id         VARCHAR(40) NULL,
    form_id         VARCHAR(40) NULL,
    leadgen_id      VARCHAR(40) NOT NULL,
    payload         TEXT NOT NULL,              -- corpo recebido (sem dados pessoais: a Meta só envia ids)
    status          ENUM('pendente','processado','duplicado','erro','ignorado') NOT NULL DEFAULT 'pendente',
    tentativas      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    proxima_tentativa_em DATETIME NULL,
    erro            VARCHAR(500) NULL,
    lead_id         INT UNSIGNED NULL,
    recebido_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processado_em   DATETIME NULL,
    UNIQUE KEY uq_meta_eventos_leadgen (leadgen_id),
    INDEX idx_meta_eventos_status (status, proxima_tentativa_em),
    INDEX idx_meta_eventos_empresa (empresa_id, recebido_em),
    CONSTRAINT fk_meta_eventos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
    CONSTRAINT fk_meta_eventos_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Oferta ativa / importação
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS oferta_listas (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id          INT UNSIGNED NOT NULL,
    nome                VARCHAR(190) NOT NULL,
    empreendimento_id   INT UNSIGNED NULL,
    arquivo_nome        VARCHAR(255) NULL,      -- nome original da planilha (o arquivo não é guardado)
    linhas_lidas        INT UNSIGNED NOT NULL DEFAULT 0,
    importados          INT UNSIGNED NOT NULL DEFAULT 0,
    duplicados          INT UNSIGNED NOT NULL DEFAULT 0,
    invalidos           INT UNSIGNED NOT NULL DEFAULT 0,
    status              ENUM('importada','em_distribuicao','encerrada') NOT NULL DEFAULT 'importada',
    criado_por          INT UNSIGNED NULL,
    criado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_oferta_empresa (empresa_id, criado_em),
    CONSTRAINT fk_oferta_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_oferta_emp FOREIGN KEY (empreendimento_id) REFERENCES empreendimentos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @x := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND CONSTRAINT_NAME = 'fk_leads_oferta_lista');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD CONSTRAINT fk_leads_oferta_lista FOREIGN KEY (oferta_lista_id) REFERENCES oferta_listas(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------------
-- Recuperação automática por inatividade
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recuperacao_regras (
    empresa_id              INT UNSIGNED PRIMARY KEY,
    ativa                   TINYINT(1) NOT NULL DEFAULT 1,
    dias_sem_feedback       SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    avisar_dias_antes       SMALLINT UNSIGNED NOT NULL DEFAULT 1,  -- alerta ao corretor antes da perda
    -- Categorias de etapa sujeitas à recuperação (visita agendada, visita
    -- realizada/negociação, contrato e encerrados ficam de fora por padrão)
    categorias              VARCHAR(190) NOT NULL DEFAULT 'novo_lead,primeiro_contato,em_atendimento',
    redistribuir_automaticamente TINYINT(1) NOT NULL DEFAULT 1,
    atualizado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_recup_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO recuperacao_regras (empresa_id) SELECT id FROM empresas;

-- lead_eventos: novos tipos
-- Só amplia a lista se ainda não tiver os valores novos: rodar de novo
-- uma migração antiga nunca pode encolher um ENUM já ampliado depois.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_eventos' AND COLUMN_NAME = 'tipo' AND COLUMN_TYPE LIKE '%''integracao''%');
SET @sql := IF(@x = 0, 'ALTER TABLE lead_eventos MODIFY tipo ENUM(''atribuicao'',''mudanca_etapa'',''contato'',''redistribuicao'',''nota'',''sistema'',''ligacao'',''whatsapp'',''visita'',''agenda'',''arquivo'',''transferencia'',''recuperacao'',''importacao'',''integracao'') NOT NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------------
-- Permissões
-- ------------------------------------------------------------------
INSERT INTO permissoes (chave, rotulo, categoria) VALUES
    ('gerenciar_integracoes',  'Gerenciar integrações (Meta Ads)',        'administracao'),
    ('gerenciar_oferta_ativa', 'Importar listas e gerenciar oferta ativa', 'operacao'),
    ('configurar_recuperacao', 'Configurar recuperação de leads',          'administracao')
ON DUPLICATE KEY UPDATE rotulo = VALUES(rotulo), categoria = VALUES(categoria);

INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
SELECT f.id, p.chave FROM funcoes f
JOIN permissoes p ON p.chave IN ('gerenciar_integracoes', 'gerenciar_oferta_ativa', 'configurar_recuperacao')
WHERE f.nome = 'Master da empresa' AND f.padrao_sistema = 1;

INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
SELECT f.id, 'gerenciar_oferta_ativa' FROM funcoes f
WHERE f.nome IN ('Gerente Geral', 'Superintendente') AND f.padrao_sistema = 1;
