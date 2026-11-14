-- ==================================================================
-- Migração 013 — Central de Relatórios, Etapa 2: histórico estruturado
-- ==================================================================
-- NÃO destrutiva e idempotente. Só cria tabelas/colunas novas; nada é
-- apagado ou reescrito. O preenchimento do passado (com fontes registradas)
-- é feito depois por: php -q ferramentas/retroalimentar_historico.php
--
--   lead_atribuicoes       cada vez que um lead chega a um corretor, com a
--                          estrutura na data e o 1º atendimento / 1º contato
--                          efetivo DAQUELA atribuição (não se perde na troca).
--   lead_etapa_historico   toda mudança de etapa (usuário, jornada, sistema).
--   lead_contratos         registro formal de contrato (VGV opcional), com
--                          validação e distrato. O Kanban continua sendo só sinal.
--   roleta_fila_historico  todas as passagens pela fila (a fila guarda a última).
--   lead_origens           origens de aquisição configuráveis por empresa.
--   leads                  origem_detalhe_id, UTMs, 1º contato efetivo,
--                          "roleta aberta na chegada?".
--   agenda_compromissos    confirmada_em / confirmada_por.
--   lead_transferencias    motivos "voluntaria" e "oferta_ativa".
--   permissão validar_contratos.
-- ==================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS lead_atribuicoes (
    id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id                  INT UNSIGNED NOT NULL,
    lead_id                     INT UNSIGNED NOT NULL,
    usuario_id                  INT UNSIGNED NOT NULL,
    equipe_id                   INT UNSIGNED NULL,          -- estrutura NA DATA da atribuição
    gerente_id                  INT UNSIGNED NULL,
    superintendencia_id         INT UNSIGNED NULL,
    empreendimento_id           INT UNSIGNED NULL,
    forma                       ENUM('cadastro','roleta','transferencia','oferta_ativa','redistribuicao','mudanca_equipe','desconhecida') NOT NULL,
    roleta_id                   INT UNSIGNED NULL,
    transferencia_id            INT UNSIGNED NULL,
    atribuido_em                DATETIME NOT NULL,
    primeiro_atendimento_em     DATETIME NULL,              -- 1ª atuação válida deste corretor neste lead
    primeiro_contato_efetivo_em DATETIME NULL,              -- 1º contato efetivo nesta atribuição
    contato_efetivo_via         VARCHAR(20) NULL,           -- whatsapp | pendencia | etapa | visita
    encerrada_em                DATETIME NULL,
    motivo_encerramento         VARCHAR(30) NULL,           -- transferencia | inatividade | mudanca_equipe | excluido | reatribuicao
    reconstruido                TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = criado pela retroalimentação a partir de fontes registradas
    criado_em                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_latr_usuario (empresa_id, usuario_id, atribuido_em),
    KEY idx_latr_estrutura (empresa_id, superintendencia_id, gerente_id, equipe_id, atribuido_em),
    KEY idx_latr_lead (lead_id, encerrada_em),
    KEY idx_latr_periodo (empresa_id, atribuido_em),
    CONSTRAINT fk_latr_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_latr_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_etapa_historico (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    lead_id         INT UNSIGNED NOT NULL,
    de_etapa_id     INT UNSIGNED NULL,
    para_etapa_id   INT UNSIGNED NOT NULL,
    de_categoria    VARCHAR(30) NULL,
    para_categoria  VARCHAR(30) NOT NULL,
    usuario_id      INT UNSIGNED NULL,          -- quem moveu (NULL = automação)
    responsavel_id  INT UNSIGNED NULL,          -- corretor dono do lead no momento
    origem          ENUM('usuario','jornada','sistema') NOT NULL,
    ocorrido_em     DATETIME NOT NULL,
    reconstruido    TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_leh_lead (lead_id, ocorrido_em),
    KEY idx_leh_periodo (empresa_id, ocorrido_em),
    KEY idx_leh_para (empresa_id, para_categoria, ocorrido_em),
    CONSTRAINT fk_leh_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_leh_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_contratos (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id          INT UNSIGNED NOT NULL,
    lead_id             INT UNSIGNED NOT NULL,
    empreendimento_id   INT UNSIGNED NULL,
    usuario_id          INT UNSIGNED NULL,          -- corretor responsável pela venda
    equipe_id           INT UNSIGNED NULL,          -- estrutura NA DATA da assinatura
    gerente_id          INT UNSIGNED NULL,
    superintendencia_id INT UNSIGNED NULL,
    assinado_em         DATE NOT NULL,
    valor_vgv           DECIMAL(14,2) NULL,         -- Valor Geral de Vendas (não é receita nem lucro)
    unidade             VARCHAR(60) NULL,
    observacao          VARCHAR(500) NULL,
    status              ENUM('registrado','validado','distratado') NOT NULL DEFAULT 'registrado',
    registrado_por      INT UNSIGNED NULL,
    registrado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    validado_por        INT UNSIGNED NULL,
    validado_em         DATETIME NULL,
    distratado_em       DATE NULL,
    distrato_motivo     VARCHAR(190) NULL,
    KEY idx_lc_periodo (empresa_id, assinado_em),
    KEY idx_lc_lead (lead_id),
    CONSTRAINT fk_lc_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_lc_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roleta_fila_historico (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id  INT UNSIGNED NOT NULL,
    lead_id     INT UNSIGNED NOT NULL,
    roleta_id   INT UNSIGNED NULL,
    origem      VARCHAR(20) NULL,
    entrou_em   DATETIME NOT NULL,
    saiu_em     DATETIME NULL,
    resultado   VARCHAR(20) NULL,       -- distribuido | atribuido_manual | oferta_ativa | excluido
    reconstruido TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_rfh_periodo (empresa_id, entrou_em),
    KEY idx_rfh_lead (lead_id, saiu_em),
    CONSTRAINT fk_rfh_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_rfh_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_origens (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id  INT UNSIGNED NOT NULL,
    nome        VARCHAR(80) NOT NULL,
    canal       ENUM('meta_ads','manual','importacao','indicacao','outro') NOT NULL,  -- grupo do sistema (compatível com leads.origem)
    sistema     TINYINT(1) NOT NULL DEFAULT 0,  -- usadas pelas integrações: não podem ser apagadas
    ativa       TINYINT(1) NOT NULL DEFAULT 1,
    ordem       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lead_origem (empresa_id, nome),
    CONSTRAINT fk_lorig_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colunas novas em leads.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'origem_detalhe_id');
SET @sql := IF(@x = 0, 'ALTER TABLE leads
    ADD COLUMN origem_detalhe_id INT UNSIGNED NULL,
    ADD COLUMN utm_source VARCHAR(120) NULL,
    ADD COLUMN utm_medium VARCHAR(120) NULL,
    ADD COLUMN utm_campaign VARCHAR(190) NULL,
    ADD COLUMN utm_content VARCHAR(190) NULL,
    ADD COLUMN utm_term VARCHAR(190) NULL,
    ADD COLUMN primeiro_contato_efetivo_em DATETIME NULL,
    ADD COLUMN chegada_roleta_id INT UNSIGNED NULL,
    ADD COLUMN chegada_roleta_aberta TINYINT(1) NULL,
    ADD KEY idx_leads_origem_detalhe (empresa_id, origem_detalhe_id)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Agenda: confirmação da visita com o cliente.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agenda_compromissos' AND COLUMN_NAME = 'confirmada_em');
SET @sql := IF(@x = 0, 'ALTER TABLE agenda_compromissos ADD COLUMN confirmada_em DATETIME NULL, ADD COLUMN confirmada_por INT UNSIGNED NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Motivos de transferência (só amplia o ENUM).
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_transferencias' AND COLUMN_NAME = 'motivo' AND COLUMN_TYPE LIKE '%voluntaria%');
SET @sql := IF(@x = 0, 'ALTER TABLE lead_transferencias MODIFY motivo ENUM(''administrativa'',''inatividade'',''mudanca_equipe'',''roleta'',''voluntaria'',''oferta_ativa'') NOT NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Origens padrão para cada empresa (o "canal" mantém compatibilidade com leads.origem).
INSERT IGNORE INTO lead_origens (empresa_id, nome, canal, sistema, ordem)
SELECT e.id, o.nome, o.canal, o.sistema, o.ordem FROM empresas e
JOIN (SELECT 'Meta Ads' AS nome, 'meta_ads' AS canal, 1 AS sistema, 1 AS ordem
      UNION ALL SELECT 'Google Ads', 'outro', 0, 2
      UNION ALL SELECT 'WhatsApp', 'outro', 0, 3
      UNION ALL SELECT 'Telefone', 'manual', 0, 4
      UNION ALL SELECT 'Indicação', 'indicacao', 1, 5
      UNION ALL SELECT 'Site', 'outro', 0, 6
      UNION ALL SELECT 'Landing page', 'outro', 0, 7
      UNION ALL SELECT 'Atendimento presencial', 'manual', 0, 8
      UNION ALL SELECT 'Ação comercial', 'manual', 0, 9
      UNION ALL SELECT 'Importação de base', 'importacao', 1, 10
      UNION ALL SELECT 'Cadastro manual (não detalhado)', 'manual', 1, 11
      UNION ALL SELECT 'Outros', 'outro', 1, 12) o;

-- Leads já existentes: só os canais que têm correspondência EXATA ganham o
-- detalhe (Meta Ads, Indicação, Importação). Manual e Outro ficam como
-- "não detalhado" — não dá para saber se foi Google, site ou telefone.
UPDATE leads l JOIN lead_origens o ON o.empresa_id = l.empresa_id AND o.sistema = 1 AND o.nome =
    CASE l.origem WHEN 'meta_ads' THEN 'Meta Ads' WHEN 'indicacao' THEN 'Indicação' WHEN 'importacao' THEN 'Importação de base'
                  WHEN 'manual' THEN 'Cadastro manual (não detalhado)' ELSE 'Outros' END
SET l.origem_detalhe_id = o.id WHERE l.origem_detalhe_id IS NULL;

-- Permissão nova: validar contratos (Master da empresa e Gerente Geral).
INSERT INTO permissoes (chave, rotulo, categoria) VALUES ('validar_contratos', 'Validar contratos registrados (vendas)', 'operacao')
ON DUPLICATE KEY UPDATE rotulo = VALUES(rotulo), categoria = VALUES(categoria);
INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
SELECT f.id, 'validar_contratos' FROM funcoes f WHERE f.nome IN ('Master da empresa', 'Gerente Geral') AND f.padrao_sistema = 1;
