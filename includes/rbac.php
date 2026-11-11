<?php
require_once __DIR__ . '/funcoes.php';

/**
 * RBAC — visibilidade de leads (por escopo da função) e permissões de
 * ação (por permissão da função), ambos resolvidos a partir de
 * usuarios.funcao_id (ver includes/auth.php). A coluna legada
 * usuarios.cargo não é mais lida por nenhuma destas funções.
 *
 * rbac_leads_scope()      → filtro de LINHA (quais leads o usuário vê).
 * usuario_tem_permissao() → filtro de AÇÃO (o que o usuário pode fazer).
 * São checados de forma independente; nenhuma página deve montar essa
 * lógica por conta própria.
 */
function rbac_leads_scope(array $usuario): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $uid = (int) $usuario['id'];

    // Leads excluídos (exclusão lógica, LGPD) saem de TODA consulta que
    // passa pelo escopo — Kanban, listas, ficha, relatórios, APIs.
    $base = 'leads.empresa_id = :rbac_empresa_id AND leads.excluido_em IS NULL';
    $params = ['rbac_empresa_id' => $empresaId];

    switch ($usuario['escopo']) {
        case 'toda_empresa':
        case 'todos_produtos':
            // Enxergam todos os produtos/leads da empresa ativa.
            return ['sql' => $base, 'params' => $params];

        case 'produtos_vinculados':
            $params['rbac_uid'] = $uid;
            return [
                'sql' => $base . ' AND leads.empreendimento_id IN (
                    SELECT empreendimento_id FROM usuario_empreendimentos WHERE usuario_id = :rbac_uid
                )',
                'params' => $params,
            ];

        case 'superintendencia':
            $params['rbac_uid'] = $uid;
            return [
                'sql' => $base . ' AND leads.equipe_id IN (
                    SELECT eq.id FROM equipes eq
                    INNER JOIN superintendencias s ON s.id = eq.superintendencia_id
                    WHERE s.superintendente_id = :rbac_uid
                )',
                'params' => $params,
            ];

        case 'equipe':
            $params['rbac_uid'] = $uid;
            return [
                'sql' => $base . ' AND leads.equipe_id IN (
                    SELECT id FROM equipes WHERE gerente_id = :rbac_uid
                )',
                'params' => $params,
            ];

        case 'propria_carteira':
        default:
            $params['rbac_uid'] = $uid;
            return ['sql' => $base . ' AND leads.responsavel_id = :rbac_uid', 'params' => $params];
    }
}

/**
 * Usuários (da empresa) cuja carteira/agenda o usuário logado enxerga —
 * mesmo recorte do escopo da função, aplicado a pessoas em vez de leads.
 * Retorna um fragmento SQL sobre o alias `u` (tabela usuarios). O
 * próprio usuário sempre entra.
 *
 *   toda_empresa / todos_produtos → todos da empresa
 *   superintendencia              → membros e gerentes das equipes da sua superintendência
 *   equipe                        → membros das equipes que gerencia
 *   produtos_vinculados           → membros das equipes vinculadas aos seus empreendimentos
 *   propria_carteira              → só ele mesmo
 */
function rbac_usuarios_scope(array $usuario): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $uid = (int) $usuario['id'];
    $base = 'u.empresa_id = :ru_empresa_id AND u.eh_superadmin = 0';
    $params = ['ru_empresa_id' => $empresaId, 'ru_uid' => $uid];

    switch ($usuario['escopo']) {
        case 'toda_empresa':
        case 'todos_produtos':
            unset($params['ru_uid']);
            return ['sql' => $base, 'params' => $params];

        case 'superintendencia':
            $params['ru_uid2'] = $uid;
            return ['sql' => $base . ' AND (u.id = :ru_uid OR u.equipe_id IN (
                    SELECT eq.id FROM equipes eq INNER JOIN superintendencias s ON s.id = eq.superintendencia_id WHERE s.superintendente_id = :ru_uid2
                ) OR u.id IN (
                    SELECT eq.gerente_id FROM equipes eq INNER JOIN superintendencias s ON s.id = eq.superintendencia_id WHERE s.superintendente_id = :ru_uid3 AND eq.gerente_id IS NOT NULL
                ))', 'params' => $params + ['ru_uid3' => $uid]];

        case 'equipe':
            $params['ru_uid2'] = $uid;
            return ['sql' => $base . ' AND (u.id = :ru_uid OR u.equipe_id IN (SELECT id FROM equipes WHERE gerente_id = :ru_uid2))', 'params' => $params];

        case 'produtos_vinculados':
            $params['ru_uid2'] = $uid;
            return ['sql' => $base . ' AND (u.id = :ru_uid OR u.equipe_id IN (
                    SELECT ee.equipe_id FROM equipe_empreendimentos ee
                    INNER JOIN usuario_empreendimentos ue ON ue.empreendimento_id = ee.empreendimento_id
                    WHERE ue.usuario_id = :ru_uid2
                ))', 'params' => $params];

        case 'propria_carteira':
        default:
            return ['sql' => $base . ' AND u.id = :ru_uid', 'params' => $params];
    }
}

/**
 * Compromissos da agenda que o usuário enxerga: os dos usuários do seu
 * recorte (rbac_usuarios_scope) — nunca confia em um usuario_id vindo do
 * navegador. Fragmento sobre o alias `ag` (agenda_compromissos).
 */
