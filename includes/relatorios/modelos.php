<?php
/**
 * Modelos de relatório (Etapa 6): conjuntos prontos de seções da Central
 * (Executivo Mensal, Prestação de Contas, ...) e modelos personalizados
 * salvos pelo usuário (migração 016). Um modelo gera UM arquivo (PDF ou
 * Excel) com todas as seções, cada uma calculada pelas mesmas funções da tela
 * e com a permissão de quem gera (seção sem acesso é pulada, com aviso).
 */
require_once __DIR__ . '/arquivos.php';
require_once __DIR__ . '/pdf_relatorio.php';

/** Modelos prontos: chave => [nome, descrição, seções [[rel, params]], período padrão]. */
function relmod_prontos(): array
{
    return [
        'executivo_mensal' => ['Executivo Mensal', 'Visão geral do mês: indicadores com comparação, origens e custos, visitas e vendas, perdas.',
            [['executiva', []], ['origens', ['agrupar' => 'origem']], ['visitas', ['agrupar' => 'empreendimento']], ['perdas', ['agrupar' => 'equipe']]], 'mes_anterior'],
        'prestacao_contas' => ['Prestação de Contas', 'Para o incorporador ou a diretoria: resultados, investimento e custos por resultado, funil e visitas por empreendimento.',
            [['executiva', []], ['origens', ['agrupar' => 'origem']], ['funil', []], ['visitas', ['agrupar' => 'empreendimento']]], 'mes_anterior'],
        'solicitacao_verba' => ['Solicitação de Verba', 'Base para pedir verba de mídia: leads, contato e contratos por origem e campanha (UTM), com custos onde houver investimento lançado.',
            [['origens', ['agrupar' => 'origem']], ['origens', ['agrupar' => 'utm_campaign']], ['executiva', []]], 'trimestre'],
        'empreendimento' => ['Empreendimento', 'Um empreendimento em detalhe (escolha o empreendimento nos filtros): visão geral, funil, origens e visitas.',
            [['executiva', []], ['funil', []], ['origens', ['agrupar' => 'origem']], ['visitas', ['agrupar' => 'corretor']]], 'mes_anterior'],
        'produtividade' => ['Produtividade', 'Corretores lado a lado (ranking por taxa de contato efetivo), visitas e perdas por corretor.',
            [['gestao', ['agrupar' => 'corretor', 'criterio' => 'taxa_contato_efetivo']], ['visitas', ['agrupar' => 'corretor']], ['perdas', ['agrupar' => 'corretor']]], 'mes_anterior'],
        'superintendencia' => ['Superintendência', 'Equipes da superintendência (escolha nos filtros): comparativo, perdas e funil.',
            [['gestao', ['agrupar' => 'equipe', 'criterio' => 'taxa_contato_efetivo']], ['perdas', ['agrupar' => 'equipe']], ['funil', []]], 'mes_anterior'],
        'marketing' => ['Marketing', 'Origens, campanhas (UTM) e plantões: de onde vêm os leads e o que acontece com eles.',
            [['origens', ['agrupar' => 'origem']], ['origens', ['agrupar' => 'utm_source']], ['origens', ['agrupar' => 'utm_campaign']], ['plantoes', []]], 'mes_anterior'],
        'perdas' => ['Perdas', 'Perdas por inatividade e transferências por equipe e corretor, com o destino dos leads.',
            [['perdas', ['agrupar' => 'equipe']], ['perdas', ['agrupar' => 'corretor']]], 'mes_anterior'],
    ];
}

/** Seções que podem entrar num modelo personalizado (as que não exigem escolha extra). */
function relmod_secoes_livres(): array
{
    return array_diff_key(relui_relatorios(), ['corretor' => 1, 'empreendimentos' => 1]);
}

function relmod_instalados(): bool
{
    return modulo_tabela_existe('relatorio_modelos');
}

function relmod_meus(array $usuario): array
{
    if (!relmod_instalados()) {
        return [];
    }
    $stmt = db()->prepare('SELECT * FROM relatorio_modelos WHERE usuario_id = :u AND empresa_id = :e ORDER BY nome');
    $stmt->execute(['u' => $usuario['id'], 'e' => $usuario['empresa_ativa_id']]);
    return $stmt->fetchAll();
}

