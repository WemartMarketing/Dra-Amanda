-- ============================================================
-- WeMart Sales — dados fictícios da Etapa 5 (oferta ativa e recuperação)
-- Rodar DEPOIS de migration_006_integracoes_oferta.sql. Idempotente.
-- Compatível com MySQL 5.7+/8.0 e MariaDB 10.3+.
--
-- NÃO cria páginas, formulários, eventos nem tokens do Meta: a
-- integração só aparece como configurada quando o Master cadastra as
-- credenciais reais (ver docs/INTEGRACAO_META.md).
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- Regra de recuperação da HM Vendas (padrão do prompt: 4 dias, aviso 1 dia antes)
INSERT INTO recuperacao_regras (empresa_id, ativa, dias_sem_feedback, avisar_dias_antes, categorias, redistribuir_automaticamente)
VALUES (1, 1, 4, 1, 'novo_lead,primeiro_contato,em_atendimento', 1)
ON DUPLICATE KEY UPDATE ativa = VALUES(ativa), dias_sem_feedback = VALUES(dias_sem_feedback),
    avisar_dias_antes = VALUES(avisar_dias_antes), categorias = VALUES(categorias),
    redistribuir_automaticamente = VALUES(redistribuir_automaticamente);

-- Lead do Carlos sem feedback há 3 dias → aviso "vai para recuperação amanhã"
INSERT INTO leads (id, empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, origem, etapa, etapa_id, interesse,
                   ultima_atividade_em, ultimo_feedback_em, criado_em)
SELECT 29, 1, 1, 1, 1, 'Otávio Bezerra', '(11) 98321-5507', 'manual', 'em_atendimento',
       (SELECT id FROM funil_etapas WHERE empresa_id = 1 AND categoria = 'em_atendimento' ORDER BY ordem LIMIT 1),
       'Studio no Smart Marquês', NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 8 DAY
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM leads WHERE id = 29);

-- Lead da Fernanda Costa sem feedback há 5 dias → a próxima rotina o recupera
INSERT INTO leads (id, empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, origem, etapa, etapa_id, interesse,
                   ultima_atividade_em, ultimo_feedback_em, criado_em)
SELECT 30, 1, 2, 1, 2, 'Sílvia Moraes', '(11) 97214-8830', 'manual', 'em_atendimento',
       (SELECT id FROM funil_etapas WHERE empresa_id = 1 AND categoria = 'em_atendimento' ORDER BY ordem LIMIT 1),
       '3 dormitórios', NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 12 DAY
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM leads WHERE id = 30);

-- Lista de oferta ativa importada (6 linhas: 4 leads, 1 duplicada, 1 inválida)
INSERT IGNORE INTO oferta_listas (id, empresa_id, nome, empreendimento_id, arquivo_nome, linhas_lidas, importados, duplicados, invalidos, status, criado_por, criado_em)
VALUES (1, 1, 'Feirão Zona Sul — demonstração', 2, 'feirao-zona-sul.csv', 6, 4, 1, 1, 'em_distribuicao', 5, NOW() - INTERVAL 2 DAY);

INSERT INTO leads (id, empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, origem, oferta_lista_id, etapa, etapa_id,
                   ultima_atividade_em, ultimo_feedback_em, ultimo_contato_em, criado_em)
SELECT 31, 1, 2, 2, 9, 'Gustavo Teles', '(11) 99610-2241', 'importacao', 1, 'primeiro_contato',
       (SELECT id FROM funil_etapas WHERE empresa_id = 1 AND categoria = 'primeiro_contato' ORDER BY ordem LIMIT 1),
       NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 2 DAY
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM leads WHERE id = 31);
INSERT INTO leads (id, empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, origem, oferta_lista_id, etapa, etapa_id,
                   ultima_atividade_em, ultimo_feedback_em, criado_em)
SELECT 32, 1, 2, 2, 10, 'Renata Quintela', '(11) 98840-3392', 'importacao', 1, 'novo_lead',
       (SELECT id FROM funil_etapas WHERE empresa_id = 1 AND categoria = 'novo_lead' ORDER BY ordem LIMIT 1),
       NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM leads WHERE id = 32);
INSERT INTO leads (id, empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, origem, oferta_lista_id, etapa, etapa_id,
                   ultima_atividade_em, criado_em)
SELECT 33, 1, 2, NULL, NULL, 'Igor Fontana', '(11) 97733-4415', 'importacao', 1, 'novo_lead',
       (SELECT id FROM funil_etapas WHERE empresa_id = 1 AND categoria = 'novo_lead' ORDER BY ordem LIMIT 1),
       NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM leads WHERE id = 33);
INSERT INTO leads (id, empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, origem, oferta_lista_id, etapa, etapa_id,
                   ultima_atividade_em, criado_em)
SELECT 34, 1, 2, NULL, NULL, 'Luana Pires', '(11) 96512-7788', 'importacao', 1, 'novo_lead',
       (SELECT id FROM funil_etapas WHERE empresa_id = 1 AND categoria = 'novo_lead' ORDER BY ordem LIMIT 1),
       NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM leads WHERE id = 34);

