-- ==================================================================
-- Migração 012 — Plantões de vendas: endereço completo, foto, horário,
-- corretores autorizados (tela operacional com rotas Google Maps/Waze)
-- ==================================================================
-- Amplia a tabela `plantoes` que já existe (não cria outra). Idempotente.
--   * cep, complemento, foto (imagem do stand), horario_descricao (para
--     plantões sem roleta — com roleta, o horário vem da própria roleta),
--     observacoes (ex.: "estacionamento pela rua lateral"), atualizado_em.
--   * plantao_usuarios: corretores autorizados no plantão (além dos que já
--     estão nas roletas dele). Usado no filtro "Meus plantões".
-- ==================================================================

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plantoes' AND COLUMN_NAME = 'cep');
SET @sql := IF(@x = 0, 'ALTER TABLE plantoes
    ADD COLUMN cep CHAR(9) NULL AFTER endereco,
    ADD COLUMN complemento VARCHAR(120) NULL AFTER cep,
    ADD COLUMN foto VARCHAR(64) NULL,
    ADD COLUMN horario_descricao VARCHAR(190) NULL,
    ADD COLUMN observacoes VARCHAR(500) NULL,
    ADD COLUMN atualizado_em DATETIME NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS plantao_usuarios (
    plantao_id  INT UNSIGNED NOT NULL,
    usuario_id  INT UNSIGNED NOT NULL,
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (plantao_id, usuario_id),
    KEY idx_plantao_usuarios_usuario (usuario_id),
    CONSTRAINT fk_pu_plantao FOREIGN KEY (plantao_id) REFERENCES plantoes(id) ON DELETE CASCADE,
    CONSTRAINT fk_pu_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
