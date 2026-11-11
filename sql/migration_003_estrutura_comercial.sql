-- WeMart Sales — Migração 003: estrutura comercial (Etapa 2 do prompt mestre).
--
-- Completa o cadastro de empreendimentos (seção 13), cria plantões de venda
-- com endereço/coordenadas/raio (base da geolocalização da Etapa 4) e os
-- vínculos plantão↔empreendimento, equipe↔empreendimento e roleta→plantão.
-- Adiciona duas permissões novas ao catálogo e concede ambas às funções
-- "Master da empresa" já existentes.

SET NAMES utf8mb4;

-- ------------------------------------------------------------------
-- Empreendimentos: campos completos
-- ------------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empreendimentos' AND COLUMN_NAME = 'construtora');
SET @sql := IF(@x = 0, 'ALTER TABLE empreendimentos ADD COLUMN construtora VARCHAR(190) NULL AFTER nome', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empreendimentos' AND COLUMN_NAME = 'endereco');
SET @sql := IF(@x = 0, 'ALTER TABLE empreendimentos ADD COLUMN endereco VARCHAR(255) NULL AFTER construtora', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empreendimentos' AND COLUMN_NAME = 'bairro');
SET @sql := IF(@x = 0, 'ALTER TABLE empreendimentos ADD COLUMN bairro VARCHAR(120) NULL AFTER endereco', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empreendimentos' AND COLUMN_NAME = 'cidade');
SET @sql := IF(@x = 0, 'ALTER TABLE empreendimentos ADD COLUMN cidade VARCHAR(120) NULL AFTER bairro', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empreendimentos' AND COLUMN_NAME = 'estado');
SET @sql := IF(@x = 0, 'ALTER TABLE empreendimentos ADD COLUMN estado CHAR(2) NULL AFTER cidade', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empreendimentos' AND COLUMN_NAME = 'status_comercial');
SET @sql := IF(@x = 0, 'ALTER TABLE empreendimentos ADD COLUMN status_comercial ENUM(''lancamento'',''em_obras'',''pronto'',''esgotado'',''suspenso'') NOT NULL DEFAULT ''lancamento'' AFTER estado', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empreendimentos' AND COLUMN_NAME = 'descricao');
SET @sql := IF(@x = 0, 'ALTER TABLE empreendimentos ADD COLUMN descricao TEXT NULL AFTER status_comercial', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Caminho relativo dentro de uploads/empreendimentos/ (nome aleatório gerado no upload)
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empreendimentos' AND COLUMN_NAME = 'imagem');
SET @sql := IF(@x = 0, 'ALTER TABLE empreendimentos ADD COLUMN imagem VARCHAR(255) NULL AFTER descricao', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ------------------------------------------------------------------
-- Plantões de venda (seção 9: cada plantão tem endereço, coordenadas e
-- raio permitido, inicialmente 300 m)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plantoes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    nome            VARCHAR(190) NOT NULL,
    endereco        VARCHAR(255) NULL,
    bairro          VARCHAR(120) NULL,
    cidade          VARCHAR(120) NULL,
    estado          CHAR(2) NULL,
    latitude        DECIMAL(10,7) NULL,
    longitude       DECIMAL(10,7) NULL,
    raio_metros     INT UNSIGNED NOT NULL DEFAULT 300,
    ativo           TINYINT(1) NOT NULL DEFAULT 1,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_plantoes_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    INDEX idx_plantoes_empresa (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plantao_empreendimentos (
    plantao_id          INT UNSIGNED NOT NULL,
    empreendimento_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (plantao_id, empreendimento_id),
    CONSTRAINT fk_pe_plantao FOREIGN KEY (plantao_id) REFERENCES plantoes(id) ON DELETE CASCADE,
    CONSTRAINT fk_pe_empreendimento FOREIGN KEY (empreendimento_id) REFERENCES empreendimentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equipes vinculadas a empreendimentos (seção 13: "Equipes vinculadas")
CREATE TABLE IF NOT EXISTS equipe_empreendimentos (
    equipe_id           INT UNSIGNED NOT NULL,
    empreendimento_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (equipe_id, empreendimento_id),
    CONSTRAINT fk_eqe_equipe FOREIGN KEY (equipe_id) REFERENCES equipes(id) ON DELETE CASCADE,
    CONSTRAINT fk_eqe_empreendimento FOREIGN KEY (empreendimento_id) REFERENCES empreendimentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roleta pertence a um plantão (a localização/raio passam a vir do plantão;
-- as colunas antigas de lat/long/raio da roleta ficam como legado e não
-- devem mais ser editadas pela interface).
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roletas' AND COLUMN_NAME = 'plantao_id');
SET @sql := IF(@x = 0, 'ALTER TABLE roletas ADD COLUMN plantao_id INT UNSIGNED NULL AFTER empresa_id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                   WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_roletas_plantao');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE roletas ADD CONSTRAINT fk_roletas_plantao FOREIGN KEY (plantao_id) REFERENCES plantoes(id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- Novas permissões
-- ------------------------------------------------------------------
INSERT INTO permissoes (chave, rotulo, categoria) VALUES
    ('gerenciar_empreendimentos', 'Gerenciar empreendimentos', 'operacao'),
    ('gerenciar_plantoes',        'Gerenciar plantões',        'operacao')
ON DUPLICATE KEY UPDATE rotulo = VALUES(rotulo), categoria = VALUES(categoria);

-- Concede às funções "Master da empresa" já existentes (as novas empresas
-- recebem via includes/funcoes.php → funcoes_definicao_padrao()).
INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
    SELECT f.id, p.chave
    FROM funcoes f
    JOIN permissoes p ON p.chave IN ('gerenciar_empreendimentos', 'gerenciar_plantoes')
    WHERE f.nome = 'Master da empresa' AND f.padrao_sistema = 1;
