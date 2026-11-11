-- WeMart Sales — Migração 004: CRM (Etapa 3 do prompt mestre).
--
-- 1. Etapas do funil configuráveis por empresa (seção 7) SEM quebrar os
--    relatórios: cada etapa pertence a uma `categoria` fixa (o vocabulário
--    que relatórios, Início e regras automáticas entendem). O Master renomeia,
--    reordena, colore, oculta ou cria etapas extras, mas toda etapa aponta
--    para uma categoria. leads.etapa_id guarda a etapa escolhida;
--    leads.etapa continua existindo e é SEMPRE gravado com a categoria da
--    etapa (denormalizado de propósito: as consultas de relatório seguem
--    funcionando sem mudança).
-- 2. Agenda comercial (seção 14).
-- 3. Registro de transferências de leads (seção 5 — regra de transferência e
--    indicadores agregados de perda da estrutura anterior).
-- 4. Arquivos do lead (seção 8 — "quando autorizados").
-- 5. Prioridade e exclusão lógica (LGPD) de leads.
--
-- Idempotente: pode rodar mais de uma vez.

SET NAMES utf8mb4;

-- ------------------------------------------------------------------
-- leads: nova categoria 'sem_interesse', prioridade, exclusão lógica,
-- etapa configurável, data da última atividade
-- ------------------------------------------------------------------
-- Só amplia a lista se ainda não tiver os valores novos: rodar de novo
-- uma migração antiga nunca pode encolher um ENUM já ampliado depois.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'etapa' AND COLUMN_TYPE LIKE '%''sem_interesse''%');
SET @sql := IF(@x = 0, 'ALTER TABLE leads MODIFY etapa ENUM(''novo_lead'',''primeiro_contato'',''em_atendimento'',''visita_agendada'',''visita_realizada'',''sem_interesse'',''contrato_assinado'',''perdido'') NOT NULL DEFAULT ''novo_lead''', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'etapa_id');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD COLUMN etapa_id INT UNSIGNED NULL AFTER etapa', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'prioridade');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD COLUMN prioridade ENUM(''baixa'',''normal'',''alta'') NOT NULL DEFAULT ''normal'' AFTER etapa_id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'ultima_atividade_em');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD COLUMN ultima_atividade_em DATETIME NULL AFTER ultimo_contato_em', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'excluido_em');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD COLUMN excluido_em DATETIME NULL AFTER atualizado_em', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'excluido_por');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD COLUMN excluido_por INT UNSIGNED NULL AFTER excluido_em', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ------------------------------------------------------------------
-- Etapas do funil por empresa
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS funil_etapas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    nome            VARCHAR(80) NOT NULL,
    categoria       ENUM('novo_lead','primeiro_contato','em_atendimento','visita_agendada','visita_realizada','sem_interesse','contrato_assinado','perdido') NOT NULL,
    ordem           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    cor             VARCHAR(7) NOT NULL DEFAULT '#635BFA',
    exibir_kanban   TINYINT(1) NOT NULL DEFAULT 1,
    ativa           TINYINT(1) NOT NULL DEFAULT 1,
    padrao_sistema  TINYINT(1) NOT NULL DEFAULT 0,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_funil_empresa_nome (empresa_id, nome),
    INDEX idx_funil_empresa_ordem (empresa_id, ordem),
    CONSTRAINT fk_funil_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Etapas padrão (seção 7) para cada empresa existente. Empresas novas
-- recebem as mesmas via código (includes/funil.php).
INSERT IGNORE INTO funil_etapas (empresa_id, nome, categoria, ordem, cor, exibir_kanban, padrao_sistema)
SELECT e.id, d.nome, d.categoria, d.ordem, d.cor, d.exibir, 1
FROM empresas e
CROSS JOIN (
    SELECT 'Tentando contato'        AS nome, 'novo_lead'         AS categoria, 10 AS ordem, '#64748B' AS cor, 1 AS exibir
    UNION ALL SELECT 'Primeiro contato',       'primeiro_contato',  20, '#3B82F6', 1
    UNION ALL SELECT 'Em atendimento',         'em_atendimento',    30, '#635BFA', 1
    UNION ALL SELECT 'Visita agendada',        'visita_agendada',   40, '#F59E0B', 1
    UNION ALL SELECT 'Visita realizada',       'visita_realizada',  50, '#10B981', 1
    UNION ALL SELECT 'Sem interesse',          'sem_interesse',     60, '#9CA3AF', 1
    UNION ALL SELECT 'Contrato assinado',      'contrato_assinado', 70, '#189D72', 1
    UNION ALL SELECT 'Perdido por inatividade','perdido',           80, '#DC6070', 0
) d;

-- Liga cada lead existente à etapa padrão da sua categoria.
UPDATE leads l
JOIN funil_etapas f ON f.empresa_id = l.empresa_id AND f.categoria = l.etapa AND f.padrao_sistema = 1
SET l.etapa_id = f.id
WHERE l.etapa_id IS NULL;

