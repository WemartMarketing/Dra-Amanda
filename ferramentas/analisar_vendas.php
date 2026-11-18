<?php
/**
 * Inteligência de Vendas — Fase 1: diagnóstico de uma planilha de vendas
 * do sistema comercial (HM) e PRÉVIA do cruzamento com o CRM. SÓ LEITURA.
 *
 *   php -q ferramentas/analisar_vendas.php caminho/vendas.xlsx
 *   php -q ferramentas/analisar_vendas.php caminho/vendas.xlsx --empresa=3
 *   php -q ferramentas/analisar_vendas.php caminho/vendas.csv  --empresa=3 --json
 *
 * Sem --empresa: só valida a planilha (colunas, datas, telefones, e-mails,
 * duplicidades). Com --empresa: também mostra quantas vendas encontram
 * leads dessa empresa (regras de correspondência v1).
 *
 * Imprime SÓ contagens e nomes de empreendimentos/mídias: nenhum nome de
 * cliente, telefone, e-mail ou CPF. Não grava nada no banco nem copia o
 * arquivo. Apague a planilha do servidor depois de usar.
 */
require_once __DIR__ . '/../includes/terminal.php';
terminal_exigir();
require_once __DIR__ . '/../includes/vendas/indice_crm.php';

// getopt() para no 1º argumento que não é opção; lê à mão (arquivo em qualquer posição).
$opts = [];
$arquivo = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--empresa=(\d+)$/', $a, $mm)) {
        $opts['empresa'] = $mm[1];
    } elseif ($a === '--json') {
        $opts['json'] = true;
    } elseif ($a !== '' && $a[0] !== '-') {
        $arquivo = $a;
    } else {
        fwrite(STDERR, "Opção desconhecida: $a\n");
        exit(1);
    }
}
if (!$arquivo || !is_file($arquivo)) {
    fwrite(STDERR, "Uso: php -q ferramentas/analisar_vendas.php arquivo.xlsx|arquivo.csv [--empresa=ID] [--json]\n");
    exit(1);
}
$ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'csv'], true)) {
    fwrite(STDERR, "Envie .xlsx ou .csv.\n");
    exit(1);
}
$lido = $ext === 'xlsx' ? importacao_ler_xlsx($arquivo, 200) : importacao_ler_csv($arquivo);
if (!$lido['ok']) {
    fwrite(STDERR, $lido['erro'] . "\n");
    exit(1);
}
$linhas = array_values(array_filter($lido['linhas'], fn ($l) => implode('', array_map(fn ($v) => trim((string) $v), $l)) !== ''));
$cab = array_shift($linhas);
$data1904 = $ext === 'xlsx' && vendas_xlsx_data1904($arquivo);
$m = vendas_mapear_cabecalho($cab);
$campos = vendas_campos();

$S = [
    'arquivo' => ['linhas' => count($linhas), 'colunas' => count($cab), 'calendario_1904' => $data1904],
    'colunas' => [
        'reconhecidas' => array_map(fn ($i) => (string) $cab[$i], $m['mapa']),
        'faltando' => array_values(array_diff(['venda_id_origem', 'venda_rm', 'data_venda', 'empreendimento', 'valor', 'celular', 'email', 'corretor_nome', 'midia', 'situacao'], array_keys($m['mapa']))),
        'nao_importadas_lgpd' => array_values($m['minimizadas']),
        'extras_guardadas_como_metadado' => array_values($m['extras']),
        'cabecalho_repetido' => $m['duplicadas'],
    ],
];

$cont = ['problemas' => [], 'alertas' => [], 'situacao' => [], 'midia' => [], 'empreendimento' => [], 'corretor_prefixo' => [], 'tel_invalido' => [],
         'datas_venda' => [], 'cpf' => ['valido' => 0, 'invalido' => 0, 'vazio' => 0, 'zeros_recuperados' => 0]];
