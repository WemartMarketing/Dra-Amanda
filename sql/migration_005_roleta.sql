-- WeMart Sales — Migração 005: roleta de distribuição (Etapa 4 do prompt mestre).
--
-- Seção 9: o corretor só entra na roleta de um plantão quando está
-- vinculado, dentro do horário, com localização válida e dentro do raio.
-- A presença vale enquanto o navegador renova a verificação periodicamente
-- (nada é prometido com o navegador fechado: sem renovação, a presença
-- expira sozinha). A distribuição é um rodízio controlado no banco, com
-- trava de linha para não entregar o mesmo lead duas vezes nem pular
-- corretores em acessos simultâneos. Leads sem corretor disponível vão
-- para uma fila de espera, sem perder informação.
--
-- Idempotente.

SET NAMES utf8mb4;

-- ------------------------------------------------------------------
-- Regras da roleta
-- ------------------------------------------------------------------
-- ISO: 1=segunda … 7=domingo
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roletas' AND COLUMN_NAME = 'dias_semana');
SET @sql := IF(@x = 0, 'ALTER TABLE roletas ADD COLUMN dias_semana VARCHAR(20) NOT NULL DEFAULT ''1,2,3,4,5,6'' AFTER horario_fim', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roletas' AND COLUMN_NAME = 'exigir_presenca');
SET @sql := IF(@x = 0, 'ALTER TABLE roletas ADD COLUMN exigir_presenca TINYINT(1) NOT NULL DEFAULT 1 AFTER dias_semana', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- GPS com precisão pior que isto é recusado
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roletas' AND COLUMN_NAME = 'precisao_maxima_metros');
SET @sql := IF(@x = 0, 'ALTER TABLE roletas ADD COLUMN precisao_maxima_metros INT UNSIGNED NOT NULL DEFAULT 150 AFTER exigir_presenca', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sem nova verificação nesse intervalo, a presença expira
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roletas' AND COLUMN_NAME = 'validade_presenca_min');
SET @sql := IF(@x = 0, 'ALTER TABLE roletas ADD COLUMN validade_presenca_min SMALLINT UNSIGNED NOT NULL DEFAULT 20 AFTER precisao_maxima_metros', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roletas' AND COLUMN_NAME = 'intervalo_verificacao_min');
SET @sql := IF(@x = 0, 'ALTER TABLE roletas ADD COLUMN intervalo_verificacao_min SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER validade_presenca_min', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roletas' AND COLUMN_NAME = 'regra_distribuicao');
SET @sql := IF(@x = 0, 'ALTER TABLE roletas ADD COLUMN regra_distribuicao ENUM(''rodizio'') NOT NULL DEFAULT ''rodizio'' AFTER intervalo_verificacao_min', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- NULL = sem limite
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roletas' AND COLUMN_NAME = 'max_leads_dia_corretor');
SET @sql := IF(@x = 0, 'ALTER TABLE roletas ADD COLUMN max_leads_dia_corretor SMALLINT UNSIGNED NULL AFTER regra_distribuicao', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- Um empreendimento pode estar em várias roletas; menor prioridade = tentada primeiro (seção 13).
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_empreendimentos' AND COLUMN_NAME = 'prioridade');
SET @sql := IF(@x = 0, 'ALTER TABLE roleta_empreendimentos ADD COLUMN prioridade SMALLINT UNSIGNED NOT NULL DEFAULT 100', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- Estado do rodízio por corretor
-- pausado pelo gestor
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_corretores' AND COLUMN_NAME = 'pausado');
SET @sql := IF(@x = 0, 'ALTER TABLE roleta_corretores ADD COLUMN pausado TINYINT(1) NOT NULL DEFAULT 0 AFTER ordem', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_corretores' AND COLUMN_NAME = 'ultimo_lead_em');
SET @sql := IF(@x = 0, 'ALTER TABLE roleta_corretores ADD COLUMN ultimo_lead_em DATETIME(6) NULL AFTER pausado', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_corretores' AND COLUMN_NAME = 'total_recebidos');
SET @sql := IF(@x = 0, 'ALTER TABLE roleta_corretores ADD COLUMN total_recebidos INT UNSIGNED NOT NULL DEFAULT 0 AFTER ultimo_lead_em', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- Cada verificação de localização (entrada, renovação, saída) — histórico
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_presencas' AND COLUMN_NAME = 'tipo');
SET @sql := IF(@x = 0, 'ALTER TABLE roleta_presencas ADD COLUMN tipo ENUM(''entrada'',''verificacao'',''saida'',''recusada'') NOT NULL DEFAULT ''verificacao'' AFTER usuario_id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_presencas' AND COLUMN_NAME = 'precisao_metros');
SET @sql := IF(@x = 0, 'ALTER TABLE roleta_presencas ADD COLUMN precisao_metros DECIMAL(8,1) NULL AFTER longitude', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_presencas' AND COLUMN_NAME = 'distancia_metros');
SET @sql := IF(@x = 0, 'ALTER TABLE roleta_presencas ADD COLUMN distancia_metros DECIMAL(10,1) NULL AFTER precisao_metros', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roleta_presencas' AND COLUMN_NAME = 'motivo');
SET @sql := IF(@x = 0, 'ALTER TABLE roleta_presencas ADD COLUMN motivo VARCHAR(120) NULL AFTER dentro_do_raio', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ------------------------------------------------------------------
-- Sessão de presença (quem está "dentro da roleta" agora)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roleta_sessoes (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id              INT UNSIGNED NOT NULL,
    roleta_id               INT UNSIGNED NOT NULL,
    usuario_id              INT UNSIGNED NOT NULL,
    entrou_em               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultima_verificacao_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    saiu_em                 DATETIME NULL,
    motivo_saida            ENUM('manual','fora_do_raio','expirou','fora_do_horario','roleta_pausada','removido','gestor') NULL,
    -- "roleta_id:usuario_id" enquanto a sessão está aberta e NULL depois de
    -- fechada (mantido por includes/roleta.php). O UNIQUE garante no máximo
    -- UMA sessão aberta por corretor por roleta. Não é coluna gerada porque o
    -- MySQL não permite coluna gerada sobre colunas com FK ON DELETE CASCADE.
    aberta_chave            VARCHAR(40) NULL,
    UNIQUE KEY uq_sessao_aberta (aberta_chave),
    INDEX idx_sessoes_roleta (roleta_id, saiu_em, ultima_verificacao_em),
    INDEX idx_sessoes_usuario (usuario_id, saiu_em),
    CONSTRAINT fk_sessoes_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_sessoes_roleta FOREIGN KEY (roleta_id) REFERENCES roletas(id) ON DELETE CASCADE,
    CONSTRAINT fk_sessoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Histórico de distribuição (seção 9: "Consultar histórico de distribuição")
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roleta_distribuicoes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    roleta_id       INT UNSIGNED NOT NULL,
    lead_id         INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,
    origem          ENUM('manual','meta_ads','importacao','fila','reprocessamento') NOT NULL DEFAULT 'manual',
    executado_por   INT UNSIGNED NULL, -- NULL = automático
    distribuido_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dist_roleta (roleta_id, distribuido_em),
    INDEX idx_dist_usuario (usuario_id, distribuido_em),
    UNIQUE KEY uq_dist_lead (lead_id), -- um lead entra pela roleta uma única vez
    CONSTRAINT fk_dist_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_dist_roleta FOREIGN KEY (roleta_id) REFERENCES roletas(id) ON DELETE CASCADE,
    CONSTRAINT fk_dist_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_dist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Fila de espera (lead sem corretor disponível — seções 9 e 10)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roleta_fila (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    roleta_id       INT UNSIGNED NULL,  -- NULL = nenhuma roleta atende o empreendimento
    lead_id         INT UNSIGNED NOT NULL,
    origem          ENUM('manual','meta_ads','importacao','reprocessamento') NOT NULL DEFAULT 'manual',
    motivo          VARCHAR(190) NULL,
    tentativas      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    entrou_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolvido_em    DATETIME NULL,
    UNIQUE KEY uq_fila_lead (lead_id),
    INDEX idx_fila_pendente (empresa_id, resolvido_em, entrou_em),
    CONSTRAINT fk_fila_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_fila_roleta FOREIGN KEY (roleta_id) REFERENCES roletas(id) ON DELETE SET NULL,
    CONSTRAINT fk_fila_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Permissão nova: participar da roleta (fazer check-in de presença)
-- ------------------------------------------------------------------
INSERT INTO permissoes (chave, rotulo, categoria) VALUES
    ('participar_roleta', 'Entrar na roleta (presença no plantão)', 'operacao')
ON DUPLICATE KEY UPDATE rotulo = VALUES(rotulo), categoria = VALUES(categoria);

INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
SELECT f.id, 'participar_roleta' FROM funcoes f
WHERE f.nome IN ('Corretor', 'Master da empresa') AND f.padrao_sistema = 1;
