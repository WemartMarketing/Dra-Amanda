-- WeMart Sales — Migração 008: WhatsApp Business (Cloud API) e Jornadas
-- Inteligentes — FASE 1 (estrutura). Ver docs/WHATSAPP_JORNADAS.md.
--
-- Cria a base para:
--   * conta(s) de WhatsApp Business de cada empresa (token cifrado com
--     APP_KEY, "modo de teste" ligado por padrão: nada sai para a Meta);
--   * contatos, histórico de consentimento (LGPD) e descadastramento;
--   * conversas e mensagens (janela de 24 h, status entregue/lida/falha,
--     custo estimado, deduplicação por wamid e por chave de idempotência);
--   * caixa de entrada dos webhooks (mesmo modelo de meta_eventos);
--   * fila persistente de tarefas (automacao_fila) para o cron;
--   * templates, jornadas, passos, execuções (uma por lead e jornada — a
--     jornada não recomeça quando o lead troca de corretor) e logs;
--   * central de pendências do corretor ("o cliente respondeu?");
--   * campanhas/disparador (estrutura; regras na Fase 7);
--   * horários por dia, pausas, feriados e exceções das roletas;
--   * book (PDF) e template de boas-vindas por empreendimento;
--   * permissões novas.
--
-- Compatível com MySQL 5.7+/8 e MariaDB 10.3+, idempotente, sem
-- procedures. JSON é guardado em TEXT (MariaDB/MySQL antigos).

SET NAMES utf8mb4;