$ids = $rms = [];
$dupId = $dupRm = 0;
$valorTotal = 0.0;
$pessoas = [];
$vendas = [];
$hora = 0;
foreach ($linhas as $n => $l) {
    $v = vendas_normalizar_linha($l, $m['mapa'], $data1904);
    $vendas[$n] = $v;
    foreach ($v['problemas'] as $k => $_) {
        $cont['problemas'][$k] = ($cont['problemas'][$k] ?? 0) + 1;
    }
    foreach ($v['alertas'] as $k => $txt) {
        $chave = $k === 'data_cadastro' ? 'data_cadastro: ' . $txt : $k;
        $cont['alertas'][$chave] = ($cont['alertas'][$chave] ?? 0) + 1;
    }
    foreach ($v['telefones_invalidos'] as $t) {
        $cont['tel_invalido'][$t] = ($cont['tel_invalido'][$t] ?? 0) + 1;
    }
    $cont['situacao'][$v['situacao'] ?? '(vazia)'] = ($cont['situacao'][$v['situacao'] ?? '(vazia)'] ?? 0) + 1;
    $cont['midia'][$v['midia'] ?: '(vazia)'] = ($cont['midia'][$v['midia'] ?: '(vazia)'] ?? 0) + 1;
    $cont['empreendimento'][$v['empreendimento'] ?: '(vazio)'] = ($cont['empreendimento'][$v['empreendimento'] ?: '(vazio)'] ?? 0) + 1;
    $pref = preg_match('/^([^_]+)_/', $v['corretor_nome'], $mm) ? $mm[1] . '_…' : '(sem prefixo)';
    $cont['corretor_prefixo'][$pref] = ($cont['corretor_prefixo'][$pref] ?? 0) + 1;
    if ($v['data_venda']) {
        $cont['datas_venda'][] = $v['data_venda'];
        $hora += $v['data_venda_so_data'] ? 0 : 1;
    }
    $cont['cpf'][$v['cpf_problema'] === null ? 'valido' : $v['cpf_problema']]++;
    $cont['cpf']['zeros_recuperados'] += $v['cpf_zeros_recuperados'] ? 1 : 0;
    if ($v['venda_id_origem'] !== null) {
        isset($ids[$v['venda_id_origem']]) ? $dupId++ : $ids[$v['venda_id_origem']] = true;
    }
    if ($v['venda_rm'] !== null) {
        isset($rms[$v['venda_rm']]) ? $dupRm++ : $rms[$v['venda_rm']] = true;
    }
    $valorTotal += (float) $v['valor'];
    $chavePessoa = $v['cpf'] ?? $v['email'] ?? ($v['telefones'][0] ?? 'linha' . $n);
    $pessoas[$chavePessoa] = ($pessoas[$chavePessoa] ?? 0) + 1;
}
arsort($cont['midia']);
arsort($cont['empreendimento']);
arsort($cont['corretor_prefixo']);
$S['validacao'] = [
    'ids_unicos' => count($ids), 'id_repetido_no_arquivo' => $dupId, 'venda_rm_unicos' => count($rms), 'venda_rm_repetido_no_arquivo' => $dupRm,
    'data_venda_de' => $cont['datas_venda'] ? substr(min($cont['datas_venda']), 0, 10) : null,
    'data_venda_ate' => $cont['datas_venda'] ? substr(max($cont['datas_venda']), 0, 10) : null,
    'data_venda_com_hora' => $hora,
    'problemas_que_impedem' => $cont['problemas'], 'alertas' => $cont['alertas'], 'telefones_fora_do_padrao' => $cont['tel_invalido'], 'cpf' => $cont['cpf'],
    'situacao' => $cont['situacao'], 'midia' => $cont['midia'], 'corretor_prefixo' => $cont['corretor_prefixo'],
    'compradores_distintos' => count($pessoas), 'compradores_com_mais_de_uma_venda' => count(array_filter($pessoas, fn ($q) => $q > 1)),
    'valor_total' => round($valorTotal, 2),
];
$S['empreendimentos'] = $cont['empreendimento'];

