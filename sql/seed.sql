-- WeMart Sales — dados de demonstração (ILUSTRATIVOS, ver LEIA-ME).
-- Pressupõe um banco recém-criado a partir de schema.sql (IDs previsíveis).
-- Senha de todos os usuários de demonstração: demo123

SET NAMES utf8mb4;
-- Mesmo fuso fixo usado pela aplicação (ver includes/db.php) — garante que
-- os NOW()/CURDATE() abaixo fiquem coerentes com o horário que o PHP usa
-- para calcular "há quantos minutos/dias".
SET time_zone = '-03:00';

INSERT INTO empresas (id, nome, slug) VALUES (1, 'HM Vendas', 'hm-vendas');

INSERT INTO empreendimentos (id, empresa_id, nome) VALUES
    (1, 1, 'Smart Marquês'),
    (2, 1, 'Vila das Belezas'),
    (3, 1, 'Freguesia do Ó'),
    (4, 1, 'Flow Itaim');

INSERT INTO superintendencias (id, empresa_id, nome) VALUES (1, 1, 'Zona Oeste');

-- Usuários (senha para todos: demo123)
INSERT INTO usuarios (id, empresa_id, nome, email, senha_hash, cargo) VALUES
    (1, 1, 'Carlos Silva',   'carlos@hmvendas.demo',          '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'corretor'),
    (2, 1, 'Fernanda Costa', 'fernanda.costa@hmvendas.demo',  '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'corretor'),
    (3, 1, 'Rafael Nunes',   'rafael.nunes@hmvendas.demo',    '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'corretor'),
    (4, 1, 'Ana Paula Reis', 'ana.gerente@hmvendas.demo',     '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'gerente'),
    (5, 1, 'Fernanda Gestora', 'fernanda.gestora@hmvendas.demo', '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'master_empresa');

INSERT INTO equipes (id, empresa_id, superintendencia_id, nome, gerente_id) VALUES
    (1, 1, 1, 'Equipe Zona Oeste', 4);

UPDATE usuarios SET equipe_id = 1 WHERE id IN (1, 2, 3);
UPDATE superintendencias SET superintendente_id = 5 WHERE id = 1;

-- Roleta de distribuição (ver tela 05)
INSERT INTO roletas (id, empresa_id, nome, ativa, raio_presenca_metros, horario_inicio, horario_fim) VALUES
    (1, 1, 'Casa HM — Zona Oeste', 1, 300, '09:00:00', '18:00:00');

INSERT INTO roleta_empreendimentos (roleta_id, empreendimento_id) VALUES (1,1), (1,2), (1,3);
INSERT INTO roleta_corretores (roleta_id, usuario_id, ordem) VALUES (1, 1, 1), (1, 2, 2);
INSERT INTO roleta_presencas (roleta_id, usuario_id, dentro_do_raio) VALUES (1, 1, 1);

-- ------------------------------------------------------------------
-- Leads (carteira de Carlos Silva, equipe Zona Oeste) — 12 ativos +
-- 2 já convertidos neste mês, para a KPI "Vendas do mês".
-- ------------------------------------------------------------------
INSERT INTO leads (id, empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, email, interesse, renda_informada, origem, etapa, proximo_passo, data_retorno, ultimo_contato_em, criado_em) VALUES
    (1,  1, 1, 1, 1, 'Ana Souza',      '11988776655', 'ana.souza@exemplo.com',      '2 dormitórios', 5600.00, 'meta_ads', 'novo_lead',        'Ligar novamente', CURDATE(),           NOW(),                         DATE_SUB(NOW(), INTERVAL 28 MINUTE)),
    (2,  1, 2, 1, 1, 'Rafael Lima',    '11987001122', NULL,                          '3 dormitórios', NULL,    'manual',   'novo_lead',        NULL,               NULL,                NULL,                          DATE_SUB(NOW(), INTERVAL 41 MINUTE)),
    (3,  1, 3, 1, 1, 'João Pedro',     '11986002233', NULL,                          '2 dormitórios', NULL,    'meta_ads', 'novo_lead',        NULL,               NULL,                NULL,                          DATE_SUB(NOW(), INTERVAL 1 HOUR)),
    (4,  1, 1, 1, 1, 'Marina Costa',   '11985003344', NULL,                          '1 dormitório',  NULL,    'meta_ads', 'primeiro_contato', 'Confirmar visita', CURDATE(),           DATE_SUB(NOW(), INTERVAL 2 HOUR), DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (5,  1, 2, 1, 1, 'Carlos Mendes',  '11984004455', NULL,                          '2 dormitórios', NULL,    'manual',   'primeiro_contato', 'Ligar novamente',  DATE_ADD(CURDATE(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (6,  1, 3, 1, 1, 'Fernanda Martins','11983005566', NULL,                         '3 dormitórios', NULL,    'indicacao','em_atendimento',   'Confirmar visita', DATE_ADD(CURDATE(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (7,  1, 2, 1, 1, 'Roberto Brito',  '11982006677', NULL,                          '2 dormitórios', NULL,    'meta_ads', 'em_atendimento',   'Enviar proposta',  CURDATE(),           DATE_SUB(NOW(), INTERVAL 5 HOUR), DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (8,  1, 1, 1, 1, 'Paula Rocha',    '11981007788', NULL,                          '1 dormitório',  NULL,    'manual',   'em_atendimento',   'Aguardar documentos', DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY)),
    (9,  1, 3, 1, 1, 'Beatriz Almeida','11980008899', NULL,                          '2 dormitórios', NULL,    'meta_ads', 'visita_agendada',  'Confirmar visita', CURDATE(),           DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
    (10, 1, 2, 1, 1, 'Lucas Alves',    '11999001122', NULL,                          '3 dormitórios', NULL,    'indicacao','visita_agendada',  'Confirmar visita', DATE_ADD(CURDATE(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),
    (11, 1, 1, 1, 1, 'Bruno Silva',    '11998002233', NULL,                          '2 dormitórios', NULL,    'meta_ads', 'visita_realizada', 'Enviar proposta',  NULL,                DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_SUB(NOW(), INTERVAL 6 DAY)),
    (12, 1, 2, 1, 1, 'Aline Freitas',  '11997003344', NULL,                          '1 dormitório',  NULL,    'manual',   'visita_realizada', 'Enviar proposta',  CURDATE(),           DATE_SUB(NOW(), INTERVAL 4 HOUR), DATE_SUB(NOW(), INTERVAL 4 DAY)),
    (13, 1, 1, 1, 1, 'Sandra Lima',    '11996004455', NULL,                          '3 dormitórios', NULL,    'meta_ads', 'contrato_assinado', NULL,              NULL,                NOW(),                         DATE_SUB(NOW(), INTERVAL 20 DAY)),
    (14, 1, 3, 1, 1, 'Marcos Vieira',  '11995005566', NULL,                          '2 dormitórios', NULL,    'indicacao','contrato_assinado', NULL,              NULL,                NOW(),                         DATE_SUB(NOW(), INTERVAL 15 DAY));

-- Histórico (ver tela 03 — exemplo fiel para o lead Ana Souza)
INSERT INTO lead_eventos (lead_id, usuario_id, tipo, descricao, criado_em) VALUES
    (1, 1, 'atribuicao',   'Lead atribuído a Carlos Silva — origem: roleta Casa HM — Zona Oeste', DATE_SUB(NOW(), INTERVAL 28 MINUTE)),
    (1, 1, 'contato',      'Cliente demonstrou interesse no imóvel.', DATE_SUB(NOW(), INTERVAL 15 MINUTE)),
    (2, 1, 'atribuicao',   'Lead atribuído a Carlos Silva — origem: roleta Casa HM — Zona Oeste', DATE_SUB(NOW(), INTERVAL 41 MINUTE)),
    (3, 1, 'atribuicao',   'Lead atribuído a Carlos Silva — origem: roleta Casa HM — Zona Oeste', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
    (4, 1, 'atribuicao',   'Lead atribuído a Carlos Silva — origem: roleta Casa HM — Zona Oeste', DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (4, 1, 'mudanca_etapa','Etapa alterada de "Novo lead" para "1º contato"', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (7, 1, 'contato',      'Cliente confirmou interesse e pediu condições de pagamento.', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
    (9, 1, 'mudanca_etapa','Etapa alterada de "Em atendimento" para "Visita agendada"', DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (13,1, 'mudanca_etapa','Etapa alterada de "Visita realizada" para "Contrato assinado"', NOW()),
    (14,1, 'mudanca_etapa','Etapa alterada de "Visita realizada" para "Contrato assinado"', NOW());

-- Próximas ações (ver tela 03 — exemplo fiel para o lead Ana Souza)
INSERT INTO lead_tarefas (lead_id, titulo, descricao, data_vencimento, concluida) VALUES
    (1, 'Retornar contato', 'Confirmar disponibilidade', DATE_ADD(CURDATE(), INTERVAL 1 DAY), 0),
    (1, 'Enviar condições', 'Informar entrada e mensais', NULL, 0),
    (7, 'Enviar proposta formal', NULL, CURDATE(), 0),
    (9, 'Confirmar presença na visita', NULL, CURDATE(), 0);