-- ------------------------------------------------------------------
-- Conta do WhatsApp Business por empresa
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS whatsapp_contas (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id            INT UNSIGNED NOT NULL,
    nome                  VARCHAR(120) NOT NULL DEFAULT 'WhatsApp principal',
    waba_id               VARCHAR(40) NULL,   -- WhatsApp Business Account ID
    phone_number_id       VARCHAR(40) NULL,   -- identifica a empresa nos webhooks
    numero_exibicao       VARCHAR(30) NULL,
    nome_verificado       VARCHAR(190) NULL,
    access_token_cifrado  TEXT NULL,          -- cripto_cifrar() com APP_KEY; nunca em texto puro
    token_validado_em     DATETIME NULL,
    status                ENUM('rascunho','ativa','pausada','erro') NOT NULL DEFAULT 'rascunho',
    modo_teste            TINYINT(1) NOT NULL DEFAULT 1, -- 1 = envios são simulados e NADA sai para a Meta
    qualidade             ENUM('desconhecida','verde','amarela','vermelha') NOT NULL DEFAULT 'desconhecida',
    limite_mensagens      VARCHAR(40) NULL,   -- faixa de limite informada pela Meta
    origem_conexao        ENUM('manual','embedded_signup') NOT NULL DEFAULT 'manual',
    ultimo_erro           VARCHAR(500) NULL,
    criado_em             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em         DATETIME NULL,
    UNIQUE KEY uq_wa_conta_phone (phone_number_id),
    KEY idx_wa_conta_empresa (empresa_id),
    CONSTRAINT fk_wa_conta_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Regras de envio por empresa (frequência, horário, descadastro, aviso fora do horário).
CREATE TABLE IF NOT EXISTS whatsapp_config (
    empresa_id                  INT UNSIGNED PRIMARY KEY,
    max_automaticas_24h         TINYINT UNSIGNED NOT NULL DEFAULT 2,  -- mensagens automáticas por contato em 24 h
    max_marketing_7d            TINYINT UNSIGNED NOT NULL DEFAULT 2,  -- templates de marketing por contato em 7 dias
    envio_inicio                TIME NOT NULL DEFAULT '08:00:00',     -- automações (não respostas) só neste horário
    envio_fim                   TIME NOT NULL DEFAULT '21:00:00',
    envio_dias                  VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
    palavras_descadastro        VARCHAR(255) NOT NULL DEFAULT 'PARAR,SAIR,DESCADASTRAR,STOP,CANCELAR,REMOVER',
    resposta_sem_corretor       TEXT NULL,  -- confirmação automática (sem preços/condições) quando ninguém pode atender
    atualizado_em               DATETIME NULL,
    CONSTRAINT fk_wa_config_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarifas para ESTIMAR custos (a cobrança real é da Meta). empresa_id NULL = padrão
-- da plataforma, definido pelo Superadmin. Nenhum valor é pré-cadastrado.
CREATE TABLE IF NOT EXISTS whatsapp_tarifas (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id     INT UNSIGNED NULL,
    categoria      ENUM('marketing','utility','authentication','service') NOT NULL,
    moeda          CHAR(3) NOT NULL DEFAULT 'BRL',
    valor          DECIMAL(10,4) NOT NULL,
    vigente_desde  DATE NOT NULL,
    criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wa_tarifa (empresa_id, categoria, vigente_desde),
    CONSTRAINT fk_wa_tarifa_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Templates (espelho dos aprovados na Meta + mapeamento das variáveis)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS whatsapp_templates (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id        INT UNSIGNED NOT NULL,
    conta_id          INT UNSIGNED NOT NULL,
    meta_template_id  VARCHAR(40) NULL,
    nome              VARCHAR(512) NOT NULL,           -- nome técnico na Meta
    idioma            VARCHAR(10) NOT NULL DEFAULT 'pt_BR',
    categoria         ENUM('marketing','utility','authentication') NOT NULL,
    status            ENUM('rascunho','pendente','aprovado','rejeitado','pausado','desativado') NOT NULL DEFAULT 'rascunho',
    finalidade        ENUM('boas_vindas','follow_up','book','reengajamento','remarketing','outro') NOT NULL DEFAULT 'outro',
    cabecalho_tipo    ENUM('nenhum','texto','documento','imagem','video') NOT NULL DEFAULT 'nenhum',
    corpo             TEXT NOT NULL,                   -- texto com {{1}} ou {{nome}}
    rodape            VARCHAR(60) NULL,
    botoes            TEXT NULL,                       -- JSON: [{"tipo":"resposta","texto":"Quero receber o book","acao":"pedir_book"}]
    variaveis         TEXT NULL,                       -- JSON: {"1":"lead.primeiro_nome","2":"empresa.nome"}
    motivo_rejeicao   VARCHAR(500) NULL,
    sincronizado_em   DATETIME NULL,
    criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em     DATETIME NULL,
    UNIQUE KEY uq_wa_template (conta_id, nome(190), idioma),
    KEY idx_wa_template_empresa (empresa_id, status),
    CONSTRAINT fk_wa_template_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_wa_template_conta FOREIGN KEY (conta_id) REFERENCES whatsapp_contas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Contatos, consentimento e descadastro
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS whatsapp_contatos (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id         INT UNSIGNED NOT NULL,
    telefone           VARCHAR(20) NOT NULL,   -- só dígitos, com DDI (ex.: 5511988887777)
    wa_id              VARCHAR(30) NULL,       -- id que a Meta devolve no webhook
    nome_perfil        VARCHAR(190) NULL,
    lead_id            INT UNSIGNED NULL,      -- lead principal ligado a este número
    opt_out_em         DATETIME NULL,          -- pediu para não receber mais mensagens
    opt_out_motivo     VARCHAR(190) NULL,
    ultima_entrada_em  DATETIME NULL,          -- última mensagem DO cliente (abre a janela de 24 h)
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em      DATETIME NULL,
    UNIQUE KEY uq_wa_contato (empresa_id, telefone),
    KEY idx_wa_contato_lead (lead_id),
    CONSTRAINT fk_wa_contato_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_wa_contato_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico (só acrescenta linhas). O estado atual de cada finalidade é a linha mais recente.
CREATE TABLE IF NOT EXISTS whatsapp_consentimentos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    contato_id      INT UNSIGNED NOT NULL,
    lead_id         INT UNSIGNED NULL,
    finalidade      ENUM('atendimento','marketing') NOT NULL,
    acao            ENUM('concedido','revogado') NOT NULL,
    origem          ENUM('formulario_meta','mensagem_cliente','manual','importacao','descadastro','sistema') NOT NULL,
    evidencia       VARCHAR(500) NULL,     -- texto do aceite, id do formulário, nome do arquivo importado…
    registrado_por  INT UNSIGNED NULL,     -- usuário (NULL = sistema)
    ip              VARCHAR(45) NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wa_consent (contato_id, finalidade, id),
    KEY idx_wa_consent_empresa (empresa_id, criado_em),
    CONSTRAINT fk_wa_consent_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_wa_consent_contato FOREIGN KEY (contato_id) REFERENCES whatsapp_contatos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Conversas e mensagens
-- ------------------------------------------------------------------
-- O acesso à conversa segue SEMPRE o lead (leads.responsavel_id + escopo da
-- função): não há "dono" guardado aqui, então a transferência do lead
-- muda o acesso na hora, sem copiar nada.
CREATE TABLE IF NOT EXISTS whatsapp_conversas (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id          INT UNSIGNED NOT NULL,
    conta_id            INT UNSIGNED NOT NULL,
    contato_id          INT UNSIGNED NOT NULL,
    lead_id             INT UNSIGNED NULL,
    status              ENUM('aberta','encerrada') NOT NULL DEFAULT 'aberta',
    nao_lidas           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ultima_previa       VARCHAR(190) NULL,
    ultima_mensagem_em  DATETIME NULL,
    ultima_entrada_em   DATETIME NULL,
    ultima_saida_em     DATETIME NULL,
    humano_assumiu_em   DATETIME NULL,    -- um usuário respondeu: automações de "sem resposta" ficam de fora
    criado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wa_conversa (conta_id, contato_id),
    KEY idx_wa_conversa_lista (empresa_id, ultima_mensagem_em),
    KEY idx_wa_conversa_lead (lead_id),
    CONSTRAINT fk_wa_conversa_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_wa_conversa_conta FOREIGN KEY (conta_id) REFERENCES whatsapp_contas(id) ON DELETE CASCADE,
    CONSTRAINT fk_wa_conversa_contato FOREIGN KEY (contato_id) REFERENCES whatsapp_contatos(id) ON DELETE CASCADE,
    CONSTRAINT fk_wa_conversa_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_mensagens (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id           INT UNSIGNED NOT NULL,
    conversa_id          INT UNSIGNED NOT NULL,
    direcao              ENUM('entrada','saida') NOT NULL,
    tipo                 ENUM('texto','template','documento','imagem','audio','video','botao','interativo','reacao','localizacao','contato','sistema','outro') NOT NULL,
    conteudo             TEXT NULL,
    template_id          INT UNSIGNED NULL,
    categoria            ENUM('marketing','utility','authentication','service') NULL,
    botao_payload        VARCHAR(190) NULL,     -- resposta rápida tocada pelo cliente
    arquivo_ref          VARCHAR(255) NULL,     -- arquivo local (book) enviado
    midia_id_meta        VARCHAR(64) NULL,
    wamid                VARCHAR(128) NULL,     -- id da mensagem na Meta: deduplica webhooks
    idempotencia_chave   VARCHAR(120) NULL,     -- impede o mesmo envio duas vezes (jornada/campanha)
    status               ENUM('na_fila','enviando','enviada','entregue','lida','falhou','recebida','simulada','cancelada') NOT NULL,
    erro_codigo          VARCHAR(20) NULL,
    erro_texto           VARCHAR(500) NULL,
    enviada_por          INT UNSIGNED NULL,     -- usuário; NULL = automação
    jornada_execucao_id  INT UNSIGNED NULL,
    campanha_id          INT UNSIGNED NULL,
    custo_estimado       DECIMAL(10,4) NULL,
    cobravel             TINYINT(1) NULL,       -- informado pela Meta no status
    criado_em            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviada_em           DATETIME NULL,
    entregue_em          DATETIME NULL,
    lida_em              DATETIME NULL,
    UNIQUE KEY uq_wa_msg_wamid (wamid),
    UNIQUE KEY uq_wa_msg_idem (idempotencia_chave),
    KEY idx_wa_msg_conversa (conversa_id, id),
    KEY idx_wa_msg_empresa (empresa_id, criado_em),
    KEY idx_wa_msg_execucao (jornada_execucao_id),
    KEY idx_wa_msg_campanha (campanha_id),
    CONSTRAINT fk_wa_msg_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_wa_msg_conversa FOREIGN KEY (conversa_id) REFERENCES whatsapp_conversas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Caixa de entrada dos webhooks: grava, responde 200 e processa depois.
CREATE TABLE IF NOT EXISTS whatsapp_webhook_eventos (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id            INT UNSIGNED NULL,
    conta_id              INT UNSIGNED NULL,
    phone_number_id       VARCHAR(40) NULL,
    tipo                  ENUM('mensagem','status','template','conta','outro') NOT NULL,
    chave_unica           VARCHAR(190) NULL,   -- wamid (+ status): o mesmo aviso repetido é descartado
    payload               MEDIUMTEXT NOT NULL,
    status                ENUM('pendente','processado','duplicado','erro','ignorado') NOT NULL DEFAULT 'pendente',
    tentativas            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    proxima_tentativa_em  DATETIME NULL,
    erro                  VARCHAR(500) NULL,
    recebido_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processado_em         DATETIME NULL,
    UNIQUE KEY uq_wa_evento (chave_unica),
    KEY idx_wa_evento_status (status, proxima_tentativa_em),
    KEY idx_wa_evento_empresa (empresa_id, recebido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Fila persistente de tarefas (processada pelo cron, sem processo contínuo)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS automacao_fila (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id       INT UNSIGNED NOT NULL,
    tipo             VARCHAR(40) NOT NULL,          -- ex.: wa_enviar, jornada_passo
    referencia_tipo  VARCHAR(30) NULL,
    referencia_id    INT UNSIGNED NULL,
    chave_unica      VARCHAR(190) NULL,             -- a mesma tarefa nunca entra duas vezes
    payload          TEXT NULL,                     -- JSON
    status           ENUM('pendente','processando','concluida','falhou','cancelada') NOT NULL DEFAULT 'pendente',
    executar_em      DATETIME NOT NULL,
    tentativas       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_tentativas   SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    travada_em       DATETIME NULL,
    travada_por      CHAR(16) NULL,
    resultado        VARCHAR(500) NULL,
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluida_em     DATETIME NULL,
    UNIQUE KEY uq_fila_chave (chave_unica),
    KEY idx_fila_pronta (status, executar_em),
    KEY idx_fila_ref (referencia_tipo, referencia_id, status),
    KEY idx_fila_empresa (empresa_id, criado_em),
    CONSTRAINT fk_afila_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Jornadas Inteligentes
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jornadas (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id         INT UNSIGNED NOT NULL,
    nome               VARCHAR(120) NOT NULL,
    descricao          VARCHAR(500) NULL,
    gatilho            ENUM('novo_lead','lead_transferido','mudanca_etapa','mensagem_recebida','manual') NOT NULL,
    gatilho_config     TEXT NULL,          -- JSON (ex.: {"etapa_id": 3})
    empreendimento_id  INT UNSIGNED NULL,  -- NULL = todos
    status             ENUM('rascunho','ativa','pausada','desativada') NOT NULL DEFAULT 'rascunho',
    max_mensagens      TINYINT UNSIGNED NOT NULL DEFAULT 3,  -- teto de mensagens automáticas por lead nesta jornada
    versao             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    criado_por         INT UNSIGNED NULL,
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em      DATETIME NULL,
    KEY idx_jornada_empresa (empresa_id, status, gatilho),
    CONSTRAINT fk_jornada_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_jornada_empreendimento FOREIGN KEY (empreendimento_id) REFERENCES empreendimentos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jornada_passos (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    jornada_id    INT UNSIGNED NOT NULL,
    chave         VARCHAR(40) NOT NULL,     -- id estável do bloco no editor
    ordem         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tipo          ENUM('enviar_template','enviar_documento','esperar','condicao','criar_tarefa','notificar_corretor',
                       'confirmar_atendimento','atualizar_etapa','encaminhar_fila','encerrar') NOT NULL,
    titulo        VARCHAR(120) NULL,
    config        TEXT NULL,                -- JSON com os parâmetros do bloco
    proximo_chave VARCHAR(40) NULL,         -- caminho padrão (ou "sim" na condição)
    senao_chave   VARCHAR(40) NULL,         -- caminho "não" da condição
    UNIQUE KEY uq_jornada_passo (jornada_id, chave),
    KEY idx_jornada_passo_ordem (jornada_id, ordem),
    CONSTRAINT fk_jpasso_jornada FOREIGN KEY (jornada_id) REFERENCES jornadas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma execução por (jornada, lead): transferir o lead NÃO recomeça a jornada.
CREATE TABLE IF NOT EXISTS jornada_execucoes (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id          INT UNSIGNED NOT NULL,
    jornada_id          INT UNSIGNED NOT NULL,
    lead_id             INT UNSIGNED NOT NULL,
    contato_id          INT UNSIGNED NULL,
    passo_chave         VARCHAR(40) NULL,
    status              ENUM('ativa','aguardando','pausada','concluida','cancelada','erro') NOT NULL DEFAULT 'ativa',
    motivo              VARCHAR(190) NULL,
    proxima_acao_em     DATETIME NULL,
    mensagens_enviadas  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    iniciada_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizada_em       DATETIME NULL,
    finalizada_em       DATETIME NULL,
    UNIQUE KEY uq_jexec (jornada_id, lead_id),
    KEY idx_jexec_pronta (status, proxima_acao_em),
    KEY idx_jexec_lead (lead_id),
    KEY idx_jexec_empresa (empresa_id, iniciada_em),
    CONSTRAINT fk_jexec_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_jexec_jornada FOREIGN KEY (jornada_id) REFERENCES jornadas(id) ON DELETE CASCADE,
    CONSTRAINT fk_jexec_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jornada_logs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id    INT UNSIGNED NOT NULL,
    execucao_id   INT UNSIGNED NOT NULL,
    passo_chave   VARCHAR(40) NULL,
    evento        VARCHAR(40) NOT NULL,    -- iniciou, enviou, bloqueado, esperando, condicao_sim, pausou, concluiu…
    detalhe       VARCHAR(500) NULL,
    criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_jlog_execucao (execucao_id, id),
    CONSTRAINT fk_jlog_execucao FOREIGN KEY (execucao_id) REFERENCES jornada_execucoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Central de pendências do corretor (seção 6: "o cliente respondeu?")
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS corretor_pendencias (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,
    lead_id         INT UNSIGNED NOT NULL,
    tipo            ENUM('confirmar_resposta','responder_whatsapp','tarefa_jornada') NOT NULL,
    titulo          VARCHAR(190) NOT NULL,
    status          ENUM('aberta','resolvida','adiada','cancelada') NOT NULL DEFAULT 'aberta',
    lembrar_em      DATETIME NULL,
    resposta        VARCHAR(40) NULL,      -- respondeu, nao_respondeu, tentativa_registrada
    observacao      VARCHAR(500) NULL,
    execucao_id     INT UNSIGNED NULL,
    chave_unica     VARCHAR(120) NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolvida_em    DATETIME NULL,
    resolvida_por   INT UNSIGNED NULL,
    UNIQUE KEY uq_pendencia (chave_unica),
    KEY idx_pendencia_usuario (usuario_id, status, lembrar_em),
    KEY idx_pendencia_lead (lead_id, status),
    CONSTRAINT fk_pend_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_pend_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Campanhas / Disparador (estrutura; telas e regras na Fase 7)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS whatsapp_campanhas (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id        INT UNSIGNED NOT NULL,
    conta_id          INT UNSIGNED NOT NULL,
    nome              VARCHAR(120) NOT NULL,
    template_id       INT UNSIGNED NULL,
    filtros           TEXT NULL,          -- JSON dos filtros usados para montar a lista
    status            ENUM('rascunho','agendada','enviando','pausada','concluida','cancelada') NOT NULL DEFAULT 'rascunho',
    total_elegiveis   INT UNSIGNED NOT NULL DEFAULT 0,
    custo_estimado    DECIMAL(12,4) NULL,
    agendada_para     DATETIME NULL,
    confirmada_por    INT UNSIGNED NULL,
    confirmada_em     DATETIME NULL,
    criado_por        INT UNSIGNED NULL,
    criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em     DATETIME NULL,
    KEY idx_wa_camp_empresa (empresa_id, status),
    CONSTRAINT fk_wa_camp_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_wa_camp_conta FOREIGN KEY (conta_id) REFERENCES whatsapp_contas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_campanha_destinatarios (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campanha_id   INT UNSIGNED NOT NULL,
    contato_id    INT UNSIGNED NOT NULL,
    lead_id       INT UNSIGNED NULL,
    status        ENUM('pendente','enviada','entregue','lida','respondida','falhou','ignorada','cancelada') NOT NULL DEFAULT 'pendente',
    motivo        VARCHAR(190) NULL,
    mensagem_id   INT UNSIGNED NULL,
    atualizado_em DATETIME NULL,
    UNIQUE KEY uq_wa_camp_dest (campanha_id, contato_id),
    KEY idx_wa_camp_dest_status (campanha_id, status),
    CONSTRAINT fk_wa_dest_campanha FOREIGN KEY (campanha_id) REFERENCES whatsapp_campanhas(id) ON DELETE CASCADE,
    CONSTRAINT fk_wa_dest_contato FOREIGN KEY (contato_id) REFERENCES whatsapp_contatos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Roletas: horário por dia da semana, pausas, feriados e exceções
-- ------------------------------------------------------------------
-- Se a roleta tiver linhas aqui, elas mandam (dia sem linha = fechado).
-- Sem linhas, vale o horário antigo (horario_inicio/fim + dias_semana).
CREATE TABLE IF NOT EXISTS roleta_horarios (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    roleta_id     INT UNSIGNED NOT NULL,
    dia_semana    TINYINT UNSIGNED NOT NULL,  -- 1 = segunda … 7 = domingo
    inicio        TIME NOT NULL,
    fim           TIME NOT NULL,              -- fim < início = atravessa a meia-noite
    pausa_inicio  TIME NULL,
    pausa_fim     TIME NULL,
    UNIQUE KEY uq_roleta_horario (roleta_id, dia_semana),
    CONSTRAINT fk_rhorario_roleta FOREIGN KEY (roleta_id) REFERENCES roletas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- roleta_id NULL = vale para todas as roletas da empresa (ex.: feriado).
CREATE TABLE IF NOT EXISTS roleta_excecoes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id  INT UNSIGNED NOT NULL,
    roleta_id   INT UNSIGNED NULL,
    data        DATE NOT NULL,
    tipo        ENUM('fechado','horario_especial') NOT NULL,
    inicio      TIME NULL,
    fim         TIME NULL,
    descricao   VARCHAR(190) NULL,
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rexc (empresa_id, data),
    CONSTRAINT fk_rexc_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_rexc_roleta FOREIGN KEY (roleta_id) REFERENCES roletas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Converte o horário antigo de cada roleta em linhas por dia (só se ainda não houver nenhuma).
INSERT INTO roleta_horarios (roleta_id, dia_semana, inicio, fim)
SELECT r.id, d.n, r.horario_inicio, r.horario_fim
FROM roletas r
JOIN (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7) d
  ON FIND_IN_SET(d.n, REPLACE(COALESCE(r.dias_semana, '1,2,3,4,5,6,7'), ' ', '')) > 0
WHERE r.horario_inicio IS NOT NULL AND r.horario_fim IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM roleta_horarios h WHERE h.roleta_id = r.id);

-- ------------------------------------------------------------------
-- Empreendimento: book digital e template de boas-vindas
-- ------------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empreendimentos' AND COLUMN_NAME = 'book_arquivo');
SET @sql := IF(@x = 0, 'ALTER TABLE empreendimentos ADD COLUMN book_arquivo VARCHAR(255) NULL, ADD COLUMN book_nome VARCHAR(190) NULL, ADD COLUMN book_tamanho INT UNSIGNED NULL, ADD COLUMN book_atualizado_em DATETIME NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empreendimentos' AND COLUMN_NAME = 'wa_template_boas_vindas_id');
SET @sql := IF(@x = 0, 'ALTER TABLE empreendimentos ADD COLUMN wa_template_boas_vindas_id INT UNSIGNED NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------------
-- Histórico do lead: novo tipo de evento "jornada" (só amplia a lista)
-- ------------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_eventos' AND COLUMN_NAME = 'tipo' AND COLUMN_TYPE NOT LIKE '%''jornada''%');
SET @sql := IF(@x = 1, 'ALTER TABLE lead_eventos MODIFY tipo ENUM(''atribuicao'',''mudanca_etapa'',''contato'',''redistribuicao'',''nota'',''sistema'',''ligacao'',''whatsapp'',''visita'',''agenda'',''arquivo'',''transferencia'',''recuperacao'',''importacao'',''integracao'',''jornada'') NOT NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------------
-- Permissões
-- ------------------------------------------------------------------
INSERT INTO permissoes (chave, rotulo, categoria) VALUES
    ('whatsapp_atender',      'Conversar com clientes pelo WhatsApp',          'leads'),
    ('whatsapp_configurar',   'Configurar WhatsApp, templates e books',         'administracao'),
    ('gerenciar_jornadas',    'Criar e ativar Jornadas Inteligentes',           'administracao'),
    ('gerenciar_campanhas_wa','Criar e disparar campanhas de WhatsApp',         'operacao')
ON DUPLICATE KEY UPDATE rotulo = VALUES(rotulo), categoria = VALUES(categoria);

INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
SELECT f.id, p.chave FROM funcoes f
JOIN permissoes p ON p.chave IN ('whatsapp_atender', 'whatsapp_configurar', 'gerenciar_jornadas', 'gerenciar_campanhas_wa')
WHERE f.nome = 'Master da empresa' AND f.padrao_sistema = 1;

INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave)
SELECT f.id, 'whatsapp_atender' FROM funcoes f
WHERE f.nome IN ('Superintendente', 'Gerente', 'Corretor') AND f.padrao_sistema = 1;
