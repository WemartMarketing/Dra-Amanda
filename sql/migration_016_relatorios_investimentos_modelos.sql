-- ==================================================================
-- Migração 016 — Central de Relatórios, Etapa 6
-- ==================================================================
-- Não destrutiva e idempotente.
--   marketing_investimentos  valor investido em anúncios, lançado à mão ou por
--                            planilha, por período, origem e (opcional)
--                            empreendimento/campanha. Base dos custos por
--                            resultado. Nunca estimado: sem lançamento, os
--                            custos ficam "não disponível".
--   relatorio_modelos        modelos de relatório personalizados (seções +
--                            filtros), por usuário.
--   permissão gerenciar_investimentos (Master da empresa).
-- ==================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_investimentos (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id          INT UNSIGNED NOT NULL,
    data_inicio         DATE NOT NULL,
    data_fim            DATE NOT NULL,
    origem_detalhe_id   INT UNSIGNED NOT NULL,       -- lead_origens: Meta Ads, Google Ads...
    empreendimento_id   INT UNSIGNED NULL,           -- NULL = todos os empreendimentos
    campanha            VARCHAR(190) NULL,           -- identificação livre (não usada no rateio)
    valor               DECIMAL(14,2) NOT NULL,
    descricao           VARCHAR(300) NULL,
    fonte               ENUM('manual','planilha') NOT NULL DEFAULT 'manual',
    criado_por          INT UNSIGNED NULL,
    criado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    excluido_em         DATETIME NULL,
    excluido_por        INT UNSIGNED NULL,
    KEY idx_mi_periodo (empresa_id, data_inicio, data_fim),
    KEY idx_mi_origem (empresa_id, origem_detalhe_id),
    CONSTRAINT fk_mi_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS relatorio_modelos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id  INT UNSIGNED NOT NULL,
    usuario_id  INT UNSIGNED NOT NULL,
    nome        VARCHAR(80) NOT NULL,
    secoes      VARCHAR(200) NOT NULL,              -- chaves dos relatórios, separadas por vírgula
    filtros     VARCHAR(2000) NOT NULL,             -- query string (revalidada ao usar)
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rel_modelo (usuario_id, empresa_id, nome),
    CONSTRAINT fk_relmod_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_relmod_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (chave, rotulo, categoria) VALUES ('gerenciar_investimentos', 'Lançar investimento em anúncios (custos dos relatórios)', 'relatorios')
ON DUPLICATE KEY UPDATE rotulo = VALUES(rotulo), categoria = VALUES(categoria);
INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
SELECT f.id, 'gerenciar_investimentos' FROM funcoes f WHERE f.nome = 'Master da empresa' AND f.padrao_sistema = 1;

-- Desempenho com alto volume (medido com 120 mil leads): 1ª entrega de cada lead.
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_atribuicoes' AND INDEX_NAME = 'idx_latr_lead_data');
SET @sql := IF(@x = 0, 'ALTER TABLE lead_atribuicoes ADD KEY idx_latr_lead_data (lead_id, atribuido_em, id)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
-- Funil em detalhe ("até onde chegaram"): maior categoria registrada por lead sem ler a linha inteira.
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_etapa_historico' AND INDEX_NAME = 'idx_leh_lead_cat');
SET @sql := IF(@x = 0, 'ALTER TABLE lead_etapa_historico ADD KEY idx_leh_lead_cat (lead_id, para_categoria)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
