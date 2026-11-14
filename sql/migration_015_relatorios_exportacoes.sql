-- ==================================================================
-- Migração 015 — Central de Relatórios, Etapa 5: exportações grandes
-- ==================================================================
-- Não destrutiva e idempotente. Exportações grandes são preparadas pelo
-- cron (cron/rotinas.php) e baixadas depois por um link temporário que só
-- funciona para quem pediu, com login (api/exportacao_baixar.php). O arquivo
-- fica em storage/exportacoes/ (bloqueado para o navegador) e é apagado
-- quando expira (24 h).
-- ==================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS relatorio_exportacoes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,
    relatorio       VARCHAR(30) NOT NULL,
    formato         VARCHAR(5) NOT NULL,
    nivel           VARCHAR(10) NOT NULL,
    parametros      VARCHAR(2000) NOT NULL,       -- filtros (query string), revalidados com a permissão atual ao gerar
    status          ENUM('pendente','gerando','pronto','erro','expirado') NOT NULL DEFAULT 'pendente',
    arquivo         VARCHAR(80) NULL,             -- nome aleatório em storage/exportacoes
    nome_download   VARCHAR(190) NULL,
    token_hash      CHAR(64) NOT NULL,            -- sha256 do token do link (o token nunca é gravado)
    linhas          INT UNSIGNED NULL,
    tamanho         INT UNSIGNED NULL,
    erro            VARCHAR(300) NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    iniciado_em     DATETIME NULL,
    concluido_em    DATETIME NULL,
    expira_em       DATETIME NULL,
    baixado_em      DATETIME NULL,
    KEY idx_relexp_fila (status, criado_em),
    KEY idx_relexp_usuario (usuario_id, criado_em),
    CONSTRAINT fk_relexp_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_relexp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