function rbac_agenda_scope(array $usuario): array
{
    $u = rbac_usuarios_scope($usuario);
    $l = rbac_leads_scope($usuario);
    // Compromisso ligado a um lead só aparece se o lead também estiver no
    // escopo: depois de uma transferência, a estrutura anterior deixa de
    // ver até o nome do cliente na agenda (seção 5).
    return [
        'sql' => 'ag.empresa_id = :ag_empresa_id AND ag.usuario_id IN (SELECT u.id FROM usuarios u WHERE ' . $u['sql'] . ')'
            . ' AND (ag.lead_id IS NULL OR ag.lead_id IN (SELECT leads.id FROM leads WHERE ' . $l['sql'] . '))',
        'params' => ['ag_empresa_id' => (int) $usuario['empresa_ativa_id']] + $u['params'] + $l['params'],
    ];
}

/**
 * Usuários ativos para quem o usuário logado pode atribuir/transferir
 * leads (destino de transferência e responsável na criação): o mesmo
 * recorte de pessoas, só ativos e com função que enxerga leads.
 */
function rbac_usuarios_atribuiveis(array $usuario): array
{
    $u = rbac_usuarios_scope($usuario);
    $stmt = db()->prepare('
        SELECT u.id, u.nome, u.equipe_id, equipes.nome AS equipe_nome, funcoes.nome AS funcao_nome, funcoes.escopo AS funcao_escopo
        FROM usuarios u
        INNER JOIN funcoes ON funcoes.id = u.funcao_id
        INNER JOIN funcao_permissoes fp ON fp.funcao_id = funcoes.id AND fp.permissao_chave = \'visualizar_leads\'
        LEFT JOIN equipes ON equipes.id = u.equipe_id
        WHERE u.ativo = 1 AND ' . $u['sql'] . '
        ORDER BY (funcoes.escopo = \'propria_carteira\') DESC, u.nome');
    $stmt->execute($u['params']);
    return $stmt->fetchAll();
}

/** Verdadeiro se o usuário pode ver/editar este lead específico. */
/**
 * Transferências que SAÍRAM da estrutura do usuário (alias `t`, tabela
 * lead_transferencias). Usa a estrutura gravada no momento da
 * transferência (de_equipe_id / de_superintendencia_id), então o gestor
 * antigo continua vendo a contagem agregada mesmo sem acesso ao lead.
 * Só para números agregados — nunca listar nomes ou telefones com isto.
 */
function rbac_transferencias_scope(array $usuario): array
{
    $params = ['rbac_t_empresa' => (int) $usuario['empresa_ativa_id']];
    $base = 't.empresa_id = :rbac_t_empresa';
    $uid = (int) $usuario['id'];
    switch ($usuario['escopo']) {
        case 'toda_empresa':
        case 'todos_produtos':
            return ['sql' => $base, 'params' => $params];
        case 'produtos_vinculados':
            $params['rbac_t_uid'] = $uid;
            return ['sql' => $base . ' AND t.empreendimento_id IN (SELECT empreendimento_id FROM usuario_empreendimentos WHERE usuario_id = :rbac_t_uid)', 'params' => $params];
        case 'superintendencia':
            $params['rbac_t_uid'] = $uid;
            return ['sql' => $base . ' AND t.de_superintendencia_id IN (SELECT id FROM superintendencias WHERE superintendente_id = :rbac_t_uid)', 'params' => $params];
        case 'equipe':
            $params['rbac_t_uid'] = $uid;
            return ['sql' => $base . ' AND t.de_equipe_id IN (SELECT id FROM equipes WHERE gerente_id = :rbac_t_uid)', 'params' => $params];
        default:
            $params['rbac_t_uid'] = $uid;
            return ['sql' => $base . ' AND t.de_usuario_id = :rbac_t_uid', 'params' => $params];
    }
}

function rbac_pode_ver_lead(array $usuario, int $leadId): bool
{
    if (!empty($usuario['eh_superadmin'])) {
        return false; // Superadmin não acessa leads de empresas (ver seção 4 do prompt mestre).
    }
    $scope = rbac_leads_scope($usuario);
    $sql = 'SELECT 1 FROM leads WHERE leads.id = :lead_id AND ' . $scope['sql'] . ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge(['lead_id' => $leadId], $scope['params']));
    return (bool) $stmt->fetch();
}

/**
 * Verdadeiro se a função do usuário tem esta permissão (ver tabela
 * `permissoes` / sql/migration_002_funcoes_permissoes.sql). Checada
 * tanto para esconder os controles de edição na página quanto, de
 * forma independente, dentro de cada endpoint de escrita em api/
 * (nunca confiar só em esconder botão).
 */
function usuario_tem_permissao(array $usuario, string $permissao): bool
{
    if (!empty($usuario['eh_superadmin'])) {
        return true;
    }
    return in_array($permissao, $usuario['permissoes'] ?? [], true);
}

/** Rótulo legível do escopo, para a UI (tela "Usuários e acessos"). */
function rbac_rotulo_escopo(string $escopo): string
{
    return funcoes_rotulos_escopo()[$escopo] ?? $escopo;
}
