<?php
/**
 * RBAC — escopo de visibilidade de leads por cargo.
 *
 * Cada cargo definido em usuarios.cargo enxerga um subconjunto de
 * leads dentro da própria empresa (ver comentário acima da tabela
 * `usuarios` em sql/schema.sql). Esta é a ÚNICA função que decide esse
 * escopo — nenhuma página deve montar essa lógica por conta própria,
 * para não haver dois lugares com regras diferentes.
 *
 * Retorna um fragmento SQL (para usar depois de WHERE) e os parâmetros
 * nomeados correspondentes. Sempre inclui o filtro de empresa.
 */
function rbac_leads_scope(array $usuario): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $uid = (int) $usuario['id'];

    $base = 'leads.empresa_id = :rbac_empresa_id';
    $params = ['rbac_empresa_id' => $empresaId];

    switch ($usuario['cargo']) {
        case 'master_wemart':
        case 'master_empresa':
        case 'gp_lider':
        case 'gerente_geral':
            // Enxergam todos os produtos/leads da empresa ativa.
            return ['sql' => $base, 'params' => $params];

        case 'gp_produto':
            $params['rbac_uid'] = $uid;
            return [
                'sql' => $base . ' AND leads.empreendimento_id IN (
                    SELECT empreendimento_id FROM usuario_empreendimentos WHERE usuario_id = :rbac_uid
                )',
                'params' => $params,
            ];

        case 'superintendente':
            $params['rbac_uid'] = $uid;
            return [
                'sql' => $base . ' AND leads.equipe_id IN (
                    SELECT eq.id FROM equipes eq
                    INNER JOIN superintendencias s ON s.id = eq.superintendencia_id
                    WHERE s.superintendente_id = :rbac_uid
                )',
                'params' => $params,
            ];

        case 'gerente':
            $params['rbac_uid'] = $uid;
            return [
                'sql' => $base . ' AND leads.equipe_id IN (
                    SELECT id FROM equipes WHERE gerente_id = :rbac_uid
                )',
                'params' => $params,
            ];

        case 'corretor':
        default:
            $params['rbac_uid'] = $uid;
            return ['sql' => $base . ' AND leads.responsavel_id = :rbac_uid', 'params' => $params];
    }
}

/** Verdadeiro se o usuário pode ver/editar este lead específico. */
function rbac_pode_ver_lead(array $usuario, int $leadId): bool
{
    $scope = rbac_leads_scope($usuario);
    $sql = 'SELECT 1 FROM leads WHERE leads.id = :lead_id AND ' . $scope['sql'] . ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge(['lead_id' => $leadId], $scope['params']));
    return (bool) $stmt->fetch();
}

/**
 * Verdadeiro se o cargo pode gerenciar roletas/usuários/configurações da
 * empresa (telas Roletas e Usuários e acessos). Corretor nunca gerencia —
 * só enxerga essas telas em modo leitura. Checada tanto para esconder os
 * controles de edição na página quanto, de forma independente, dentro de
 * cada endpoint de escrita em api/ (nunca confiar só em esconder botão).
 */
function rbac_pode_gerenciar(array $usuario): bool
{
    return $usuario['cargo'] !== 'corretor';
}

/** Rótulos legíveis dos cargos, para a UI (ver tela "Usuários e acessos"). */
function rbac_rotulo_cargo(string $cargo): string
{
    return [
        'master_wemart'   => 'Master WeMart',
        'master_empresa'  => 'Master da empresa',
        'gp_lider'        => 'GP Líder',
        'gerente_geral'   => 'Gerente Geral',
        'gp_produto'      => 'GP do Produto',
        'superintendente' => 'Superintendente',
        'gerente'         => 'Gerente',
        'corretor'        => 'Corretor',
    ][$cargo] ?? $cargo;
}
