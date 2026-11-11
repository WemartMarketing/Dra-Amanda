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

    $base = 'leads.empresa_id = :rbac_empresa_id';
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

/** Verdadeiro se o usuário pode ver/editar este lead específico. */
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
