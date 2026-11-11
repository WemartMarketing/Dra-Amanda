-- WeMart Sales — dados de demonstração da Etapa 2 (estrutura comercial).
-- Fictícios. Idempotente: pode rodar mais de uma vez. Só afeta a empresa
-- de demonstração "hm-vendas"; em uma instalação real, NÃO rode este
-- arquivo (cadastre plantões/empreendimentos pela interface).
--
-- Ordem: schema → seed → migration_002 → backfill_002 → migration_003 → seed_003

SET NAMES utf8mb4;

SET @empresa := (SELECT id FROM empresas WHERE slug = 'hm-vendas' LIMIT 1);

-- Empreendimentos: completa os campos dos 4 já existentes (só preenche o
-- que ainda está vazio, para não sobrescrever edições feitas pela tela).
UPDATE empreendimentos SET
    construtora = COALESCE(construtora, 'HM Engenharia'),
    endereco = COALESCE(endereco, 'Rua Marquês de São Vicente, 1500'),
    bairro = COALESCE(bairro, 'Barra Funda'), cidade = COALESCE(cidade, 'São Paulo'), estado = COALESCE(estado, 'SP'),
    status_comercial = IF(descricao IS NULL, 'lancamento', status_comercial),
    descricao = COALESCE(descricao, 'Apartamentos de 2 dormitórios com varanda, lazer completo e a 600 m do metrô. Enquadrado no programa habitacional.')
WHERE empresa_id = @empresa AND nome = 'Smart Marquês';

UPDATE empreendimentos SET
    construtora = COALESCE(construtora, 'HM Engenharia'),
    endereco = COALESCE(endereco, 'Av. Raimundo Pereira de Magalhães, 3300'),
    bairro = COALESCE(bairro, 'Freguesia do Ó'), cidade = COALESCE(cidade, 'São Paulo'), estado = COALESCE(estado, 'SP'),
    status_comercial = IF(descricao IS NULL, 'em_obras', status_comercial),
    descricao = COALESCE(descricao, 'Condomínio clube com 2 e 3 dormitórios, entrega prevista para o próximo ano.')
WHERE empresa_id = @empresa AND nome = 'Freguesia do Ó';

UPDATE empreendimentos SET
    construtora = COALESCE(construtora, 'HM Engenharia'),
    endereco = COALESCE(endereco, 'Estrada do Campo Limpo, 4100'),
    bairro = COALESCE(bairro, 'Campo Limpo'), cidade = COALESCE(cidade, 'São Paulo'), estado = COALESCE(estado, 'SP'),
    status_comercial = IF(descricao IS NULL, 'pronto', status_comercial),
    descricao = COALESCE(descricao, 'Unidades prontas para morar, 2 dormitórios com vaga. Últimas unidades.')
WHERE empresa_id = @empresa AND nome = 'Vila das Belezas';

UPDATE empreendimentos SET
    construtora = COALESCE(construtora, 'HM Engenharia'),
    endereco = COALESCE(endereco, 'Rua Joaquim Floriano, 820'),
    bairro = COALESCE(bairro, 'Itaim Bibi'), cidade = COALESCE(cidade, 'São Paulo'), estado = COALESCE(estado, 'SP'),
    status_comercial = IF(descricao IS NULL, 'lancamento', status_comercial),
    descricao = COALESCE(descricao, 'Studios e 1 dormitório para investidor, rooftop e coworking.')
WHERE empresa_id = @empresa AND nome = 'Flow Itaim';

-- Plantões (coordenadas aproximadas, fictícias)
INSERT INTO plantoes (empresa_id, nome, endereco, bairro, cidade, estado, latitude, longitude, raio_metros, ativo)
SELECT @empresa, 'Casa HM — Zona Oeste', 'Av. Francisco Matarazzo, 1500', 'Água Branca', 'São Paulo', 'SP', -23.5262100, -46.6766900, 300, 1
FROM DUAL WHERE @empresa IS NOT NULL AND NOT EXISTS (SELECT 1 FROM plantoes WHERE empresa_id = @empresa AND nome = 'Casa HM — Zona Oeste');

INSERT INTO plantoes (empresa_id, nome, endereco, bairro, cidade, estado, latitude, longitude, raio_metros, ativo)
SELECT @empresa, 'Casa HM — Zona Sul', 'Estrada do Campo Limpo, 4000', 'Campo Limpo', 'São Paulo', 'SP', -23.6332500, -46.7590500, 300, 1
FROM DUAL WHERE @empresa IS NOT NULL AND NOT EXISTS (SELECT 1 FROM plantoes WHERE empresa_id = @empresa AND nome = 'Casa HM — Zona Sul');

INSERT INTO plantoes (empresa_id, nome, endereco, bairro, cidade, estado, latitude, longitude, raio_metros, ativo)
SELECT @empresa, 'Interior — Campinas', 'Av. Norte-Sul, 1200', 'Cambuí', 'Campinas', 'SP', -22.8936700, -47.0498500, 400, 1
FROM DUAL WHERE @empresa IS NOT NULL AND NOT EXISTS (SELECT 1 FROM plantoes WHERE empresa_id = @empresa AND nome = 'Interior — Campinas');

-- Plantão ↔ empreendimentos
INSERT IGNORE INTO plantao_empreendimentos (plantao_id, empreendimento_id)
SELECT p.id, e.id FROM plantoes p JOIN empreendimentos e ON e.empresa_id = p.empresa_id
WHERE p.empresa_id = @empresa AND (
       (p.nome = 'Casa HM — Zona Oeste' AND e.nome IN ('Smart Marquês', 'Freguesia do Ó'))
    OR (p.nome = 'Casa HM — Zona Sul'   AND e.nome IN ('Vila das Belezas'))
    OR (p.nome = 'Interior — Campinas'  AND e.nome IN ('Flow Itaim'))
);

-- Roleta → plantão (mesmo nome), sem sobrescrever vínculo já escolhido
UPDATE roletas r JOIN plantoes p ON p.empresa_id = r.empresa_id AND p.nome = r.nome
SET r.plantao_id = p.id
WHERE r.empresa_id = @empresa AND r.plantao_id IS NULL;

-- Equipes ↔ empreendimentos
INSERT IGNORE INTO equipe_empreendimentos (equipe_id, empreendimento_id)
SELECT eq.id, e.id FROM equipes eq JOIN empreendimentos e ON e.empresa_id = eq.empresa_id
WHERE eq.empresa_id = @empresa AND (
       (eq.nome = 'Equipe Zona Oeste' AND e.nome IN ('Smart Marquês', 'Freguesia do Ó'))
    OR (eq.nome = 'Equipe Zona Sul'   AND e.nome IN ('Vila das Belezas', 'Flow Itaim'))
);
