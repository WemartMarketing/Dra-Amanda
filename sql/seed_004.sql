-- WeMart Sales — dados de demonstração da Etapa 3 (CRM).
-- Fictícios. Idempotente: pode rodar mais de uma vez (cada insert confere
-- se o registro já existe). Só afeta a empresa de demonstração
-- "hm-vendas"; em uma instalação real, NÃO rode este arquivo.
--
-- Ordem: schema → seed → migration_002 → backfill_002 → migration_003 →
--        seed_003 → migration_004 → seed_004
--
-- Horários são relativos a quando o arquivo roda (hoje, atrasados e
-- próximos), para a agenda sempre ter algo em cada grupo.

SET NAMES utf8mb4;

SET @empresa := (SELECT id FROM empresas WHERE slug = 'hm-vendas' LIMIT 1);
SET @carlos  := (SELECT id FROM usuarios WHERE email = 'carlos@hmvendas.demo' LIMIT 1);
SET @juliana := (SELECT id FROM usuarios WHERE email = 'juliana.prado@hmvendas.demo' LIMIT 1);
SET @marcelo := (SELECT id FROM usuarios WHERE email = 'marcelo.diniz@hmvendas.demo' LIMIT 1);
SET @rafael  := (SELECT id FROM usuarios WHERE email = 'rafael.nunes@hmvendas.demo' LIMIT 1);
SET @diego   := (SELECT id FROM usuarios WHERE email = 'diego.gerente@hmvendas.demo' LIMIT 1);

-- ------------------------------------------------------------ Prioridades
UPDATE leads SET prioridade = 'alta'  WHERE empresa_id = @empresa AND nome IN ('Ana Souza', 'Beatriz Almeida', 'Henrique Tavares') AND prioridade = 'normal';
UPDATE leads SET prioridade = 'baixa' WHERE empresa_id = @empresa AND nome IN ('Marcos Vieira', 'Aline Freitas') AND prioridade = 'normal';

-- ------------------------------------------------------------ Agenda
-- Cada linha só entra se ainda não existir um compromisso com o mesmo
-- título, responsável e lead (sem procedures: a hospedagem compartilhada
-- costuma não permitir CREATE ROUTINE).

INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, concluido_em)
SELECT @empresa, l.id, @carlos, 'retorno', 'Retorno: enviar simulação', DATE_SUB(CURDATE(), INTERVAL 1 DAY) + INTERVAL 10 HOUR, 'agendado', @carlos, NULL
FROM leads l WHERE l.empresa_id = @empresa AND l.nome = 'Marina Costa' AND l.excluido_em IS NULL AND @carlos IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM agenda_compromissos a WHERE a.lead_id = l.id AND a.usuario_id = @carlos AND a.titulo = 'Retorno: enviar simulação');

INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, concluido_em)
SELECT @empresa, l.id, @carlos, 'retorno', 'Ligar para confirmar documentos', CURDATE() + INTERVAL 9 HOUR, 'agendado', @carlos, NULL
FROM leads l WHERE l.empresa_id = @empresa AND l.nome = 'Paula Rocha' AND l.excluido_em IS NULL AND @carlos IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM agenda_compromissos a WHERE a.lead_id = l.id AND a.usuario_id = @carlos AND a.titulo = 'Ligar para confirmar documentos');

INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, concluido_em)
SELECT @empresa, l.id, @carlos, 'visita', 'Visita ao decorado', CURDATE() + INTERVAL 15 HOUR, 'agendado', @carlos, NULL
FROM leads l WHERE l.empresa_id = @empresa AND l.nome = 'Beatriz Almeida' AND l.excluido_em IS NULL AND @carlos IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM agenda_compromissos a WHERE a.lead_id = l.id AND a.usuario_id = @carlos AND a.titulo = 'Visita ao decorado');

INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, concluido_em)
SELECT @empresa, l.id, @carlos, 'visita', 'Visita ao stand de vendas', CURDATE() + INTERVAL 1 DAY + INTERVAL 11 HOUR, 'agendado', @carlos, NULL
FROM leads l WHERE l.empresa_id = @empresa AND l.nome = 'Lucas Alves' AND l.excluido_em IS NULL AND @carlos IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM agenda_compromissos a WHERE a.lead_id = l.id AND a.usuario_id = @carlos AND a.titulo = 'Visita ao stand de vendas');

INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, concluido_em)
SELECT @empresa, l.id, @carlos, 'retorno', 'Retorno sobre a proposta', CURDATE() + INTERVAL 2 DAY + INTERVAL 14 HOUR, 'agendado', @carlos, NULL
FROM leads l WHERE l.empresa_id = @empresa AND l.nome = 'Fernanda Martins' AND l.excluido_em IS NULL AND @carlos IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM agenda_compromissos a WHERE a.lead_id = l.id AND a.usuario_id = @carlos AND a.titulo = 'Retorno sobre a proposta');

INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, concluido_em)
SELECT @empresa, NULL, @carlos, 'tarefa', 'Atualizar tabela de preços', CURDATE() + INTERVAL 3 DAY + INTERVAL 9 HOUR, 'agendado', @carlos, NULL
FROM DUAL WHERE @empresa IS NOT NULL AND @carlos IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM agenda_compromissos a WHERE a.lead_id IS NULL AND a.usuario_id = @carlos AND a.titulo = 'Atualizar tabela de preços');

INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, concluido_em)
SELECT @empresa, l.id, @carlos, 'visita', 'Visita ao apartamento modelo', DATE_SUB(CURDATE(), INTERVAL 3 DAY) + INTERVAL 16 HOUR, 'realizado', @carlos, DATE_SUB(CURDATE(), INTERVAL 3 DAY) + INTERVAL 16 HOUR
FROM leads l WHERE l.empresa_id = @empresa AND l.nome = 'Bruno Silva' AND l.excluido_em IS NULL AND @carlos IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM agenda_compromissos a WHERE a.lead_id = l.id AND a.usuario_id = @carlos AND a.titulo = 'Visita ao apartamento modelo');

INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, concluido_em)
SELECT @empresa, l.id, @juliana, 'retorno', 'Retorno: dúvidas sobre o financiamento', CURDATE() + INTERVAL 1 DAY + INTERVAL 10 HOUR, 'agendado', @juliana, NULL
FROM leads l WHERE l.empresa_id = @empresa AND l.nome = 'Camila Ferreira' AND l.excluido_em IS NULL AND @juliana IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM agenda_compromissos a WHERE a.lead_id = l.id AND a.usuario_id = @juliana AND a.titulo = 'Retorno: dúvidas sobre o financiamento');

INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, concluido_em)
SELECT @empresa, l.id, @marcelo, 'visita', 'Visita ao decorado', CURDATE() + INTERVAL 2 DAY + INTERVAL 10 HOUR, 'agendado', @marcelo, NULL
FROM leads l WHERE l.empresa_id = @empresa AND l.nome = 'Letícia Moraes' AND l.excluido_em IS NULL AND @marcelo IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM agenda_compromissos a WHERE a.lead_id = l.id AND a.usuario_id = @marcelo AND a.titulo = 'Visita ao decorado');

INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, concluido_em)
SELECT @empresa, l.id, @marcelo, 'retorno', 'Retorno: aguardar holerite', DATE_SUB(CURDATE(), INTERVAL 2 DAY) + INTERVAL 15 HOUR, 'agendado', @marcelo, NULL
FROM leads l WHERE l.empresa_id = @empresa AND l.nome = 'Vinícius Rocha' AND l.excluido_em IS NULL AND @marcelo IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM agenda_compromissos a WHERE a.lead_id = l.id AND a.usuario_id = @marcelo AND a.titulo = 'Retorno: aguardar holerite');


-- ------------------------------------------------------------ Transferência
-- "Otávio Barros" veio da carteira do Rafael (Zona Oeste) para a Juliana
-- (Zona Sul). O lead já está com a Juliana no seed; aqui só registramos a
-- transferência com a estrutura de origem e destino.
INSERT INTO lead_transferencias (empresa_id, lead_id, empreendimento_id,
        de_usuario_id, de_equipe_id, de_gerente_id, de_superintendencia_id,
        para_usuario_id, para_equipe_id, para_gerente_id, para_superintendencia_id,
        motivo, observacao, executado_por, criado_em)
SELECT @empresa, l.id, l.empreendimento_id,
       @rafael, er.id, er.gerente_id, er.superintendencia_id,
       @juliana, ej.id, ej.gerente_id, ej.superintendencia_id,
       'administrativa', 'Demonstração: cliente mora na Zona Sul.', @diego, DATE_SUB(NOW(), INTERVAL 5 DAY)
FROM leads l
JOIN usuarios ur ON ur.id = @rafael
JOIN equipes er ON er.id = ur.equipe_id
JOIN usuarios uj ON uj.id = @juliana
JOIN equipes ej ON ej.id = uj.equipe_id
WHERE l.empresa_id = @empresa AND l.nome = 'Otávio Barros'
  AND NOT EXISTS (SELECT 1 FROM lead_transferencias t WHERE t.lead_id = l.id AND t.motivo = 'administrativa');

INSERT INTO lead_eventos (lead_id, usuario_id, tipo, descricao, criado_em)
SELECT l.id, @diego, 'transferencia', 'Lead transferido de Rafael Nunes para Juliana Prado (transferência administrativa)', DATE_SUB(NOW(), INTERVAL 5 DAY)
FROM leads l
WHERE l.empresa_id = @empresa AND l.nome = 'Otávio Barros'
  AND NOT EXISTS (SELECT 1 FROM lead_eventos ev WHERE ev.lead_id = l.id AND ev.tipo = 'transferencia');