-- Lead perdido por inatividade por Rafael (Zona Oeste) e redistribuído para Juliana (Zona Sul)
INSERT INTO leads (id, empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, origem, etapa, etapa_id, interesse,
                   ultima_atividade_em, ultimo_feedback_em, criado_em)
SELECT 35, 1, 2, 2, 9, 'Henrique Paiva', '(11) 98007-6621', 'manual', 'em_atendimento',
       (SELECT id FROM funil_etapas WHERE empresa_id = 1 AND categoria = 'em_atendimento' ORDER BY ordem LIMIT 1),
       '2 dormitórios, Vila das Belezas', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 15 DAY
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM leads WHERE id = 35);

INSERT IGNORE INTO lead_transferencias (id, empresa_id, lead_id, empreendimento_id, de_usuario_id, de_equipe_id, de_gerente_id, de_superintendencia_id,
        para_usuario_id, para_equipe_id, para_gerente_id, para_superintendencia_id, motivo, observacao, executado_por, criado_em)
VALUES (2, 1, 35, 2, 3, 1, 4, 1, 9, 2, 8, 2, 'inatividade', 'Sem feedback válido há 4 dias — enviado para recuperação', NULL, NOW() - INTERVAL 6 DAY);

INSERT IGNORE INTO roleta_fila (id, empresa_id, lead_id, roleta_id, origem, evitar_usuario_id, motivo, tentativas, entrou_em, resolvido_em)
VALUES (3, 1, 35, 2, 'recuperacao', 3, 'Recuperado por inatividade', 0, NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 6 DAY);

INSERT IGNORE INTO roleta_distribuicoes (empresa_id, roleta_id, lead_id, usuario_id, origem, executado_por, distribuido_em)
SELECT 1, 2, 35, 9, 'recuperacao', NULL, NOW() - INTERVAL 6 DAY FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM roleta_distribuicoes WHERE lead_id = 35);

INSERT IGNORE INTO lead_eventos (id, lead_id, usuario_id, tipo, descricao, criado_em) VALUES
    (42, 29, 1, 'contato', 'Cliente pediu para retornar depois de falar com a esposa', NOW() - INTERVAL 3 DAY),
    (43, 30, 2, 'contato', 'Enviada tabela de preços por WhatsApp', NOW() - INTERVAL 5 DAY),
    (44, 31, 5, 'importacao', 'Importado na lista de oferta ativa "Feirão Zona Sul — demonstração" (feirao-zona-sul.csv) por Fernanda Gestora', NOW() - INTERVAL 2 DAY),
    (45, 32, 5, 'importacao', 'Importado na lista de oferta ativa "Feirão Zona Sul — demonstração" (feirao-zona-sul.csv) por Fernanda Gestora', NOW() - INTERVAL 2 DAY),
    (46, 33, 5, 'importacao', 'Importado na lista de oferta ativa "Feirão Zona Sul — demonstração" (feirao-zona-sul.csv) por Fernanda Gestora', NOW() - INTERVAL 2 DAY),
    (47, 34, 5, 'importacao', 'Importado na lista de oferta ativa "Feirão Zona Sul — demonstração" (feirao-zona-sul.csv) por Fernanda Gestora', NOW() - INTERVAL 2 DAY),
    (48, 31, 5, 'atribuicao', 'Oferta ativa "Feirão Zona Sul — demonstração": atribuído a Juliana Prado por Fernanda Gestora (divisão igual)', NOW() - INTERVAL 2 DAY),
    (49, 32, 5, 'atribuicao', 'Oferta ativa "Feirão Zona Sul — demonstração": atribuído a Marcelo Diniz por Fernanda Gestora (divisão igual)', NOW() - INTERVAL 2 DAY),
    (50, 31, 9, 'contato', 'Atendeu, tem interesse em visitar no sábado', NOW() - INTERVAL 1 DAY),
    (51, 35, NULL, 'recuperacao', 'Sem feedback válido há 4 dias — enviado para recuperação', NOW() - INTERVAL 6 DAY),
    (52, 35, NULL, 'atribuicao', 'Recebido pela roleta Casa HM — Zona Sul — atribuído a Juliana Prado', NOW() - INTERVAL 6 DAY),
    (53, 35, 9, 'contato', 'Retomado o contato, cliente segue interessado', NOW() - INTERVAL 1 DAY);

INSERT INTO auditoria (empresa_id, usuario_id, acao, entidade, entidade_id, detalhes_json, criado_em)
SELECT 1, 5, 'oferta.lista_importada', 'oferta_lista', 1, '{"arquivo":"feirao-zona-sul.csv","lidas":6,"importados":4,"duplicados":1,"invalidos":1}', NOW() - INTERVAL 2 DAY FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM auditoria WHERE entidade = 'oferta_lista' AND entidade_id = 1 AND acao = 'oferta.lista_importada');
INSERT INTO auditoria (empresa_id, usuario_id, acao, entidade, entidade_id, detalhes_json, criado_em)
SELECT 1, 5, 'oferta.distribuida', 'oferta_lista', 1, '{"modo":"dividir","distribuidos":2,"em_fila":0}', NOW() - INTERVAL 2 DAY FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM auditoria WHERE entidade = 'oferta_lista' AND entidade_id = 1 AND acao = 'oferta.distribuida');
