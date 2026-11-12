<?php
/**
 * POST /api/exportar.php — baixa um relatório em .xlsx (ou CSV, se o
 * servidor não tiver ZipArchive).
 *   relatorio: quebra | quebra_empreendimento | quebra_campanha | roleta |
 *              perdas | perdas_empreendimento | leads
 *   + os mesmos filtros da tela (período, empreendimento, equipe...) e,
 *   para "leads", os filtros da lista de leads.
 *
 * Mesmas verificações de toda escrita (sessão, CSRF, permissão
 * exportar_relatorios) e o mesmo escopo da tela: as linhas saem das MESMAS
 * funções que montam a tabela (includes/dashboard.php, leads_lista.php).
 * Cada exportação vira uma linha na auditoria (quem, o quê, filtros e
 * quantas linhas). Nada é guardado no servidor.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/exportacao.php';
require_once __DIR__ . '/../includes/auditoria.php';

$usuario = api_iniciar('exportar_relatorios');
$empresaId = (int) $usuario['empresa_ativa_id'];
$relatorio = (string) ($_POST['relatorio'] ?? '');
$f = dash_filtros($usuario, $_POST);
$periodoTxt = ' — ' . $f['rotulo'];

switch ($relatorio) {
    case 'quebra':
    case 'quebra_empreendimento':
    case 'quebra_campanha':
        $dim = $relatorio === 'quebra' ? (string) ($_POST['por'] ?? '') : substr($relatorio, 7);
        if (!array_key_exists($dim, dash_dimensoes())) {
            $dim = dash_dimensao_padrao($usuario);
        }
        $colunas = dash_colunas_quebra($dim);
        $linhas = dash_quebra($usuario, $f, $dim);
        if ($linhas) {
            $linhas[] = dash_totais($linhas);
        }
        $titulo = 'Produtividade por ' . mb_strtolower(dash_dimensoes()[$dim]);
        $filtrosAud = dash_filtros_publicos($f) + ['por' => $dim];
        break;

    case 'roleta':
        $colunas = dash_colunas_roleta();
        $linhas = dash_por_roleta($usuario, $f);
        $titulo = 'Resultados por roleta';
        $filtrosAud = dash_filtros_publicos($f);
        break;

    case 'perdas':
    case 'perdas_empreendimento':
        $dim = $relatorio === 'perdas' ? (string) ($_POST['perdas_por'] ?? '') : 'empreendimento';
        if (!in_array($dim, ['corretor', 'equipe', 'gerente', 'superintendencia', 'empreendimento'], true)) {
            $dim = 'corretor';
        }
        $colunas = dash_colunas_perdas($dim);
        $linhas = dash_perdas($usuario, $f, $dim);
        if ($linhas) {
            $t = ['nome' => 'Total'];
            foreach (['inatividade', 'redistribuidos', 'administrativas', 'mudanca_equipe', 'total'] as $c) {
                $t[$c] = array_sum(array_column($linhas, $c));
            }
            $linhas[] = $t;
        }
        $titulo = 'Perdas por ' . mb_strtolower(dash_dimensoes()[$dim]);
        $filtrosAud = dash_filtros_publicos($f) + ['perdas_por' => $dim];
        break;

    case 'leads':
        require_once __DIR__ . '/../includes/leads_lista.php';
        if (!usuario_tem_permissao($usuario, 'visualizar_leads')) {
            api_erro('Sua função não pode ver leads.', 403);
        }
        if (empty($_POST['ciente_lgpd'])) {
            api_erro('Confirme que vai usar os dados pessoais só para o atendimento.', 422);
        }
        $lf = leads_filtros_da_requisicao($_POST);
        $total = leads_contar($usuario, $lf);
        if ($total > exportacao_limite()) {
            api_erro('A lista tem ' . dash_num($total) . ' leads — acima do limite de ' . dash_num(exportacao_limite()) . ' por planilha. Use os filtros (período, etapa, empreendimento) para dividir.', 422);
        }
        [$where, $params] = leads_where($usuario, $lf);
        $stmt = db()->prepare('SELECT leads.id, leads.nome, leads.telefone, leads.email, empreendimentos.nome AS empreendimento_nome, leads.origem, leads.campanha,
                funil_etapas.nome AS etapa_nome, leads.etapa, usuarios.nome AS responsavel_nome, leads.prioridade, leads.interesse,
                leads.criado_em, leads.primeiro_contato_em, leads.ultima_atividade_em, leads.data_retorno
            ' . leads_from() . ' WHERE ' . $where . ' ORDER BY leads.criado_em DESC LIMIT ' . exportacao_limite());
        $stmt->execute($params);
        $origens = lead_origens();
        $prioridades = lead_prioridades();
        $linhas = (function () use ($stmt, $origens, $prioridades) {
            while ($l = $stmt->fetch()) {
                $l['origem'] = $origens[$l['origem']] ?? $l['origem'];
                $l['prioridade'] = $prioridades[$l['prioridade']] ?? $l['prioridade'];
                $l['etapa_nome'] = $l['etapa_nome'] ?: lead_etapa_rotulo($l['etapa']);
                yield $l;
            }
        })();
        $colunas = [
            'id' => ['Código', 'int'], 'nome' => ['Nome', 'texto'], 'telefone' => ['Telefone', 'texto'], 'email' => ['E-mail', 'texto'],
            'empreendimento_nome' => ['Empreendimento', 'texto'], 'origem' => ['Origem', 'texto'], 'campanha' => ['Campanha', 'texto'],
            'etapa_nome' => ['Etapa', 'texto'], 'responsavel_nome' => ['Responsável', 'texto'], 'prioridade' => ['Prioridade', 'texto'],
            'interesse' => ['Interesse', 'texto'], 'criado_em' => ['Entrada', 'datahora'], 'primeiro_contato_em' => ['Primeiro contato', 'datahora'],
            'ultima_atividade_em' => ['Última atividade', 'datahora'], 'data_retorno' => ['Próximo contato', 'data'],
        ];
        $titulo = 'Leads';
        $periodoTxt = '';
        $filtrosAud = array_filter($lf, fn ($v) => $v !== null && $v !== '');
        break;

    case 'automacao_indicadores':
    case 'automacao_empreendimento':
    case 'automacao_equipe':
    case 'automacao_jornada':
    case 'automacao_campanha':
        require_once __DIR__ . '/../includes/relatorio_automacao.php';
        $ra = relatorio_automacao($usuario, $f);
        $tab = substr($relatorio, 10);
        if ($tab === 'indicadores') {
            $colunas = ['indicador' => ['Indicador', 'texto'], 'valor' => ['Valor', 'texto']];
            $linhas = array_map(fn ($l) => ['indicador' => $l[0], 'valor' => $l[1]], relaut_indicadores_linhas($ra['indicadores']));
        } else {
            $colunas = relaut_colunas($tab);
            $linhas = $ra['por_' . $tab];
        }
        $titulo = 'Automações — ' . ['indicadores' => 'indicadores', 'empreendimento' => 'por empreendimento', 'equipe' => 'por equipe',
                                     'jornada' => 'por jornada', 'campanha' => 'por campanha'][$tab];
        $filtrosAud = dash_filtros_publicos($f);
        break;

    default:
        api_erro('Relatório desconhecido.', 422);
}

if (is_array($linhas) && count($linhas) > exportacao_limite()) {
    api_erro('Resultado acima do limite de ' . dash_num(exportacao_limite()) . ' linhas. Use os filtros para dividir.', 422);
}

// Conta as linhas enquanto envia (a lista de leads chega como gerador).
$contador = 0;
$fluxo = (function () use ($linhas, &$contador) {
    foreach ($linhas as $l) {
        $contador++;
        yield $l;
    }
})();
header_remove('Content-Type');
$formato = exportacao_formato();
exportacao_enviar($titulo . $periodoTxt, $colunas, $fluxo);
auditoria_registrar($empresaId, (int) $usuario['id'], 'relatorio.exportado', 'relatorio', null, [
    'relatorio' => $relatorio, 'titulo' => $titulo, 'formato' => $formato, 'linhas' => $contador, 'filtros' => $filtrosAud,
    'dados_pessoais' => $relatorio === 'leads',
]);
