-- ============================================================
-- WeMart Sales — dados fictícios da Etapa 4 (roleta)
-- Rodar DEPOIS de migration_005_roleta.sql. Idempotente: pode rodar de
-- novo sem duplicar nada. Compatível com MySQL 5.7+/8.0 e MariaDB 10.3+.
-- Não abre presença de ninguém: todo corretor começa FORA da roleta.
-- ============================================================

SET NAMES utf8mb4;
-- Datas relativas (NOW, CURDATE) no horário de Brasília, como o sistema.
SET time_zone = '-03:00';

-- Regras das 3 roletas de demonstração ---------------------------------
UPDATE roletas SET dias_semana = '1,2,3,4,5,6', horario_inicio = '09:00:00', horario_fim = '18:00:00',
       exigir_presenca = 1, precisao_maxima_metros = 150, validade_presenca_min = 20,
       intervalo_verificacao_min = 5, max_leads_dia_corretor = NULL
 WHERE id = 1 AND empresa_id = 1;
UPDATE roletas SET dias_semana = '1,2,3,4,5,6', horario_inicio = '09:00:00', horario_fim = '18:00:00',
       exigir_presenca = 1, precisao_maxima_metros = 150, validade_presenca_min = 20,
       intervalo_verificacao_min = 5, max_leads_dia_corretor = 8
 WHERE id = 2 AND empresa_id = 1;
UPDATE roletas SET dias_semana = '1,2,3,4,5', horario_inicio = '09:00:00', horario_fim = '17:00:00',
       exigir_presenca = 1, precisao_maxima_metros = 200, validade_presenca_min = 30,
       intervalo_verificacao_min = 10, max_leads_dia_corretor = NULL, ativa = 0
 WHERE id = 3 AND empresa_id = 1;

-- Prioridade: "Vila das Belezas" está nas roletas 1 e 2; a Zona Sul tenta primeiro.
UPDATE roleta_empreendimentos SET prioridade = 10 WHERE roleta_id = 2 AND empreendimento_id = 2;
UPDATE roleta_empreendimentos SET prioridade = 20 WHERE roleta_id = 1 AND empreendimento_id = 2;

-- Histórico de distribuição (leads que já existem no seed) ----------------
INSERT IGNORE INTO roleta_distribuicoes (empresa_id, roleta_id, lead_id, usuario_id, origem, executado_por, distribuido_em)
SELECT leads.empresa_id, 1, leads.id, leads.responsavel_id, 'manual', NULL, leads.criado_em
  FROM leads WHERE leads.id IN (1, 2, 4, 8, 13, 22, 23) AND leads.responsavel_id IN (1, 2, 3)
   AND NOT EXISTS (SELECT 1 FROM roleta_distribuicoes d WHERE d.lead_id = leads.id); -- sem índice único desde a 006: evita duplicar ao rodar de novo
INSERT IGNORE INTO roleta_distribuicoes (empresa_id, roleta_id, lead_id, usuario_id, origem, executado_por, distribuido_em)
SELECT leads.empresa_id, 2, leads.id, leads.responsavel_id, 'manual', NULL, leads.criado_em
  FROM leads WHERE leads.id IN (15, 17, 19, 20) AND leads.responsavel_id IN (9, 10)
   AND NOT EXISTS (SELECT 1 FROM roleta_distribuicoes d WHERE d.lead_id = leads.id);

-- Contadores do rodízio refletem o histórico acima (recalculados, não somados).
UPDATE roleta_corretores
   SET total_recebidos = (SELECT COUNT(*) FROM roleta_distribuicoes d WHERE d.roleta_id = roleta_corretores.roleta_id AND d.usuario_id = roleta_corretores.usuario_id),
       ultimo_lead_em  = (SELECT MAX(d.distribuido_em) FROM roleta_distribuicoes d WHERE d.roleta_id = roleta_corretores.roleta_id AND d.usuario_id = roleta_corretores.usuario_id)
 WHERE roleta_id IN (1, 2);

