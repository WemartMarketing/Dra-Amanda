-- ==================================================================
-- Migração 011 — Origem do lead: anúncio e criativo do Meta Ads
-- ==================================================================
-- Idempotente: pode rodar mais de uma vez (MySQL 5.7+/MariaDB 10.3+).
--
--   * meta_anuncios: UM registro por anúncio da empresa (campanha,
--     conjunto, anúncio, criativo, textos, CTA, mídia em cache, link
--     oficial). Vários leads apontam para o mesmo anúncio — a API só é
--     consultada uma vez por anúncio (e de novo só quando vence o cache).
--   * leads: os ids de origem gravados NO LEAD no momento do cadastro
--     (continuam lá mesmo que a campanha seja pausada ou excluída na Meta,
--     e não mudam em transferências).
--   * meta_marketing: token opcional da empresa para a Marketing API
--     (anúncios/criativos). Cifrado com APP_KEY, como o token da página.
--   * meta_criativo_falhas: registro das falhas de sincronização.
-- ==================================================================

CREATE TABLE IF NOT EXISTS meta_anuncios (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id          INT UNSIGNED NOT NULL,
    ad_id               VARCHAR(40) NOT NULL,
    nome                VARCHAR(255) NULL,
    adset_id            VARCHAR(40) NULL,
    adset_nome          VARCHAR(255) NULL,
    campanha_id         VARCHAR(40) NULL,
    campanha_nome       VARCHAR(255) NULL,
    conta_anuncios_id   VARCHAR(40) NULL,
    criativo_id         VARCHAR(40) NULL,
    pagina_id           VARCHAR(40) NULL,
    titulo              VARCHAR(255) NULL,
    texto               TEXT NULL,
    cta                 VARCHAR(60) NULL,
    midia_tipo          ENUM('imagem','video','carrossel','desconhecida') NULL,
    imagem_arquivo      VARCHAR(255) NULL,      -- cópia local (storage/criativos/...), nunca a URL temporária da Meta
    imagem_baixada_em   DATETIME NULL,
    video_id            VARCHAR(40) NULL,
    link_oficial        VARCHAR(1000) NULL,     -- só URL validada, vinda da própria Meta
    link_fonte          VARCHAR(40) NULL,       -- preview_shareable_link | post_facebook | instagram
    dinamico            TINYINT(1) NOT NULL DEFAULT 0,  -- criativo dinâmico/variações automáticas
    status_meta         VARCHAR(40) NULL,       -- effective_status informado pela Meta
    removido_na_meta    TINYINT(1) NOT NULL DEFAULT 0,
    sync_status         ENUM('pendente','ok','parcial','sem_permissao','removido','erro') NOT NULL DEFAULT 'pendente',
    sync_erro           VARCHAR(500) NULL,
    sync_tentativas     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    sincronizado_em     DATETIME NULL,
    proxima_sync_em     DATETIME NULL,
    criado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meta_anuncio (empresa_id, ad_id),
    KEY idx_meta_anuncio_sync (sync_status, proxima_sync_em),
    KEY idx_meta_anuncio_campanha (empresa_id, campanha_id),
    CONSTRAINT fk_meta_anuncio_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meta_marketing (
    empresa_id      INT UNSIGNED NOT NULL PRIMARY KEY,
    token_cifrado   TEXT NULL,
    validado_em     DATETIME NULL,
    ultimo_erro     VARCHAR(500) NULL,
    atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_meta_marketing_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meta_criativo_falhas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    meta_anuncio_id INT UNSIGNED NULL,
    etapa           VARCHAR(40) NOT NULL,       -- anuncio | imagem | video | link
    erro            VARCHAR(500) NOT NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mcf_empresa (empresa_id, criado_em),
    CONSTRAINT fk_mcf_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_mcf_anuncio FOREIGN KEY (meta_anuncio_id) REFERENCES meta_anuncios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colunas de origem no lead (uma por vez, cada uma só se ainda não existir).
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'meta_anuncio_id');
SET @sql := IF(@x = 0, 'ALTER TABLE leads
    ADD COLUMN meta_anuncio_id INT UNSIGNED NULL,
    ADD COLUMN meta_ad_id VARCHAR(40) NULL,
    ADD COLUMN meta_adset_id VARCHAR(40) NULL,
    ADD COLUMN meta_campanha_id VARCHAR(40) NULL,
    ADD COLUMN meta_form_id VARCHAR(40) NULL,
    ADD COLUMN meta_page_id VARCHAR(40) NULL,
    ADD COLUMN meta_plataforma VARCHAR(20) NULL,
    ADD COLUMN meta_cadastro_em DATETIME NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_meta_anuncio');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD KEY idx_leads_meta_anuncio (meta_anuncio_id), ADD KEY idx_leads_meta_campanha (empresa_id, meta_campanha_id)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND CONSTRAINT_NAME = 'fk_leads_meta_anuncio');
SET @sql := IF(@x = 0, 'ALTER TABLE leads ADD CONSTRAINT fk_leads_meta_anuncio FOREIGN KEY (meta_anuncio_id) REFERENCES meta_anuncios(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Leads antigos do Meta: recupera o ad_id que já veio no aviso do webhook (meta_eventos.payload).
UPDATE leads l JOIN meta_eventos ev ON ev.lead_id = l.id AND ev.status = 'processado'
SET l.meta_ad_id = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ev.payload, '$.ad_id')), 'null'),
    l.meta_form_id = COALESCE(l.meta_form_id, ev.form_id),
    l.meta_page_id = COALESCE(l.meta_page_id, ev.page_id)
WHERE l.meta_ad_id IS NULL AND JSON_VALID(ev.payload);
