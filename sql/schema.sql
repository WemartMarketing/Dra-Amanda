-- WeMart Sales — schema MySQL/MariaDB (compatível com HostGator compartilhado)
--
-- Convenções:
--   * Todo dado operacional carrega empresa_id — isolamento multiempresa é
--     reforçado na camada de aplicação (toda query de leitura/escrita passa
--     por WHERE empresa_id = :empresa_id), nunca só confiado ao schema.
--   * Cargos (usuarios.cargo) definem o escopo de visualização (ver
--     docs/RBAC.md): master_wemart enxerga todas as empresas; os demais
--     cargos são sempre escopados a uma empresa.
--   * Timestamps em UTC; a camada de aplicação formata para exibição.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------
-- Empresas (tenants)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS empresas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome            VARCHAR(190) NOT NULL,
    slug            VARCHAR(80) NOT NULL UNIQUE,
    ativa           TINYINT(1) NOT NULL DEFAULT 1,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Superintendências e equipes (hierarquia comercial dentro da empresa)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS superintendencias (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id          INT UNSIGNED NOT NULL,
    nome                VARCHAR(190) NOT NULL,
    superintendente_id  INT UNSIGNED NULL, -- referencia usuarios.id; FK adicionada após criar usuarios
    criado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_superintendencias_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipes (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id          INT UNSIGNED NOT NULL,
    superintendencia_id INT UNSIGNED NULL,
    nome                VARCHAR(190) NOT NULL,
    gerente_id          INT UNSIGNED NULL, -- referencia usuarios.id; FK adicionada após criar usuarios
    criado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_equipes_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_equipes_superintendencia FOREIGN KEY (superintendencia_id) REFERENCES superintendencias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Usuários
-- ------------------------------------------------------------------
-- Cargos e escopo de visualização (ver tela "Usuários e acessos"):
--   master_wemart     -> todas as empresas (administra a plataforma)
--   master_empresa    -> toda a empresa
--   gp_lider          -> todos os produtos da empresa
--   gerente_geral     -> todos os produtos da empresa
--   gp_produto        -> produtos vinculados (ver tabela usuario_empreendimentos)
--   superintendente   -> sua superintendência (onde superintendencias.superintendente_id = usuarios.id)
--   gerente           -> sua equipe (onde equipes.gerente_id = usuarios.id)
--   corretor          -> sua própria carteira de leads (leads.responsavel_id = usuarios.id)
CREATE TABLE IF NOT EXISTS usuarios (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NULL, -- NULL apenas para master_wemart
    equipe_id       INT UNSIGNED NULL,
    nome            VARCHAR(190) NOT NULL,
    email           VARCHAR(190) NOT NULL UNIQUE,
    senha_hash      VARCHAR(255) NOT NULL,
    cargo           ENUM('master_wemart','master_empresa','gp_lider','gerente_geral','gp_produto','superintendente','gerente','corretor') NOT NULL,
    ativo           TINYINT(1) NOT NULL DEFAULT 1,
    ultimo_login_em DATETIME NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuarios_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_usuarios_equipe FOREIGN KEY (equipe_id) REFERENCES equipes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE equipes
    ADD CONSTRAINT fk_equipes_gerente FOREIGN KEY (gerente_id) REFERENCES usuarios(id) ON DELETE SET NULL;

ALTER TABLE superintendencias
    ADD CONSTRAINT fk_superintendencias_superintendente FOREIGN KEY (superintendente_id) REFERENCES usuarios(id) ON DELETE SET NULL;

-- ------------------------------------------------------------------
-- Empreendimentos (produtos) e vínculo de GP do Produto
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS empreendimentos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    nome            VARCHAR(190) NOT NULL,
    ativo           TINYINT(1) NOT NULL DEFAULT 1,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_empreendimentos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuario_empreendimentos (
    usuario_id          INT UNSIGNED NOT NULL,
    empreendimento_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (usuario_id, empreendimento_id),
    CONSTRAINT fk_ue_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_ue_empreendimento FOREIGN KEY (empreendimento_id) REFERENCES empreendimentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Leads e seu funil (etapas fixas do Kanban, ver tela 02)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leads (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id          INT UNSIGNED NOT NULL,
    empreendimento_id   INT UNSIGNED NULL,
    equipe_id           INT UNSIGNED NULL,
    responsavel_id      INT UNSIGNED NULL, -- corretor dono do lead no momento
    nome                VARCHAR(190) NOT NULL,
    telefone            VARCHAR(30) NULL,
    email               VARCHAR(190) NULL,
    interesse           VARCHAR(190) NULL,
    renda_informada     DECIMAL(12,2) NULL,
    origem              ENUM('meta_ads','manual','importacao','indicacao','outro') NOT NULL DEFAULT 'manual',
    etapa               ENUM('novo_lead','primeiro_contato','em_atendimento','visita_agendada','visita_realizada','contrato_assinado','perdido') NOT NULL DEFAULT 'novo_lead',
    motivo_perda        VARCHAR(190) NULL, -- preenchido só quando etapa = 'perdido'
    proximo_passo       VARCHAR(190) NULL,
    data_retorno        DATE NULL,
    ultimo_contato_em   DATETIME NULL,
    criado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_leads_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_leads_empreendimento FOREIGN KEY (empreendimento_id) REFERENCES empreendimentos(id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_equipe FOREIGN KEY (equipe_id) REFERENCES equipes(id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_responsavel FOREIGN KEY (responsavel_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_leads_empresa_etapa (empresa_id, etapa),
    INDEX idx_leads_responsavel (responsavel_id),
    -- Regra de atenção (ver tela 01 e LEIA-ME): leads sem retorno há mais de
    -- N dias podem ser redistribuídos — consultada por data_retorno/ultimo_contato_em,
    -- não precisa de coluna própria.
    INDEX idx_leads_retorno (data_retorno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico/timeline do lead (seção "Histórico" na ficha do lead)
CREATE TABLE IF NOT EXISTS lead_eventos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id         INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NULL,
    tipo            ENUM('atribuicao','mudanca_etapa','contato','redistribuicao','nota','sistema') NOT NULL,
    descricao       VARCHAR(500) NOT NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_eventos_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_eventos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_eventos_lead (lead_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Próximas ações / tarefas (painel "Próximas ações" na ficha do lead)
CREATE TABLE IF NOT EXISTS lead_tarefas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id         INT UNSIGNED NOT NULL,
    titulo          VARCHAR(190) NOT NULL,
    descricao       VARCHAR(500) NULL,
    data_vencimento DATE NULL,
    concluida       TINYINT(1) NOT NULL DEFAULT 0,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tarefas_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Roletas de distribuição (plantão, geolocalização, corretores habilitados)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roletas (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id              INT UNSIGNED NOT NULL,
    nome                    VARCHAR(190) NOT NULL,
    ativa                   TINYINT(1) NOT NULL DEFAULT 1,
    raio_presenca_metros    INT UNSIGNED NULL,       -- NULL = geolocalização desativada
    horario_inicio          TIME NULL,
    horario_fim             TIME NULL,
    latitude_centro         DECIMAL(10,7) NULL,
    longitude_centro        DECIMAL(10,7) NULL,
    criado_em               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_roletas_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roleta_empreendimentos (
    roleta_id           INT UNSIGNED NOT NULL,
    empreendimento_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (roleta_id, empreendimento_id),
    CONSTRAINT fk_re_roleta FOREIGN KEY (roleta_id) REFERENCES roletas(id) ON DELETE CASCADE,
    CONSTRAINT fk_re_empreendimento FOREIGN KEY (empreendimento_id) REFERENCES empreendimentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roleta_corretores (
    roleta_id       INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,
    ordem           INT UNSIGNED NOT NULL DEFAULT 0, -- posição no rodízio
    PRIMARY KEY (roleta_id, usuario_id),
    CONSTRAINT fk_rc_roleta FOREIGN KEY (roleta_id) REFERENCES roletas(id) ON DELETE CASCADE,
    CONSTRAINT fk_rc_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Presença confirmada de um corretor num plantão/roleta (para a regra de
-- raio + horário decidir quem está apto a receber o próximo lead)
CREATE TABLE IF NOT EXISTS roleta_presencas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    roleta_id       INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,
    latitude        DECIMAL(10,7) NULL,
    longitude       DECIMAL(10,7) NULL,
    dentro_do_raio  TINYINT(1) NOT NULL DEFAULT 0,
    confirmado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_presenca_roleta FOREIGN KEY (roleta_id) REFERENCES roletas(id) ON DELETE CASCADE,
    CONSTRAINT fk_presenca_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_presenca_roleta_usuario (roleta_id, usuario_id, confirmado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Auditoria (eventos administrativos — ver requisito "auditoria de eventos")
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auditoria (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NULL,
    usuario_id      INT UNSIGNED NULL,
    acao            VARCHAR(80) NOT NULL,     -- ex.: 'lead.mudou_etapa', 'usuario.criado'
    entidade        VARCHAR(80) NOT NULL,     -- ex.: 'lead', 'usuario', 'roleta'
    entidade_id     INT UNSIGNED NULL,
    detalhes_json   TEXT NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_auditoria_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
    CONSTRAINT fk_auditoria_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_auditoria_empresa (empresa_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