function relmod_salvar(array $usuario, string $nome, array $secoes, string $filtros): array
{
    $nome = trim(preg_replace('/\s+/', ' ', $nome));
    if (mb_strlen($nome) < 2 || mb_strlen($nome) > 80) {
        return ['ok' => false, 'erro' => 'Dê um nome de 2 a 80 caracteres.'];
    }
    $secoes = array_values(array_unique(array_filter($secoes, fn ($s) => isset(relmod_secoes_livres()[$s]))));
    if (!$secoes) {
        return ['ok' => false, 'erro' => 'Escolha pelo menos uma seção.'];
    }
    if (count($secoes) > 8) {
        return ['ok' => false, 'erro' => 'No máximo 8 seções por modelo.'];
    }
    if ((int) db()->query('SELECT COUNT(*) FROM relatorio_modelos WHERE usuario_id = ' . (int) $usuario['id'])->fetchColumn() >= 30) {
        return ['ok' => false, 'erro' => 'Limite de 30 modelos. Exclua algum antes.'];
    }
    db()->prepare('INSERT INTO relatorio_modelos (empresa_id, usuario_id, nome, secoes, filtros) VALUES (:e, :u, :n, :s, :f)
                   ON DUPLICATE KEY UPDATE secoes = VALUES(secoes), filtros = VALUES(filtros)')
        ->execute(['e' => $usuario['empresa_ativa_id'], 'u' => $usuario['id'], 'n' => $nome, 's' => implode(',', $secoes), 'f' => mb_substr(relui_filtros_limpos($filtros), 0, 2000)]);
    return ['ok' => true];
}

function relmod_excluir(array $usuario, int $id): bool
{
    $stmt = db()->prepare('DELETE FROM relatorio_modelos WHERE id = :id AND usuario_id = :u AND empresa_id = :e');
    $stmt->execute(['id' => $id, 'u' => $usuario['id'], 'e' => $usuario['empresa_ativa_id']]);
    return $stmt->rowCount() === 1;
}

/**
 * Resolve um modelo: 'p:chave' (pronto) ou 'u:id' (do usuário). Retorna
 * ['nome', 'secoes' => [[rel, params]], 'filtros' => []] ou null.
 */
function relmod_carregar(array $usuario, string $chave): ?array
{
    if (str_starts_with($chave, 'p:') && isset(relmod_prontos()[substr($chave, 2)])) {
        [$nome, , $secoes, $periodo] = relmod_prontos()[substr($chave, 2)];
        return ['nome' => $nome, 'secoes' => $secoes, 'filtros' => ['periodo' => $periodo]];
    }
    if (str_starts_with($chave, 'u:') && relmod_instalados()) {
        $stmt = db()->prepare('SELECT * FROM relatorio_modelos WHERE id = :id AND usuario_id = :u AND empresa_id = :e');
        $stmt->execute(['id' => (int) substr($chave, 2), 'u' => $usuario['id'], 'e' => $usuario['empresa_ativa_id']]);
        if ($m = $stmt->fetch()) {
            parse_str($m['filtros'], $f);
            return ['nome' => $m['nome'], 'secoes' => array_map(fn ($s) => [$s, []], explode(',', $m['secoes'])), 'filtros' => $f];
        }
    }
    return null;
}

/**
 * Dados de todas as seções do modelo, com os filtros escolhidos na hora
 * ($get) por cima dos do modelo. Seção sem acesso vira aviso.
 */
function relmod_dados(array $usuario, array $modelo, array $get, string $nivel = 'completo'): array
{
    $base = array_merge($modelo['filtros'], array_filter($get, fn ($v) => $v !== '' && $v !== null && $v !== []));
    $secoes = [];
    $avisos = [];
    foreach ($modelo['secoes'] as [$rel, $params]) {
        if (!relui_pode($usuario, $rel)) {
            $avisos[] = 'Seção "' . (relui_relatorios()[$rel][0] ?? $rel) . '" omitida: sua função não tem acesso.';
            continue;
        }
        $d = relexp_dados($usuario, $rel, array_merge($base, $params), $nivel);
        if (!$d['ok']) {
            return ['ok' => false, 'erro' => $d['erro']];
        }
        if (isset($params['agrupar'])) {
            $d['titulo'] .= ' — por ' . mb_strtolower(['origem' => 'origem', 'utm_source' => 'utm_source', 'utm_campaign' => 'utm_campaign', 'canal' => 'grupo de origem',
                'corretor' => 'corretor', 'equipe' => 'equipe', 'gerente' => 'gerente', 'superintendencia' => 'superintendência', 'empreendimento' => 'empreendimento'][$params['agrupar']] ?? $params['agrupar']);
        }
        $secoes[] = $d;
    }
    if (!$secoes) {
        return ['ok' => false, 'erro' => 'Nenhuma seção deste modelo está disponível para a sua função.'];
    }
    $secoes[0]['notas'] = array_merge($avisos, $secoes[0]['notas']);
    return ['ok' => true, 'secoes' => $secoes];
}

/** Gera o arquivo do modelo (pdf | xlsx) em $destino. */
function relmod_gerar(array $usuario, string $chave, string $formato, array $get, string $destino): array
{
    if (!usuario_tem_permissao($usuario, 'exportar_relatorios')) {
        return ['ok' => false, 'erro' => 'Sua função não pode exportar relatórios.'];
    }
    $m = relmod_carregar($usuario, $chave);
    if (!$m) {
        return ['ok' => false, 'erro' => 'Modelo não encontrado.'];
    }
    if (!in_array($formato, ['pdf', 'xlsx'], true)) {
        return ['ok' => false, 'erro' => 'Formato inválido (PDF ou Excel).'];
    }
    $r = relmod_dados($usuario, $m, $get);
    if (!$r['ok']) {
        return $r;
    }
    $secoes = $r['secoes'];
    if ($formato === 'pdf') {
        $paginas = relpdf_documento($secoes, $m['nome'], $destino);
        $info = ['extensao' => 'pdf', 'mime' => 'application/pdf', 'paginas' => $paginas];
    } else {
        if (exportacao_formato() !== 'xlsx') {
            return ['ok' => false, 'erro' => 'Este servidor não gera Excel (.xlsx): use o PDF.'];
        }
        relarq_xlsx(relarq_abas_modelo($secoes, $m['nome']), $m['nome'], $destino);
        $info = ['extensao' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }
    return ['ok' => true, 'nome' => exportacao_nome_arquivo($m['nome'] . ' ' . $secoes[0]['periodo']) . '.' . $info['extensao'], 'secoes' => count($secoes),
            'linhas' => array_sum(array_map(fn ($d) => array_sum(array_map(fn ($t) => count($t['linhas']), $d['tabelas'])), $secoes)), 'modelo' => $m['nome']] + $info;
}

/** Abas do Excel de um modelo: Capa, uma aba por seção, Notas. */
function relarq_abas_modelo(array $secoes, string $titulo): array
{
    $d0 = $secoes[0];
    $capa = function () use ($d0, $secoes, $titulo) {
        yield [relarq_cel('WeMart Sales — ' . $titulo, 'titulo')];
        yield [];
        foreach ([['Empresa', $d0['empresa']], ['Período', $d0['periodo']], ['Comparado com', $d0['comparado'] ? $d0['comparado'] . ' (mesma duração)' : '—'],
                  ['Filtros', $d0['filtros'] ? implode(' · ', array_map(fn ($x) => $x[0] . ': ' . $x[1], $d0['filtros'])) : 'Nenhum — tudo o que o usuário pode ver'],
                  ['Gerado em', $d0['gerado_em']], ['Fuso horário', $d0['fuso']], ['Gerado por', $d0['usuario']]] as $p) {
            yield [relarq_cel($p[0], 'rotulo'), relarq_cel($p[1])];
        }
        yield [];
        yield [relarq_cel('Seções', 'titulo')];
        foreach ($secoes as $i => $s) {
            yield [relarq_cel(($i + 1) . '.'), relarq_cel($s['titulo'])];
        }
    };
    $abas = [['nome' => 'Capa', 'linhas' => $capa, 'larguras' => [22, 90]]];
    foreach ($secoes as $i => $d) {
        $fn = function () use ($d) {
            yield [relarq_cel($d['titulo'], 'titulo')];
            yield [];
            yield array_map(fn ($c) => relarq_cel($c, 'cabecalho'), ['Indicador', 'Valor', 'Unidade', 'Período anterior', 'Variação', 'Observação']);
            foreach ($d['indicadores'] as $ind) {
                $t = relexp_tipo_unidade($ind['unidade']);
                yield [relarq_cel($ind['nome']), relarq_cel($ind['valor'], $t), relarq_cel(['%' => '%', 'R$' => 'R$', 'min' => 'minutos', 'h' => 'horas'][$ind['unidade']] ?? 'quantidade'),
                       relarq_cel($ind['anterior'], $t), relarq_cel($ind['variacao'], 'dec1'), relarq_cel($ind['nota'])];
            }
            foreach ($d['tabelas'] as $tab) {
                yield [];
                yield [relarq_cel($tab['titulo'], 'titulo')];
                if (!$tab['linhas']) {
                    yield [relarq_cel('Nada no período para esta seleção.', 'nota')];
                    continue;
                }
                yield from relarq_linhas_tabela($tab);
            }
        };
        $abas[] = ['nome' => mb_substr(($i + 1) . '. ' . $d['titulo'], 0, 31), 'linhas' => $fn, 'larguras' => [38, 18, 16, 18, 14, 18, 16, 16, 16, 16, 16, 16]];
    }
    $notas = function () use ($secoes) {
        yield [relarq_cel('Notas', 'titulo')];
        $vistas = [];
        foreach ($secoes as $d) {
            foreach ($d['notas'] as $n) {
                if (!isset($vistas[$n])) {
                    $vistas[$n] = 1;
                    yield [relarq_cel($d['titulo']), relarq_cel($n)];
                }
            }
        }
        yield [];
        yield [relarq_cel('Definições (dicionário de indicadores)', 'titulo')];
        $defs = [];
        foreach ($secoes as $d) {
            foreach ($d['definicoes'] as $x) {
                $defs[$x[0]] = $x;
            }
        }
        foreach ($defs as $x) {
            yield [relarq_cel($x[0]), relarq_cel($x[1] . ' Cálculo: ' . $x[2])];
        }
    };
    $abas[] = ['nome' => 'Notas', 'linhas' => $notas, 'larguras' => [40, 110]];
    return $abas;
}
