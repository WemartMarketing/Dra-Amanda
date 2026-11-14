-- ==================================================================
-- Migração 014 — Central de Relatórios, Etapa 3: filtros favoritos
-- ==================================================================
-- Não destrutiva e idempotente. Cada usuário guarda combinações de filtros
-- por relatório (só os filtros, nunca resultados nem dados de leads).
-- ==================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS relatorio_favoritos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id  INT UNSIGNED NOT NULL,
    usuario_id  INT UNSIGNED NOT NULL,
    relatorio   VARCHAR(30) NOT NULL,
    nome        VARCHAR(80) NOT NULL,
    filtros     VARCHAR(2000) NOT NULL,     -- query string dos filtros (validada de novo ao abrir)
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rel_fav (usuario_id, empresa_id, relatorio, nome),
    CONSTRAINT fk_relfav_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_relfav_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