// ------------------------------------------------------------------ prévia do cruzamento
if (!empty($opts['empresa'])) {
    $empresaId = (int) $opts['empresa'];
    $st = db()->prepare('SELECT id, nome FROM empresas WHERE id = :id');
    $st->execute(['id' => $empresaId]);
    $empresa = $st->fetch();
    if (!$empresa) {
        fwrite(STDERR, "Empresa $empresaId não encontrada.\n");
        exit(1);
    }
    // Equivalência de empreendimentos: só SUGESTÃO (igual sem acento/caixa); o resto vai para revisão.
    $emps = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :e');
    $emps->execute(['e' => $empresaId]);
    $porNome = [];
    foreach ($emps->fetchAll() as $e) {
        $porNome[vendas_nome_comparavel($e['nome'])][] = (int) $e['id'];
    }
    $equiv = [];
    foreach (array_keys($cont['empreendimento']) as $nome) {
        $ids1 = $porNome[vendas_nome_comparavel($nome)] ?? [];
        $equiv[$nome] = count($ids1) === 1 ? 'mesmo nome no CRM (confirmar)' : (count($ids1) > 1 ? 'mais de um no CRM com esse nome' : 'sem equivalente — cadastrar equivalência');
    }
    $S['equivalencia_empreendimentos'] = $equiv;
    $empIdDoNome = fn (string $nome) => count($porNome[vendas_nome_comparavel($nome)] ?? []) === 1 ? $porNome[vendas_nome_comparavel($nome)][0] : null;

    $idx = vendas_indice_crm($empresaId);
    $X = ['status' => array_fill_keys(array_keys(vendas_status_rotulos()), 0), 'motivos_ambigua' => [], 'cronologia' => [], 'por' => [],
          'com_varios_leads' => 0, 'mesmo_empreendimento' => 0, 'outro_empreendimento' => 0, 'empreendimento_sem_equivalencia' => 0,
          'com_oferta_ativa' => 0, 'com_transferencia' => 0, 'com_remarketing_antes' => 0, 'valor_vinculado' => 0.0, 'dias' => []];
    $temTransf = modulo_tabela_existe('lead_transferencias');
    $temCamp = modulo_tabela_existe('whatsapp_campanha_destinatarios');
    foreach ($vendas as $v) {
        [$pt, $pe, $pv] = vendas_candidatos($idx, $v);
        $r = vendas_classificar($pt, $pe, $v['data_venda'], $v['email'], $pv);
        $X['status'][$r['status']]++;
        if ($r['status'] === 'ambigua') {
            $X['motivos_ambigua'][$r['motivo']] = ($X['motivos_ambigua'][$r['motivo']] ?? 0) + 1;
            continue;
        }
        if (!in_array($r['status'], ['forte', 'telefone', 'email'], true)) {
            continue;
        }
        $X['cronologia'][$r['cronologia']] = ($X['cronologia'][$r['cronologia']] ?? 0) + 1;
        $X['valor_vinculado'] += (float) $v['valor'];
        $X['com_varios_leads'] += count($r['lead_ids']) > 1 ? 1 : 0;
        $leads = array_intersect_key($idx['leads'], array_flip($r['lead_ids']));
        uasort($leads, fn ($a, $b) => strcmp((string) $a['primeira_data'], (string) $b['primeira_data']));
        $primeiro = reset($leads);
        $empVenda = $empIdDoNome($v['empreendimento']);
        if ($empVenda === null) {
            $X['empreendimento_sem_equivalencia']++;
        } elseif ($primeiro['empreendimento_id'] === $empVenda) {
            $X['mesmo_empreendimento']++;
        } else {
            $X['outro_empreendimento']++;
        }
        $X['com_oferta_ativa'] += array_filter($leads, fn ($l) => $l['oferta']) ? 1 : 0;
        $ph = implode(',', array_map('intval', $r['lead_ids']));
        if ($temTransf) {
            $X['com_transferencia'] += (int) db()->query("SELECT COUNT(*) FROM lead_transferencias WHERE lead_id IN ($ph)" . ($v['data_venda'] ? ' AND criado_em <= ' . db()->quote(substr($v['data_venda'], 0, 10) . ' 23:59:59') : ''))->fetchColumn() > 0 ? 1 : 0;
        }
        if ($temCamp && $v['data_venda']) {
            $X['com_remarketing_antes'] += (int) db()->query("SELECT COUNT(*) FROM whatsapp_campanha_destinatarios WHERE lead_id IN ($ph) AND status IN ('enviada','entregue','lida','respondida') AND atualizado_em <= "
                . db()->quote(substr($v['data_venda'], 0, 10) . ' 23:59:59'))->fetchColumn() > 0 ? 1 : 0;
        }
        if ($r['cronologia'] === 'anterior' || $r['cronologia'] === 'mesmo_dia') {
            $X['dias'][] = vendas_dias_entre($r['primeira_data'], $v['data_venda']);
        }
    }
    sort($X['dias']);
    $nd = count($X['dias']);
    $S['cruzamento'] = [
        'empresa' => ['id' => $empresaId, 'nome' => $empresa['nome']], 'regras' => VENDAS_REGRAS_VERSAO,
        'leads_no_indice' => count($idx['leads']), 'telefones_no_indice' => count($idx['tel']), 'emails_no_indice' => count($idx['email']),
        'status' => $X['status'], 'motivos_ambigua' => $X['motivos_ambigua'], 'cronologia_dos_vinculados' => $X['cronologia'],
        'vinculadas_com_varios_leads' => $X['com_varios_leads'],
        'empreendimento' => ['mesmo_do_primeiro_interesse' => $X['mesmo_empreendimento'], 'diferente' => $X['outro_empreendimento'], 'sem_equivalencia' => $X['empreendimento_sem_equivalencia']],
        'com_oferta_ativa' => $X['com_oferta_ativa'], 'com_transferencia_ate_a_venda' => $X['com_transferencia'], 'com_remarketing_antes_da_venda' => $X['com_remarketing_antes'],
        'valor_das_vendas_vinculadas' => round($X['valor_vinculado'], 2),
        'jornada_dias' => $nd ? ['vendas' => $nd, 'media' => round(array_sum($X['dias']) / $nd, 1), 'mediana' => $nd % 2 ? $X['dias'][intdiv($nd, 2)] : ($X['dias'][$nd / 2 - 1] + $X['dias'][$nd / 2]) / 2,
                                 'min' => $X['dias'][0], 'max' => $X['dias'][$nd - 1]] : null,
        'nota' => 'Prévia sem gravar nada. "Vinculada" = forte, telefone ou e-mail com entrada no CRM até o dia da venda. Ambíguas exigem revisão humana.',
    ];
}