-- Presenças de ontem, já encerradas (alimentam o "última saída" da tela) ---
INSERT IGNORE INTO roleta_sessoes (id, empresa_id, roleta_id, usuario_id, entrou_em, ultima_verificacao_em, saiu_em, motivo_saida, aberta_chave) VALUES
    (1, 1, 1, 1, CONCAT(CURDATE() - INTERVAL 1 DAY, ' 09:12:00'), CONCAT(CURDATE() - INTERVAL 1 DAY, ' 17:55:00'), CONCAT(CURDATE() - INTERVAL 1 DAY, ' 18:00:00'), 'fora_do_horario', NULL),
    (2, 1, 1, 2, CONCAT(CURDATE() - INTERVAL 1 DAY, ' 10:05:00'), CONCAT(CURDATE() - INTERVAL 1 DAY, ' 11:20:00'), CONCAT(CURDATE() - INTERVAL 1 DAY, ' 11:40:00'), 'expirou', NULL),
    (3, 1, 1, 3, CONCAT(CURDATE() - INTERVAL 1 DAY, ' 13:30:00'), CONCAT(CURDATE() - INTERVAL 1 DAY, ' 16:10:00'), CONCAT(CURDATE() - INTERVAL 1 DAY, ' 16:10:00'), 'manual', NULL),
    (4, 1, 2, 9, CONCAT(CURDATE() - INTERVAL 1 DAY, ' 09:02:00'), CONCAT(CURDATE() - INTERVAL 1 DAY, ' 12:30:00'), CONCAT(CURDATE() - INTERVAL 1 DAY, ' 12:31:00'), 'fora_do_raio', NULL);

INSERT IGNORE INTO roleta_presencas (id, roleta_id, usuario_id, tipo, latitude, longitude, precisao_metros, distancia_metros, dentro_do_raio, motivo, confirmado_em) VALUES
    (6, 1, 1, 'entrada',  -23.5263100, -46.6767500, 18, 12,   1, NULL,            CONCAT(CURDATE() - INTERVAL 1 DAY, ' 09:12:00')),
    (7, 1, 2, 'entrada',  -23.5260000, -46.6770000, 25, 26,   1, NULL,            CONCAT(CURDATE() - INTERVAL 1 DAY, ' 10:05:00')),
    (8, 1, 3, 'recusada', -23.5420000, -46.6900000, 30, 2100, 0, 'fora_do_raio',  CONCAT(CURDATE() - INTERVAL 1 DAY, ' 13:20:00')),
    (9, 1, 3, 'entrada',  -23.5262500, -46.6766000, 20, 4,    1, NULL,            CONCAT(CURDATE() - INTERVAL 1 DAY, ' 13:30:00')),
    (10, 2, 9, 'recusada', -23.6450000, -46.7700000, 22, 2300, 0, 'fora_do_raio', CONCAT(CURDATE() - INTERVAL 1 DAY, ' 12:30:00'));

-- Dois leads aguardando na fila (chegaram sem ninguém no plantão) ----------
INSERT INTO leads (id, empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, origem, etapa, etapa_id, interesse, ultima_atividade_em, criado_em)
SELECT 27, 1, 3, NULL, NULL, 'Tatiane Ribeiro', '(11) 98123-4471', 'manual', 'novo_lead',
       (SELECT id FROM funil_etapas WHERE empresa_id = 1 AND categoria = 'novo_lead' ORDER BY ordem LIMIT 1),
       '2 dormitórios com varanda', NOW() - INTERVAL 40 MINUTE, NOW() - INTERVAL 40 MINUTE
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM leads WHERE id = 27);
INSERT INTO leads (id, empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, origem, etapa, etapa_id, interesse, ultima_atividade_em, criado_em)
SELECT 28, 1, 1, NULL, NULL, 'Wagner Lopes', '(11) 97456-1180', 'manual', 'novo_lead',
       (SELECT id FROM funil_etapas WHERE empresa_id = 1 AND categoria = 'novo_lead' ORDER BY ordem LIMIT 1),
       'Studio para investimento', NOW() - INTERVAL 15 MINUTE, NOW() - INTERVAL 15 MINUTE
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM leads WHERE id = 28);

INSERT IGNORE INTO roleta_fila (id, empresa_id, lead_id, roleta_id, origem, motivo, tentativas, entrou_em) VALUES
    (1, 1, 27, 1, 'manual', 'Nenhum corretor na roleta no momento', 1, NOW() - INTERVAL 40 MINUTE),
    (2, 1, 28, 1, 'manual', 'Nenhum corretor na roleta no momento', 1, NOW() - INTERVAL 15 MINUTE);

INSERT IGNORE INTO lead_eventos (id, lead_id, usuario_id, tipo, descricao, criado_em) VALUES
    (40, 27, NULL, 'sistema', 'Lead cadastrado pela gestão e colocado na fila de espera da roleta Casa HM — Zona Oeste', NOW() - INTERVAL 40 MINUTE),
    (41, 28, NULL, 'sistema', 'Lead cadastrado pela gestão e colocado na fila de espera da roleta Casa HM — Zona Oeste', NOW() - INTERVAL 15 MINUTE);
