<?php
/**
 * Funções (cargos configuráveis) — catálogo de permissões, mapeamento
 * das 7 funções padrão do sistema (mesmas 7 que existiam como cargo
 * fixo antes desta etapa) e rótulos de escopo para a UI.
 *
 * `funcoes_seed_padrao($empresaId)` é a ÚNICA fonte da lista de funções
 * padrão — usada tanto no backfill (sql/migration_002_backfill.php)
 * quanto na criação de uma nova empresa pelo Superadmin
 * (api/empresa_criar.php), para nunca haver duas listas divergentes.
 */

function funcoes_catalogo_permissoes(): array
{
    $stmt = db()->query('SELECT chave, rotulo, categoria FROM permissoes ORDER BY categoria, rotulo');
    return $stmt->fetchAll();
}

function funcoes_rotulos_escopo(): array
{
    return [
        'toda_empresa'        => 'Toda a empresa',
        'todos_produtos'      => 'Todos os produtos',
        'produtos_vinculados' => 'Produtos vinculados',
        'superintendencia'    => 'Sua superintendência',
        'equipe'              => 'Sua equipe',
        'propria_carteira'    => 'Sua própria carteira',
    ];
}

/**
 * As 7 funções padrão do sistema (equivalentes aos antigos 8 cargos
 * fixos, exceto master_wemart — que virou Superadmin, fora do sistema
 * de funções por empresa). Cada entrada: nome, escopo, permissões.
 */
function funcoes_definicao_padrao(): array
{
    return [
        'master_empresa'  => ['nome' => 'Master da empresa', 'escopo' => 'toda_empresa',        'permissoes' => ['visualizar_leads', 'criar_leads', 'editar_leads', 'excluir_leads', 'redistribuir_leads', 'gerenciar_roletas', 'gerenciar_equipes', 'gerenciar_empreendimentos', 'gerenciar_plantoes', 'exportar_relatorios', 'visualizar_indicadores', 'administrar_usuarios', 'configurar_funil', 'acessar_arquivos_leads', 'participar_roleta', 'gerenciar_integracoes', 'configurar_recuperacao', 'gerenciar_oferta_ativa', 'visualizar_auditoria', 'whatsapp_atender', 'whatsapp_configurar', 'gerenciar_jornadas', 'gerenciar_campanhas_wa']],
        'gp_lider'        => ['nome' => 'GP Líder',          'escopo' => 'todos_produtos',       'permissoes' => ['visualizar_leads', 'visualizar_indicadores', 'exportar_relatorios']],
        'gerente_geral'   => ['nome' => 'Gerente Geral',     'escopo' => 'todos_produtos',       'permissoes' => ['visualizar_leads', 'visualizar_indicadores', 'exportar_relatorios', 'gerenciar_equipes', 'gerenciar_oferta_ativa']],
        'gp_produto'      => ['nome' => 'GP do Produto',     'escopo' => 'produtos_vinculados',  'permissoes' => ['visualizar_leads', 'visualizar_indicadores']],
        'superintendente' => ['nome' => 'Superintendente',   'escopo' => 'superintendencia',     'permissoes' => ['visualizar_leads', 'visualizar_indicadores', 'redistribuir_leads', 'gerenciar_equipes', 'acessar_arquivos_leads', 'gerenciar_oferta_ativa', 'whatsapp_atender']],
        'gerente'         => ['nome' => 'Gerente',           'escopo' => 'equipe',               'permissoes' => ['visualizar_leads', 'criar_leads', 'editar_leads', 'redistribuir_leads', 'visualizar_indicadores', 'acessar_arquivos_leads', 'whatsapp_atender']],
        'corretor'        => ['nome' => 'Corretor',          'escopo' => 'propria_carteira',     'permissoes' => ['visualizar_leads', 'criar_leads', 'editar_leads', 'acessar_arquivos_leads', 'participar_roleta', 'whatsapp_atender']],
    ];
}

/**
 * Cria (se ainda não existir, pelo nome) as 7 funções padrão para uma
 * empresa, com suas permissões. Retorna [cargo_legado => funcao_id]
 * para quem precisar migrar usuários a partir do cargo antigo.
 */
function funcoes_seed_padrao(int $empresaId): array
{
    $mapa = [];
    foreach (funcoes_definicao_padrao() as $cargoLegado => $def) {
        $stmt = db()->prepare('SELECT id FROM funcoes WHERE empresa_id = :empresa_id AND nome = :nome');
        $stmt->execute(['empresa_id' => $empresaId, 'nome' => $def['nome']]);
        $funcaoId = $stmt->fetchColumn();

        if (!$funcaoId) {
            db()->prepare('INSERT INTO funcoes (empresa_id, nome, escopo, padrao_sistema) VALUES (:empresa_id, :nome, :escopo, 1)')
                ->execute(['empresa_id' => $empresaId, 'nome' => $def['nome'], 'escopo' => $def['escopo']]);
            $funcaoId = (int) db()->lastInsertId();
            foreach ($def['permissoes'] as $permissao) {
                db()->prepare('INSERT IGNORE INTO funcao_permissoes (funcao_id, permissao_chave) VALUES (:funcao_id, :permissao)')
                    ->execute(['funcao_id' => $funcaoId, 'permissao' => $permissao]);
            }
        }
        $mapa[$cargoLegado] = (int) $funcaoId;
    }
    return $mapa;
}

/**
 * Mapeia um escopo de volta para o cargo ENUM legado mais próximo —
 * só para manter a coluna usuarios.cargo (NOT NULL, mantida por
 * segurança de rollback) preenchida com algo plausível; o código novo
 * nunca lê usuarios.cargo para decidir permissão, só funcao_id/escopo.
 */
function escopo_cargo_legado(string $escopo): string
{
    return [
        'toda_empresa'        => 'master_empresa',
        'todos_produtos'      => 'gerente_geral',
        'produtos_vinculados' => 'gp_produto',
        'superintendencia'    => 'superintendente',
        'equipe'              => 'gerente',
        'propria_carteira'    => 'corretor',
    ][$escopo] ?? 'corretor';
}
