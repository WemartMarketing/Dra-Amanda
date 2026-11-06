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

INSERT INTO superintendencias (id, empresa_id, nome) VALUES
    (1, 1, 'Zona Oeste'),
    (2, 1, 'Zona Sul');

-- Usuários (senha para todos: demo123)
INSERT INTO usuarios (id, empresa_id, nome, email, senha_hash, cargo) VALUES
    (1,  1, 'Carlos Silva',     'carlos@hmvendas.demo',          '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'corretor'),
    (2,  1, 'Fernanda Costa',   'fernanda.costa@hmvendas.demo',  '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'corretor'),
    (3,  1, 'Rafael Nunes',     'rafael.nunes@hmvendas.demo',    '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'corretor'),
    (4,  1, 'Ana Paula Reis',   'ana.gerente@hmvendas.demo',     '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'gerente'),
    (5,  1, 'Fernanda Gestora', 'fernanda.gestora@hmvendas.demo','$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'master_empresa'),
    (6,  1, 'Roberto Nascimento','roberto.super@hmvendas.demo',  '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'superintendente'),
    (7,  1, 'Camila Duarte',   'camila.super@hmvendas.demo',     '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'superintendente'),
    (8,  1, 'Diego Martins',   'diego.gerente@hmvendas.demo',    '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'gerente'),
    (9,  1, 'Juliana Prado',   'juliana.prado@hmvendas.demo',    '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'corretor'),
    (10, 1, 'Marcelo Diniz',   'marcelo.diniz@hmvendas.demo',    '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'corretor'),
    (11, 1, 'Patrícia Lima',   'patricia.produto@hmvendas.demo', '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'gp_produto'),
    (12, 1, 'Eduardo Nogueira','eduardo.lider@hmvendas.demo',    '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'gp_lider'),
    (13, 1, 'Beatriz Sales',   'beatriz.geral@hmvendas.demo',    '$2y$10$ad/19PZCUWIfyXBk5Pg0lujtdxbNjKEQR6DsgMrQnaTrt0s0MPQw.', 'gerente_geral');

INSERT INTO equipes (id, empresa_id, superintendencia_id, nome, gerente_id) VALUES
    (1, 1, 1, 'Equipe Zona Oeste', 4),
    (2, 1, 2, 'Equipe Zona Sul', 8);

UPDATE usuarios SET equipe_id = 1 WHERE id IN (1, 2, 3);
UPDATE usuarios SET equipe_id = 2 WHERE id IN (9, 10);
UPDATE superintendencias SET superintendente_id = 6 WHERE id = 1;
UPDATE superintendencias SET superintendente_id = 7 WHERE id = 2;

-- GP do Produto: Patrícia enxerga só os leads de Smart Marquês e Flow Itaim.
INSERT INTO usuario_empreendimentos (usuario_id, empreendimento_id) VALUES (11, 1), (11, 4);

-- Roletas de distribuição (ver tela 05) — uma ativa por superintendência e
-- uma pausada, para a lista não ficar trivial.
INSERT INTO roletas (id, empresa_id, nome, ativa, raio_presenca_metros, horario_inicio, horario_fim) VALUES
    (1, 1, 'Casa HM — Zona Oeste', 1, 300, '09:00:00', '18:00:00'),
    (2, 1, 'Casa HM — Zona Sul', 1, 300, '09:00:00', '18:00:00'),
    (3, 1, 'Interior — Campinas', 0, 500, '09:00:00', '17:00:00');

INSERT INTO roleta_empreendimentos (roleta_id, empreendimento_id) VALUES
    (1, 1), (1, 2), (1, 3),
    (2, 2), (2, 4),
    (3, 4);
INSERT INTO roleta_corretores (roleta_id, usuario_id, ordem) VALUES
    (1, 1, 1), (1, 2, 2), (1, 3, 3),
    (2, 9, 1), (2, 10, 2);

-- Presença: Carlos e Juliana confirmaram recentemente e estão dentro do
-- raio (Disponível); Fernanda Costa está fora do raio agora (Bloqueado);
-- Rafael não confirma presença há dias (também some da lista "disponível").
INSERT INTO roleta_presencas (roleta_id, usuario_id, dentro_do_raio, confirmado_em) VALUES
    (1, 1, 1, DATE_SUB(NOW(), INTERVAL 20 MINUTE)),
    (1, 2, 0, DATE_SUB(NOW(), INTERVAL 10 MINUTE)),
    (1, 3, 1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
    (2, 9, 1, DATE_SUB(NOW(), INTERVAL 35 MINUTE)),
    (2, 10, 1, DATE_SUB(NOW(), INTERVAL 1 HOUR));

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

-- ------------------------------------------------------------------
-- Leads da Equipe Zona Sul (Juliana Prado e Marcelo Diniz) — alimenta os
-- relatórios com mais de uma equipe/empreendimento e inclui leads
-- perdidos (ver tela 04, "Oportunidades perdidas").
-- ------------------------------------------------------------------
INSERT INTO leads (id, empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, email, interesse, renda_informada, origem, etapa, motivo_perda, proximo_passo, data_retorno, ultimo_contato_em, criado_em, atualizado_em) VALUES
    (15, 1, 2, 2, 9,  'Henrique Tavares', '11994001100', NULL, '2 dormitórios', NULL, 'meta_ads',   'novo_lead',        NULL, 'Ligar novamente',  CURDATE(), NOW(),                          DATE_SUB(NOW(), INTERVAL 2 HOUR),  DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (16, 1, 4, 2, 9,  'Camila Ferreira',  '11993002211', NULL, '1 dormitório',  NULL, 'manual',     'primeiro_contato', NULL, 'Confirmar visita', CURDATE(), DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY),   DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (17, 1, 2, 2, 10, 'Vinícius Rocha',   '11992003322', NULL, '3 dormitórios', NULL, 'meta_ads',   'em_atendimento',   NULL, 'Enviar proposta',  CURDATE(), DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY),   DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (18, 1, 4, 2, 10, 'Letícia Moraes',   '11991004433', NULL, '2 dormitórios', NULL, 'indicacao',  'visita_agendada',  NULL, 'Confirmar visita', DATE_ADD(CURDATE(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (19, 1, 2, 2, 9,  'Gustavo Pires',    '11990005544', NULL, '2 dormitórios', NULL, 'meta_ads',   'visita_realizada', NULL, 'Enviar proposta',  NULL, DATE_SUB(NOW(), INTERVAL 4 HOUR), DATE_SUB(NOW(), INTERVAL 8 DAY),  DATE_SUB(NOW(), INTERVAL 4 HOUR)),
    (20, 1, 4, 2, 10, 'Renata Quintão',   '11989006655', NULL, '1 dormitório',  NULL, 'manual',     'contrato_assinado', NULL, NULL,              NULL, NOW(),                          DATE_SUB(NOW(), INTERVAL 18 DAY), NOW()),
    (21, 1, 2, 2, 9,  'Otávio Barros',    '11988007766', NULL, '3 dormitórios', NULL, 'meta_ads',   'perdido', 'Sem resposta do cliente',   NULL, NULL, DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
    (22, 1, 1, 1, 2,  'Simone Castro',    '11987008877', NULL, '2 dormitórios', NULL, 'manual',     'perdido', 'Sem resposta do cliente',   NULL, NULL, DATE_SUB(NOW(), INTERVAL 9 DAY),  DATE_SUB(NOW(), INTERVAL 9 DAY),  DATE_SUB(NOW(), INTERVAL 4 DAY)),
    (23, 1, 3, 1, 3,  'Felipe Andrade',   '11986009988', NULL, '1 dormitório',  NULL, 'meta_ads',   'perdido', 'Sem resposta do cliente',   NULL, NULL, DATE_SUB(NOW(), INTERVAL 7 DAY),  DATE_SUB(NOW(), INTERVAL 7 DAY),  DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (24, 1, 4, 2, 10, 'Priscila Nunes',   '11985000099', NULL, '2 dormitórios', NULL, 'indicacao',  'perdido', 'Fechou com concorrente',   NULL, NULL, DATE_SUB(NOW(), INTERVAL 6 DAY),  DATE_SUB(NOW(), INTERVAL 6 DAY),  DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (25, 1, 2, 2, 9,  'Alexandre Melo',   '11984001188', NULL, '3 dormitórios', NULL, 'meta_ads',   'perdido', 'Fechou com concorrente',   NULL, NULL, DATE_SUB(NOW(), INTERVAL 11 DAY), DATE_SUB(NOW(), INTERVAL 11 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),
    (26, 1, 1, 1, 1,  'Larissa Gomes',    '11983002299', NULL, '2 dormitórios', NULL, 'manual',     'perdido', 'Fora do perfil de crédito', NULL, NULL, DATE_SUB(NOW(), INTERVAL 13 DAY), DATE_SUB(NOW(), INTERVAL 13 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY));

INSERT INTO lead_eventos (lead_id, usuario_id, tipo, descricao, criado_em) VALUES
    (15, 9,  'atribuicao', 'Lead atribuído a Juliana Prado — origem: roleta Casa HM — Zona Sul', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (17, 10, 'contato',    'Cliente pediu condições de pagamento.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (20, 10, 'mudanca_etapa', 'Etapa alterada de "Visita realizada" para "Contrato assinado"', NOW()),
    (21, 9,  'mudanca_etapa', 'Etapa alterada de "Em atendimento" para "Perdido"', DATE_SUB(NOW(), INTERVAL 3 DAY)),
    (22, 2,  'mudanca_etapa', 'Etapa alterada de "1º contato" para "Perdido"', DATE_SUB(NOW(), INTERVAL 4 DAY)),
    (23, 3,  'mudanca_etapa', 'Etapa alterada de "Novo lead" para "Perdido"', DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (24, 10, 'mudanca_etapa', 'Etapa alterada de "Visita agendada" para "Perdido"', DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (25, 9,  'mudanca_etapa', 'Etapa alterada de "Em atendimento" para "Perdido"', DATE_SUB(NOW(), INTERVAL 5 DAY)),
    (26, 1,  'mudanca_etapa', 'Etapa alterada de "1º contato" para "Perdido"', DATE_SUB(NOW(), INTERVAL 6 DAY));

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