if (isset($opts['json'])) {
    fwrite(STDOUT, json_encode($S, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}
$p = function (string $titulo, $dados, int $nivel = 0) use (&$p) {
    $pad = str_repeat('  ', $nivel);
    if (!is_array($dados)) {
        fwrite(STDOUT, $pad . $titulo . ': ' . (is_bool($dados) ? ($dados ? 'sim' : 'não') : ($dados === null ? '—' : $dados)) . "\n");
        return;
    }
    fwrite(STDOUT, $pad . $titulo . ($dados ? ':' : ': —') . "\n");
    $lista = array_is_list($dados);
    foreach ($dados as $k => $v) {
        $lista && !is_array($v) ? fwrite(STDOUT, $pad . '  - ' . $v . "\n") : $p((string) $k, $v, $nivel + 1);
    }
};
fwrite(STDOUT, 'INTELIGÊNCIA DE VENDAS — DIAGNÓSTICO DA PLANILHA (' . date('d/m/Y H:i') . ")\nSó contagens; nada foi gravado.\n\n");
foreach ($S as $k => $v) {
    $p(mb_strtoupper(str_replace('_', ' ', $k)), $v);
    fwrite(STDOUT, "\n");
}
if (empty($opts['empresa'])) {
    fwrite(STDOUT, "Para ver o cruzamento com o CRM, repita com --empresa=ID. Empresas:\n");
    foreach (db()->query('SELECT id, nome FROM empresas WHERE ativa = 1 ORDER BY id') as $e) {
        fwrite(STDOUT, '  ' . $e['id'] . ' — ' . $e['nome'] . "\n");
    }
}