UPDATE leads SET ultima_atividade_em = COALESCE(ultimo_contato_em, atualizado_em, criado_em)
WHERE ultima_atividade_em IS NULL;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_leads_etapa');
SET @sql := IF(@fk = 0,
    'ALTER TABLE leads ADD CONSTRAINT fk_leads_etapa FOREIGN KEY (etapa_id) REFERENCES funil_etapas(id) ON DELETE RESTRICT',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------------
-- Agenda comercial (seção 14)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agenda_compromissos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    lead_id         INT UNSIGNED NULL,
    usuario_id      INT UNSIGNED NOT NULL,          -- responsável pelo compromisso
    tipo            ENUM('visita','retorno','tarefa') NOT NULL,
    titulo          VARCHAR(190) NOT NULL,
    inicio          DATETIME NOT NULL,
    status          ENUM('agendado','realizado','cliente_ausente','reagendado','cancelado') NOT NULL DEFAULT 'agendado',
    observacao      VARCHAR(500) NULL,
    reagendado_de   INT UNSIGNED NULL,              -- compromisso original, quando é um reagendamento
    criado_por      INT UNSIGNED NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluido_em    DATETIME NULL,
    CONSTRAINT fk_agenda_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_agenda_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_agenda_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_agenda_reagendado FOREIGN KEY (reagendado_de) REFERENCES agenda_compromissos(id) ON DELETE SET NULL,
    INDEX idx_agenda_usuario_inicio (usuario_id, status, inicio),
    INDEX idx_agenda_lead (lead_id),
    INDEX idx_agenda_empresa_inicio (empresa_id, inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Transferências de leads (seção 5). Guarda a estrutura anterior e a nova
-- para auditoria e para os indicadores AGREGADOS de perda da estrutura
-- anterior — esses relatórios contam linhas desta tabela, nunca abrem o
-- cadastro do cliente transferido.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lead_transferencias (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id              INT UNSIGNED NOT NULL,
    lead_id                 INT UNSIGNED NOT NULL,
    empreendimento_id       INT UNSIGNED NULL,
    de_usuario_id           INT UNSIGNED NULL,
    de_equipe_id            INT UNSIGNED NULL,
    de_gerente_id           INT UNSIGNED NULL,
    de_superintendencia_id  INT UNSIGNED NULL,
    para_usuario_id         INT UNSIGNED NULL,
    para_equipe_id          INT UNSIGNED NULL,
    para_gerente_id         INT UNSIGNED NULL,
    para_superintendencia_id INT UNSIGNED NULL,
    motivo                  ENUM('administrativa','inatividade','mudanca_equipe','roleta') NOT NULL,
    observacao              VARCHAR(500) NULL,
    executado_por           INT UNSIGNED NULL,      -- NULL = rotina automática
    criado_em               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transf_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_transf_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    INDEX idx_transf_de_equipe (de_equipe_id, motivo, criado_em),
    INDEX idx_transf_de_super (de_superintendencia_id, motivo, criado_em),
    INDEX idx_transf_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Arquivos do lead (guardados fora da pasta pública de imagens e servidos
-- só por um script PHP que confere permissão e escopo do lead)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lead_arquivos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    lead_id         INT UNSIGNED NOT NULL,
    nome_original   VARCHAR(255) NOT NULL,
    arquivo         VARCHAR(80) NOT NULL,           -- nome aleatório em storage/lead_arquivos/
    mime            VARCHAR(100) NOT NULL,
    tamanho         INT UNSIGNED NOT NULL,
    enviado_por     INT UNSIGNED NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    excluido_em     DATETIME NULL,
    CONSTRAINT fk_arquivos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_arquivos_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    INDEX idx_arquivos_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- lead_eventos: novos tipos de atividade
-- Só amplia a lista se ainda não tiver os valores novos: rodar de novo
-- uma migração antiga nunca pode encolher um ENUM já ampliado depois.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_eventos' AND COLUMN_NAME = 'tipo' AND COLUMN_TYPE LIKE '%''transferencia''%');
SET @sql := IF(@x = 0, 'ALTER TABLE lead_eventos MODIFY tipo ENUM(''atribuicao'',''mudanca_etapa'',''contato'',''redistribuicao'',''nota'',''sistema'',''ligacao'',''whatsapp'',''visita'',''agenda'',''arquivo'',''transferencia'') NOT NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------------
-- Novas permissões
-- ------------------------------------------------------------------
INSERT INTO permissoes (chave, rotulo, categoria) VALUES
    ('configurar_funil',       'Configurar etapas do funil',   'administracao'),
    ('acessar_arquivos_leads', 'Enviar e ver arquivos de leads', 'leads')
ON DUPLICATE KEY UPDATE rotulo = VALUES(rotulo), categoria = VALUES(categoria);

INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
SELECT f.id, p.chave FROM funcoes f
JOIN permissoes p ON p.chave IN ('configurar_funil', 'acessar_arquivos_leads')
WHERE f.nome = 'Master da empresa' AND f.padrao_sistema = 1;

INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
SELECT f.id, 'acessar_arquivos_leads' FROM funcoes f
WHERE f.nome IN ('Gerente', 'Corretor', 'Superintendente') AND f.padrao_sistema = 1;
