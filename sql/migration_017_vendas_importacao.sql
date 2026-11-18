-- ==================================================================
-- Migração 017 — Inteligência de Vendas, Fase 2 (importação)
-- ==================================================================
-- Não destrutiva e idempotente. NÃO altera leads, Kanban nem automações.
--   vendas_lotes            cada envio de planilha (arquivo, quem, quando,
--                           mapeamento, contagens, retenção do arquivo).
--   vendas_lote_linhas      resultado de cada linha do lote (sem dados
--                           pessoais: só nº da linha, id de origem, motivo).
--   vendas_externas         a venda normalizada. Chave única: empresa +
--                           sistema de origem + id da venda na origem.
--   vendas_externas_versoes histórico quando uma venda já importada volta
--                           com alterações (dados pessoais: só "alterado").
--   permissões importar_vendas e ver_inteligencia_vendas (Master).
-- ==================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS vendas_lotes (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id           INT UNSIGNED NOT NULL,
    sistema_origem       VARCHAR(40) NOT NULL,
    arquivo_nome         VARCHAR(200) NOT NULL,
    arquivo_ext          VARCHAR(5) NOT NULL,
    arquivo_sha256       CHAR(64) NOT NULL,
    arquivo_guardado     TINYINT(1) NOT NULL DEFAULT 0,      -- 1 enquanto o original está em storage/vendas
    descartar_arquivo_em DATETIME NULL,
    usuario_id           INT UNSIGNED NULL,
    mapeamento           TEXT NOT NULL,                      -- JSON {campo: índice}
    situacoes_venda      VARCHAR(500) NOT NULL,              -- JSON [situações que contam como venda]
    atualizar_alteradas  TINYINT(1) NOT NULL DEFAULT 1,
    reprocessa_lote_id   INT UNSIGNED NULL,                  -- reprocessamento de outro lote
    status               ENUM('processando','concluido','falhou') NOT NULL DEFAULT 'processando',
    erro                 VARCHAR(300) NULL,
    linhas_lidas         INT UNSIGNED NOT NULL DEFAULT 0,
    incluidas            INT UNSIGNED NOT NULL DEFAULT 0,
    atualizadas          INT UNSIGNED NOT NULL DEFAULT 0,
    sem_alteracao        INT UNSIGNED NOT NULL DEFAULT 0,
    ignoradas            INT UNSIGNED NOT NULL DEFAULT 0,    -- repetidas no arquivo ou alteradas sem autorização
    com_erro             INT UNSIGNED NOT NULL DEFAULT 0,
    com_alerta           INT UNSIGNED NOT NULL DEFAULT 0,
    versao_importador    VARCHAR(10) NOT NULL,
    criado_em            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluido_em         DATETIME NULL,
    KEY idx_vl_empresa (empresa_id, criado_em),
    KEY idx_vl_descarte (arquivo_guardado, descartar_arquivo_em),
    CONSTRAINT fk_vl_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendas_externas (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id            INT UNSIGNED NOT NULL,
    sistema_origem        VARCHAR(40) NOT NULL,
    chave_origem          VARCHAR(60) NOT NULL,              -- "id" da venda no sistema externo
    venda_rm              VARCHAR(60) NULL,
    primeiro_lote_id      INT UNSIGNED NOT NULL,
    ultimo_lote_id        INT UNSIGNED NOT NULL,
    situacao              VARCHAR(60) NULL,
    eh_venda              TINYINT(1) NOT NULL DEFAULT 1,     -- situação marcada como "venda" no lote
    data_venda            DATETIME NULL,
    data_venda_so_data    TINYINT(1) NOT NULL DEFAULT 1,
    data_cadastro_origem  DATETIME NULL,                     -- dado importado; NUNCA substitui a entrada no CRM
    data_envio_rm         DATETIME NULL,
    cliente_nome          VARCHAR(190) NULL,
    telefone1             VARCHAR(16) NULL,                  -- 55 + DDD + número, só dígitos
    telefone2             VARCHAR(16) NULL,
    email                 VARCHAR(190) NULL,
    cpf_hash              CHAR(64) NULL,                     -- HMAC-SHA256; o CPF em claro não é guardado
    cpf_final             CHAR(2) NULL,
    empreendimento_texto  VARCHAR(190) NULL,
    empreendimento_id     INT UNSIGNED NULL,                 -- preenchido pela equivalência (Fase 3)
    bloco                 VARCHAR(40) NULL,
    unidade               VARCHAR(40) NULL,
    imobiliaria_texto     VARCHAR(190) NULL,
    corretor_id_origem    VARCHAR(40) NULL,
    corretor_texto        VARCHAR(190) NULL,
    superintendente_texto VARCHAR(190) NULL,
    gerente_texto         VARCHAR(190) NULL,
    coordenador_texto     VARCHAR(190) NULL,
    midia_texto           VARCHAR(190) NULL,                 -- mídia DECLARADA NA VENDA (≠ origem do lead no CRM)
    modalidade            VARCHAR(190) NULL,
    valor                 DECIMAL(14,2) NULL,                -- valor de venda (VGV da unidade); não é receita
    tabela                DECIMAL(14,2) NULL,
    desconto              DECIMAL(14,2) NULL,
    entrada               DECIMAL(14,2) NULL,
    subsidio              DECIMAL(14,2) NULL,
    fgts                  DECIMAL(14,2) NULL,
    financiamento         DECIMAL(14,2) NULL,
    comissao_cliente      DECIMAL(14,2) NULL,
    comissao_empresa      DECIMAL(14,2) NULL,
    valor_pagamento       DECIMAL(14,2) NULL,
    extras                MEDIUMTEXT NULL,                   -- JSON: colunas sem campo próprio (não pessoais)
    alertas               TEXT NULL,                         -- JSON: inconsistências encontradas na linha
    hash_conteudo         CHAR(64) NOT NULL,
    criado_em             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em         DATETIME NULL,
    UNIQUE KEY uq_ve_origem (empresa_id, sistema_origem, chave_origem),
    KEY idx_ve_rm (empresa_id, sistema_origem, venda_rm),
    KEY idx_ve_data (empresa_id, data_venda),
    KEY idx_ve_tel1 (empresa_id, telefone1),
    KEY idx_ve_email (empresa_id, email),
    CONSTRAINT fk_ve_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendas_lote_linhas (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lote_id      INT UNSIGNED NOT NULL,
    linha_num    INT UNSIGNED NOT NULL,                      -- linha na planilha (cabeçalho = 1)
    chave_origem VARCHAR(60) NULL,
    resultado    ENUM('incluida','atualizada','sem_alteracao','ignorada','erro') NOT NULL,
    venda_id     INT UNSIGNED NULL,
    motivo       VARCHAR(300) NULL,
    alertas      VARCHAR(600) NULL,
    KEY idx_vll_lote (lote_id, resultado),
    CONSTRAINT fk_vll_lote FOREIGN KEY (lote_id) REFERENCES vendas_lotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendas_externas_versoes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venda_id    INT UNSIGNED NOT NULL,
    lote_id     INT UNSIGNED NOT NULL,
    usuario_id  INT UNSIGNED NULL,
    alteracoes  TEXT NOT NULL,                               -- JSON {campo: [antes, depois]} ou {campo: "alterado"} p/ dados pessoais
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vev_venda (venda_id, criado_em),
    CONSTRAINT fk_vev_venda FOREIGN KEY (venda_id) REFERENCES vendas_externas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (chave, rotulo, categoria) VALUES ('importar_vendas', 'Importar planilhas de vendas (Inteligência de Vendas)', 'relatorios')
ON DUPLICATE KEY UPDATE rotulo = VALUES(rotulo), categoria = VALUES(categoria);
INSERT INTO permissoes (chave, rotulo, categoria) VALUES ('ver_inteligencia_vendas', 'Ver a Inteligência de Vendas (conciliação e jornada)', 'relatorios')
ON DUPLICATE KEY UPDATE rotulo = VALUES(rotulo), categoria = VALUES(categoria);
INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
SELECT f.id, 'importar_vendas' FROM funcoes f WHERE f.nome = 'Master da empresa' AND f.padrao_sistema = 1;
INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
SELECT f.id, 'ver_inteligencia_vendas' FROM funcoes f WHERE f.nome = 'Master da empresa' AND f.padrao_sistema = 1;
