-- WeMart Sales — Migração 002: funções/permissões configuráveis, Superadmin,
-- throttling de login e ativação da auditoria (Etapa 1 do prompt mestre).
--
-- Substitui usuarios.cargo (ENUM fixo de 8 valores) por usuarios.funcao_id,
-- apontando para uma tabela `funcoes` que o Master de cada empresa pode
-- criar/editar livremente, cada uma com um `escopo` (a mesma lógica de
-- visibilidade que já existia, agora dado configurável em vez de switch de
-- cargo fixo) e um conjunto de `permissoes` independentes (visualizar,
-- criar, editar, excluir, redistribuir, gerenciar roletas, exportar,
-- administrar usuários etc. — ver seção 5 do prompt mestre).
--
-- Mantém `usuarios.cargo` temporariamente (não remove a coluna nem apaga
-- dados) para rollback seguro; o código passa a ler `funcao_id`/`escopo`.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------
-- Planos (base para comercialização futura por assinatura — seção 4)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS planos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome            VARCHAR(80) NOT NULL,
    descricao       VARCHAR(255) NULL,
    ativo           TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO planos (nome, descricao)
SELECT 'Padrão', 'Plano inicial — todas as funcionalidades construídas até esta etapa.'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM planos WHERE nome = 'Padrão');

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'plano_id');
SET @sql := IF(@x = 0, 'ALTER TABLE empresas ADD COLUMN plano_id INT UNSIGNED NULL AFTER ativa', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND CONSTRAINT_NAME = 'fk_empresas_plano');
SET @sql := IF(@x = 0, 'ALTER TABLE empresas ADD CONSTRAINT fk_empresas_plano FOREIGN KEY (plano_id) REFERENCES planos(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


UPDATE empresas SET plano_id = (SELECT id FROM planos WHERE nome = 'Padrão' LIMIT 1) WHERE plano_id IS NULL;

-- ------------------------------------------------------------------
-- Catálogo fixo de permissões (seção 5) — não editável pelo Master,
-- só atribuível a uma função.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permissoes (
    chave       VARCHAR(40) PRIMARY KEY,
    rotulo      VARCHAR(120) NOT NULL,
    categoria   VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (chave, rotulo, categoria) VALUES
    ('visualizar_leads',       'Visualizar leads',                 'leads'),
    ('criar_leads',            'Criar leads',                      'leads'),
    ('editar_leads',           'Editar leads',                     'leads'),
    ('excluir_leads',          'Excluir leads',                    'leads'),
    ('redistribuir_leads',     'Redistribuir leads',               'leads'),
    ('gerenciar_roletas',      'Gerenciar roletas',                'operacao'),
    ('gerenciar_equipes',      'Gerenciar equipes e estrutura',    'operacao'),
    ('exportar_relatorios',    'Exportar relatórios',              'relatorios'),
    ('visualizar_indicadores', 'Visualizar indicadores/dashboard', 'relatorios'),
    ('administrar_usuarios',   'Administrar usuários e funções',   'administracao')
ON DUPLICATE KEY UPDATE rotulo = VALUES(rotulo), categoria = VALUES(categoria);

-- ------------------------------------------------------------------
-- Funções (cargos configuráveis) por empresa
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS funcoes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    nome            VARCHAR(80) NOT NULL,
    -- Mesmo vocabulário de escopo que a versão anterior usava em código
    -- fixo (ver includes/rbac.php) — agora é dado, não switch/case.
    escopo          ENUM('toda_empresa','todos_produtos','produtos_vinculados','superintendencia','equipe','propria_carteira') NOT NULL,
    padrao_sistema  TINYINT(1) NOT NULL DEFAULT 0, -- true = uma das 8 funções seedadas na criação da empresa; o Master pode editar permissões mas a exclusão pede confirmação extra na UI
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_funcoes_empresa_nome (empresa_id, nome),
    CONSTRAINT fk_funcoes_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS funcao_permissoes (
    funcao_id       INT UNSIGNED NOT NULL,
    permissao_chave VARCHAR(40) NOT NULL,
    PRIMARY KEY (funcao_id, permissao_chave),
    CONSTRAINT fk_fp_funcao FOREIGN KEY (funcao_id) REFERENCES funcoes(id) ON DELETE CASCADE,
    CONSTRAINT fk_fp_permissao FOREIGN KEY (permissao_chave) REFERENCES permissoes(chave) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Usuários: adiciona funcao_id (substitui cargo em código) e o flag
-- de Superadmin WeMart (cross-empresa, fora do sistema de funções).
-- ------------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'funcao_id');
SET @sql := IF(@x = 0, 'ALTER TABLE usuarios ADD COLUMN funcao_id INT UNSIGNED NULL AFTER cargo', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'eh_superadmin');
SET @sql := IF(@x = 0, 'ALTER TABLE usuarios ADD COLUMN eh_superadmin TINYINT(1) NOT NULL DEFAULT 0 AFTER funcao_id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND CONSTRAINT_NAME = 'fk_usuarios_funcao');
SET @sql := IF(@x = 0, 'ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_funcao FOREIGN KEY (funcao_id) REFERENCES funcoes(id) ON DELETE RESTRICT', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ------------------------------------------------------------------
-- Throttling de tentativas de login (seção 15)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_tentativas (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(190) NOT NULL,
    ip          VARCHAR(45) NULL,
    sucesso     TINYINT(1) NOT NULL,
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_tentativas_email (email, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
