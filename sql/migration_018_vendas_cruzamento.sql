-- ==================================================================
-- Migração 018 — Inteligência de Vendas, Fase 3 (cruzamento)
-- ==================================================================
-- Não destrutiva e idempotente. NÃO altera leads, Kanban nem automações.
--   contato_identificadores      índice normalizado telefone/e-mail → lead
--                                (reconstruído pelo motor; nunca é a fonte).
--   vendas_correspondencias      resultado do cruzamento de cada venda
--                                (status automático + decisão humana).
--   vendas_correspondencia_leads leads ligados/candidatos e a evidência.
--   vendas_revisoes              decisões humanas, com justificativa.
--   vendas_conciliacao_log       histórico: cada mudança de status.
--   vendas_equivalencias         nome/código do sistema externo → cadastro
--                                do WeMart (empreendimento, corretor).
--   imobiliarias_parceiras       canais externos, para classificar vendas.
--   permissão revisar_correspondencias (Master).
-- ==================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS contato_identificadores (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id  INT UNSIGNED NOT NULL,
    lead_id     INT UNSIGNED NOT NULL,
    tipo        ENUM('telefone','email','telefone_variante') NOT NULL,   -- variante: celular antigo sem o 9 (só aponta candidato)
    valor       VARCHAR(190) NOT NULL,
    fonte       ENUM('lead','whatsapp') NOT NULL,
    UNIQUE KEY uq_ci (empresa_id, tipo, valor, lead_id, fonte),
    KEY idx_ci_lead (lead_id),
    CONSTRAINT fk_ci_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendas_correspondencias (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id          INT UNSIGNED NOT NULL,
    venda_id            INT UNSIGNED NOT NULL,
    status              ENUM('forte','telefone','email','ambigua','sem','posterior') NOT NULL,   -- resultado AUTOMÁTICO
    motivo              VARCHAR(300) NOT NULL,
    cronologia          ENUM('anterior','mesmo_dia','posterior','desconhecida') NULL,
    primeira_entrada_em DATETIME NULL,          -- 1ª entrada comprovada no CRM dos leads ligados
    alertas             VARCHAR(600) NULL,
    regras_versao       VARCHAR(10) NOT NULL,
    decisao             ENUM('automatica','confirmada','rejeitada') NOT NULL DEFAULT 'automatica',
    vinculada           TINYINT(1) NOT NULL DEFAULT 0,   -- identidade estabelecida (automática sem conflito ou confirmada)
    revisado_por        INT UNSIGNED NULL,
    revisado_em         DATETIME NULL,
    calculado_em        DATETIME NOT NULL,
    UNIQUE KEY uq_vc_venda (venda_id),
    KEY idx_vc_status (empresa_id, status, decisao),
    CONSTRAINT fk_vc_venda FOREIGN KEY (venda_id) REFERENCES vendas_externas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendas_correspondencia_leads (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venda_id   INT UNSIGNED NOT NULL,
    lead_id    INT UNSIGNED NOT NULL,
    evidencia  ENUM('telefone_email','telefone','email','variante','manual') NOT NULL,
    papel      ENUM('vinculado','candidato','descartado') NOT NULL,
    UNIQUE KEY uq_vcl (venda_id, lead_id),
    KEY idx_vcl_lead (lead_id),
    CONSTRAINT fk_vcl_venda FOREIGN KEY (venda_id) REFERENCES vendas_externas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendas_revisoes (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id    INT UNSIGNED NOT NULL,
    venda_id      INT UNSIGNED NOT NULL,
    usuario_id    INT UNSIGNED NULL,
    decisao       ENUM('confirmar','rejeitar','reabrir') NOT NULL,
    lead_ids      VARCHAR(500) NULL,
    justificativa VARCHAR(500) NOT NULL,
    criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vr_venda (venda_id, criado_em),
    CONSTRAINT fk_vr_venda FOREIGN KEY (venda_id) REFERENCES vendas_externas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendas_conciliacao_log (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venda_id         INT UNSIGNED NOT NULL,
    origem           ENUM('cruzamento','revisao') NOT NULL,
    status_anterior  VARCHAR(20) NULL,
    status_novo      VARCHAR(20) NOT NULL,
    leads_anteriores VARCHAR(500) NULL,
    leads_novos      VARCHAR(500) NULL,
    regras_versao    VARCHAR(10) NOT NULL,
    usuario_id       INT UNSIGNED NULL,
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vcl_log (venda_id, criado_em),
    CONSTRAINT fk_vclog_venda FOREIGN KEY (venda_id) REFERENCES vendas_externas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS imobiliarias_parceiras (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id  INT UNSIGNED NOT NULL,
    nome        VARCHAR(190) NOT NULL,
    ativa       TINYINT(1) NOT NULL DEFAULT 1,
    criado_por  INT UNSIGNED NULL,
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_imob (empresa_id, nome),
    CONSTRAINT fk_imob_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendas_equivalencias (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id       INT UNSIGNED NOT NULL,
    sistema_origem   VARCHAR(40) NOT NULL,
    tipo             ENUM('empreendimento','corretor') NOT NULL,
    valor_origem     VARCHAR(190) NOT NULL,          -- normalizado (empreendimento: nome comparável; corretor: id do sistema externo)
    valor_texto      VARCHAR(190) NOT NULL,          -- como veio na planilha
    classificacao    ENUM('crm','externo','nao_existe','nao_identificado') NOT NULL,
    empreendimento_id INT UNSIGNED NULL,             -- tipo empreendimento + crm
    usuario_id       INT UNSIGNED NULL,              -- tipo corretor + crm
    imobiliaria_id   INT UNSIGNED NULL,              -- tipo corretor + externo (opcional)
    confirmado_por   INT UNSIGNED NULL,
    confirmado_em    DATETIME NULL,
    UNIQUE KEY uq_veq (empresa_id, sistema_origem, tipo, valor_origem),
    CONSTRAINT fk_veq_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (chave, rotulo, categoria) VALUES ('revisar_correspondencias', 'Revisar correspondências de vendas e equivalências', 'relatorios')
ON DUPLICATE KEY UPDATE rotulo = VALUES(rotulo), categoria = VALUES(categoria);
INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
SELECT f.id, 'revisar_correspondencias' FROM funcoes f WHERE f.nome = 'Master da empresa' AND f.padrao_sistema = 1;
