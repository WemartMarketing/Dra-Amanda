<?php
/**
 * Inteligência de Vendas — verificação de integridade (SÓ LEITURA, só
 * contagens). Usada no painel de Qualidade dos Dados e em
 * ferramentas/verificar_vendas.php.
 * Itens: ['rotulo', 'valor', 'nivel' => ok|info|alerta|critico, 'nota'].
 */
require_once __DIR__ . '/cruzamento.php';

function vendas_integridade(int $empresaId): array
{
    if (!vendas_cruzamento_instalado()) {
        return [['rotulo' => 'Inteligência de Vendas instalada', 'valor' => 'não', 'nivel' => 'info', 'nota' => 'Rode as migrações 017, 018 e 019.']];
    }
    $q = fn (string $sql) => (int) db()->query($sql)->fetchColumn();
    $e = $empresaId;
    $item = fn (string $rot, int $n, string $nivelSeHouver, string $nota) => ['rotulo' => $rot, 'valor' => $n, 'nivel' => $n ? $nivelSeHouver : 'ok', 'nota' => $n ? $nota : ''];
    $out = [];
    $out[] = ['rotulo' => 'Vendas importadas', 'valor' => $q("SELECT COUNT(*) FROM vendas_externas WHERE empresa_id = $e"), 'nivel' => 'info', 'nota' => ''];
    $out[] = $item('Vendas ainda não cruzadas', $q("SELECT COUNT(*) FROM vendas_externas v LEFT JOIN vendas_correspondencias c ON c.venda_id = v.id WHERE v.empresa_id = $e AND c.id IS NULL"),
        'alerta', 'Use "Recalcular cruzamento" na Conciliação.');
    $out[] = $item('Correspondências com lead de OUTRA empresa', $q("SELECT COUNT(*) FROM vendas_correspondencia_leads cl JOIN vendas_externas v ON v.id = cl.venda_id JOIN leads l ON l.id = cl.lead_id
        WHERE v.empresa_id = $e AND l.empresa_id <> v.empresa_id"), 'critico', 'Isolamento violado: recalcule o cruzamento e avise o suporte.');
    $out[] = $item('Vínculos com lead excluído (LGPD)', $q("SELECT COUNT(*) FROM vendas_correspondencia_leads cl JOIN vendas_externas v ON v.id = cl.venda_id JOIN leads l ON l.id = cl.lead_id
        WHERE v.empresa_id = $e AND l.excluido_em IS NOT NULL AND cl.papel = 'vinculado'"), 'alerta', 'O lead foi excluído depois do cruzamento; a Jornada já o ignora. Recalcule para limpar.');
    $out[] = $item('Vendas "vinculadas" sem lead vinculado', $q("SELECT COUNT(*) FROM vendas_correspondencias c JOIN vendas_externas v ON v.id = c.venda_id WHERE v.empresa_id = $e AND c.vinculada = 1
        AND NOT EXISTS (SELECT 1 FROM vendas_correspondencia_leads cl WHERE cl.venda_id = c.venda_id AND cl.papel = 'vinculado')"), 'critico', 'Inconsistência: recalcule o cruzamento.');
    $out[] = $item('Leads vinculados em venda NÃO vinculada (automática)', $q("SELECT COUNT(*) FROM vendas_correspondencias c JOIN vendas_externas v ON v.id = c.venda_id WHERE v.empresa_id = $e AND c.vinculada = 0
        AND c.decisao = 'automatica' AND EXISTS (SELECT 1 FROM vendas_correspondencia_leads cl WHERE cl.venda_id = c.venda_id AND cl.papel = 'vinculado')"), 'critico', 'Inconsistência: recalcule o cruzamento.');
    $out[] = $item('Decisões humanas sem registro de revisão', $q("SELECT COUNT(*) FROM vendas_correspondencias c JOIN vendas_externas v ON v.id = c.venda_id WHERE v.empresa_id = $e AND c.decisao <> 'automatica'
        AND NOT EXISTS (SELECT 1 FROM vendas_revisoes r WHERE r.venda_id = c.venda_id)"), 'critico', 'Toda decisão humana deve ter justificativa no histórico.');
    $out[] = $item('Ambíguas aguardando revisão', $q("SELECT COUNT(*) FROM vendas_correspondencias c JOIN vendas_externas v ON v.id = c.venda_id WHERE v.empresa_id = $e AND c.status = 'ambigua' AND c.decisao = 'automatica'"),
        'alerta', 'Revise na Conciliação: só contam como encontradas depois da revisão.');
    $out[] = $item('Cruzamentos com regras antigas', $q("SELECT COUNT(*) FROM vendas_correspondencias c JOIN vendas_externas v ON v.id = c.venda_id WHERE v.empresa_id = $e AND c.regras_versao <> " . db()->quote(VENDAS_REGRAS_VERSAO)),
        'alerta', 'Recalcule o cruzamento para usar as regras ' . VENDAS_REGRAS_VERSAO . '.');
    $out[] = $item('Lotes com linhas faltando no registro', $q("SELECT COUNT(*) FROM vendas_lotes l WHERE l.empresa_id = $e AND l.status = 'concluido'
        AND l.linhas_lidas <> (SELECT COUNT(*) FROM vendas_lote_linhas x WHERE x.lote_id = l.id)"), 'critico', 'O resultado por linha não bate com as linhas lidas.');
    $out[] = $item('Originais vencidos ainda guardados', $q("SELECT COUNT(*) FROM vendas_lotes WHERE empresa_id = $e AND arquivo_guardado = 1 AND descartar_arquivo_em < NOW() - INTERVAL 1 DAY"),
        'alerta', 'O descarte roda no cron (cron/rotinas.php). Confira se o cron está ativo.');
    $out[] = $item('Lotes que falharam', $q("SELECT COUNT(*) FROM vendas_lotes WHERE empresa_id = $e AND status = 'falhou'"), 'info', 'Nenhuma venda desses lotes foi gravada; veja o motivo na página do lote.');
    $out[] = $item('Vendas com empreendimento sem equivalência', $q("SELECT COUNT(*) FROM vendas_externas WHERE empresa_id = $e AND empreendimento_id IS NULL AND empreendimento_texto IS NOT NULL"),
        'alerta', 'Cadastre as equivalências: sem elas, "entrada × compra" fica indisponível.');
    $out[] = $item('Vendas com corretor sem equivalência (pelo id do corretor)', $q("SELECT COUNT(*) FROM vendas_externas v WHERE v.empresa_id = $e AND NOT EXISTS (SELECT 1 FROM vendas_equivalencias q WHERE q.empresa_id = v.empresa_id
        AND q.tipo = 'corretor' AND q.valor_origem = COALESCE(v.corretor_id_origem, ''))"), 'alerta', 'Sem equivalência, a classificação fica "responsável não identificado".');
    $out[] = $item('Índice de contatos com lead excluído ou de outra empresa', $q("SELECT COUNT(*) FROM contato_identificadores ci JOIN leads l ON l.id = ci.lead_id WHERE ci.empresa_id = $e
        AND (l.excluido_em IS NOT NULL OR l.empresa_id <> ci.empresa_id)"), 'critico', 'O índice é reconstruído a cada cruzamento: recalcule.');
    if (vendas_coluna_anonimizada()) {
        $out[] = $item('Vendas anonimizadas que ainda têm dado pessoal', $q("SELECT COUNT(*) FROM vendas_externas WHERE empresa_id = $e AND anonimizada_em IS NOT NULL
            AND (cliente_nome IS NOT NULL OR telefone1 IS NOT NULL OR telefone2 IS NOT NULL OR email IS NOT NULL OR cpf_hash IS NOT NULL)"), 'critico', 'Anonimização incompleta.');
    }
    return $out;
}
