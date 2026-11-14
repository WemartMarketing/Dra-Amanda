<?php
/**
 * Testes da Central de Relatórios — Etapa 1 (auditoria de dados e dicionário).
 * Cenário fictício com resultados ESPERADOS calculados à mão; empresas de
 * teste apagadas no final.
 *
 *   php tests/relatorios_etapa1_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/relatorios/indicadores.php';
require_once __DIR__ . '/../includes/relatorios/auditoria_dados.php';
require_once __DIR__ . '/../includes/funil.php';
require_once __DIR__ . '/../includes/funcoes.php';

grupo('Dicionário de indicadores: completo e consistente');
$ind = relatorio_indicadores();
$campos = ['nome', 'categoria', 'descricao', 'formula', 'fonte', 'data_base', 'filtros', 'deduplicacao', 'permissao', 'ausentes', 'atualizacao', 'disponibilidade'];
$faltando = [];
foreach ($ind as $k => $i) {
    foreach ($campos as $c) {
        if (!isset($i[$c]) || !is_string($i[$c]) || trim($i[$c]) === '') {
            $faltando[] = "$k.$c";
        }
    }
}
igual($faltando, [], 'todo indicador tem os 12 campos preenchidos (nome, fórmula, fonte, data, filtros, deduplicação, permissão, ausentes, atualização…)');
ok(count($ind) >= 45, count($ind) . ' indicadores definidos');
ok(!array_filter(array_keys($ind), fn ($k) => !preg_match('/^[a-z0-9_]+$/', $k)), 'chaves estáveis (minúsculas e _)');
ok(!array_filter($ind, fn ($i) => !isset(relatorio_categorias()[$i['categoria']])), 'toda categoria existe na Central');
ok(!array_filter($ind, fn ($i) => !isset(relatorio_disponibilidades()[$i['disponibilidade']])), 'toda disponibilidade é válida');
$cobertas = array_unique(array_column($ind, 'categoria'));
igual(count(array_diff(array_keys(relatorio_categorias()), $cobertas)), 0, 'as 10 categorias da Central têm indicadores');
foreach (['investimento', 'roi', 'vgv', 'custos_por_resultado', 'visitas_confirmadas'] as $k) {
    ok(($ind[$k]['disponibilidade'] ?? '') === 'indisponivel', "\"{$ind[$k]['nome']}\" marcado como não disponível (não se inventa número)");
}
ok(str_contains($ind['recebidos_corretor']['ausentes'], 'carteira atual') && $ind['recebidos_corretor']['disponibilidade'] === 'etapa2', 'recebidos pelo corretor: não usa a carteira atual; depende do histórico da Etapa 2');
ok(str_contains(relatorio_regras_gerais()['contato_valido'], 'mensagem automática'), 'definição de contato efetivo exclui mensagem automática');
ok(str_contains($ind['wa_impactados']['deduplicacao'], 'UMA vez'), 'leads impactados contam uma vez, não por mensagem');
$doc = (string) @file_get_contents(__DIR__ . '/../docs/RELATORIOS_DICIONARIO.md');
igual($doc, relatorio_dicionario_markdown(), 'docs/RELATORIOS_DICIONARIO.md está em dia com o dicionário (rode ferramentas/gerar_dicionario.php)');

$emp = criar_empresa_teste('Relatórios E1');
$emp2 = criar_empresa_teste('Relatórios E1 Outra');
try {
    $pdo = db();
    $f = funcoes_seed_padrao($emp);
    funil_seed_padrao($emp);
    funil_seed_padrao($emp2);
    $pdo->prepare("INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, 'Corretora Auditoria', :m, 'x', 'corretor')")
        ->execute(['e' => $emp, 'f' => $f['corretor'], 'm' => bin2hex(random_bytes(5)) . '@teste.invalid']);
    $cor = (int) $pdo->lastInsertId();
    $et = fn (int $e, string $cat) => funil_etapa_por_categoria($e, $cat);
    $lead = function (int $e, array $d) use ($pdo, $et) {
        $d += ['nome' => 'Fulano Teste', 'telefone' => null, 'email' => null, 'origem' => 'manual', 'resp' => null, 'atrib' => null, 'contato' => null, 'cat' => 'novo_lead', 'contrato' => null];
        $etp = $et($e, $d['cat']);
        $pdo->prepare('INSERT INTO leads (empresa_id, nome, telefone, email, origem, responsavel_id, atribuido_em, primeiro_contato_em, etapa_id, etapa, contrato_assinado_em)
                       VALUES (:e, :n, :t, :m, :o, :r, :a, :c, :etid, :cat, :ct)')
            ->execute(['e' => $e, 'n' => $d['nome'], 't' => $d['telefone'], 'm' => $d['email'], 'o' => $d['origem'], 'r' => $d['resp'], 'a' => $d['atrib'], 'c' => $d['contato'],
                       'etid' => $etp['id'], 'cat' => $etp['categoria'], 'ct' => $d['contrato']]);
        return (int) $pdo->lastInsertId();
    };
    // Cenário (resultados esperados entre parênteses):
    $L1 = $lead($emp, ['telefone' => '(11) 98888-1111', 'origem' => 'meta_ads']);                 // mesma pessoa que L2
    $L2 = $lead($emp, ['telefone' => '+55 11 98888 1111', 'origem' => 'meta_ads']);               // (1 cadastro a mais)
    $L3 = $lead($emp, ['telefone' => 'sem número', 'email' => null]);                              // (sem telefone nem e-mail)
    $L4 = $lead($emp, ['telefone' => '11977770000', 'resp' => $cor, 'atrib' => '2026-11-01 10:00:00', 'contato' => '2026-11-01 09:00:00', 'cat' => 'primeiro_contato']); // (data incoerente; sem registro de atribuição; fora da 1ª etapa sem transição)
    $L5 = $lead($emp, ['telefone' => '11966660000', 'resp' => $cor, 'atrib' => '2026-11-02 10:00:00', 'cat' => 'contrato_assinado']); // (contrato sem data)
    $pdo->prepare("INSERT INTO roletas (empresa_id, nome) VALUES (:e, 'Roleta Auditoria')")->execute(['e' => $emp]);
    $rol = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO roleta_distribuicoes (empresa_id, roleta_id, lead_id, usuario_id, origem) VALUES (:e, :r, :l, :u, \'manual\')')->execute(['e' => $emp, 'r' => $rol, 'l' => $L5, 'u' => $cor]); // L5 tem registro
    $pdo->prepare("INSERT INTO auditoria (empresa_id, usuario_id, acao, entidade, entidade_id, detalhes_json) VALUES (:e, :u, 'lead.etapa_alterada', 'lead', :l, :d)")
        ->execute(['e' => $emp, 'u' => $cor, 'l' => $L5, 'd' => json_encode(['de' => 'em_atendimento', 'para' => 'contrato_assinado'])]);
    $L6 = $lead($emp, ['telefone' => '11955550000', 'resp' => $cor, 'atrib' => '2026-11-03 10:00:00', 'cat' => 'em_atendimento']);
    $pdo->prepare("INSERT INTO auditoria (empresa_id, usuario_id, acao, entidade, entidade_id, detalhes_json) VALUES (:e, :u, 'lead.etapa_alterada', 'lead', :l, :d)")
        ->execute(['e' => $emp, 'u' => $cor, 'l' => $L6, 'd' => json_encode(['de' => 'visita_realizada', 'para' => 'contrato_assinado'])]); // (entrou em contrato e saiu)
    $pdo->prepare("INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status) VALUES (:e, :l, :u, 'visita', 'Visita', DATE_SUB(NOW(), INTERVAL 3 DAY), 'agendado')")
        ->execute(['e' => $emp, 'l' => $L6, 'u' => $cor]); // (visita vencida sem situação)
    $pdo->prepare("INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, concluido_em) VALUES (:e, :l, :u, 'visita', 'Visita', DATE_SUB(NOW(), INTERVAL 2 DAY), 'realizado', NOW())")
        ->execute(['e' => $emp, 'l' => $L6, 'u' => $cor]);
    // Outra empresa: mesmos problemas, que NÃO podem aparecer na auditoria desta.
    $lead($emp2, ['telefone' => '(11) 98888-1111']);
    $lead($emp2, ['telefone' => '(11) 98888-1111']);
    $lead($emp2, ['telefone' => null]);

    grupo('Auditoria dos dados: números esperados');
    $a = relatorio_auditoria_dados($emp);
    $achar = function (string $secao, string $rotulo) use ($a) {
        foreach ($a[$secao] ?? [] as $it) {
            if ($it['rotulo'] === $rotulo) {
                return $it;
            }
        }
        return null;
    };
    igual($achar('Leads', 'Leads ativos')['valor'], 6, 'leads ativos = 6');
    igual($achar('Leads', 'Leads únicos (telefone/e-mail)')['valor'], 5, 'leads únicos = 5 (L1 e L2 são a mesma pessoa, com o telefone escrito diferente)');
    ok(str_contains($achar('Leads', 'Leads únicos (telefone/e-mail)')['nota'], 'Cadastros a mais para a mesma pessoa: 1'), '1 cadastro a mais identificado');
    igual($achar('Leads', 'Leads sem telefone válido nem e-mail')['valor'], 1, 'lead sem contato = 1');
    igual($achar('Leads', 'Origem: manual')['nivel'], 'alerta', 'origem genérica "manual" sinalizada');
    igual($achar('Atribuição e histórico do corretor', 'Leads com corretor sem registro de como chegaram a ele')['valor'], 2, 'com corretor sem registro de atribuição = 2 (L4 e L6; L5 veio pela roleta)');
    igual($achar('Atendimento', '1º atendimento antes da atribuição (data incoerente)')['valor'], 1, 'data incoerente = 1 (L4)');
    igual($achar('Contratos e visitas', 'Contratos sem data de assinatura')['valor'], 1, 'contrato sem data = 1 (L5)');
    igual($achar('Contratos e visitas', 'Leads que entraram em "contrato" e depois saíram da etapa')['valor'], 1, 'entrou em contrato e saiu = 1 (L6)');
    igual($achar('Contratos e visitas', 'Visitas que já passaram e continuam "agendadas" (sem situação)')['valor'], 1, 'visita vencida sem situação = 1');
    igual($achar('Funil (Kanban)', 'Mudanças de etapa com origem/destino estruturados')['valor'], 2, 'transições estruturadas = 2');
    igual($achar('Funil (Kanban)', 'Leads fora da 1ª etapa sem nenhuma transição estruturada')['valor'], 1, 'fora da 1ª etapa sem histórico = 1 (L4)');
    igual($achar('Marketing', 'Investimento publicitário')['valor'], 'não existe', 'investimento declarado como inexistente');
    $json = json_encode($a, JSON_UNESCAPED_UNICODE);
    ok(!str_contains($json, 'Fulano') && !str_contains($json, '98888') && !str_contains($json, '@'), 'a auditoria não expõe nomes, telefones nem e-mails');

    grupo('Isolamento entre empresas');
    $b = relatorio_auditoria_dados($emp2);
    $achar2 = function (string $secao, string $rotulo) use ($b) {
        foreach ($b[$secao] ?? [] as $it) {
            if ($it['rotulo'] === $rotulo) {
                return $it['valor'];
            }
        }
        return null;
    };
    igual($achar2('Leads', 'Leads ativos'), 3, 'a outra empresa vê só os seus 3 leads');
    igual($achar2('Leads', 'Leads únicos (telefone/e-mail)'), 2, 'e os próprios duplicados');
    igual($achar('Leads', 'Leads ativos')['valor'], 6, 'números desta empresa não mudam com os da outra');

    grupo('Ferramenta de terminal');
    $saida = shell_exec(escapeshellarg(PHP_BINARY) . ' -q ' . escapeshellarg(__DIR__ . '/../ferramentas/auditoria_relatorios.php') . ' --empresa=' . $emp . ' --json');
    $j = json_decode((string) $saida, true);
    ok(is_array($j) && $j['empresas'][0]['id'] === $emp && isset($j['empresas'][0]['secoes']['Leads']), 'ferramentas/auditoria_relatorios.php --json devolve a auditoria da empresa');
    $antes = (int) $pdo->query("SELECT COUNT(*) FROM leads WHERE empresa_id = $emp")->fetchColumn() + (int) $pdo->query("SELECT COUNT(*) FROM auditoria WHERE empresa_id = $emp")->fetchColumn();
    shell_exec(escapeshellarg(PHP_BINARY) . ' -q ' . escapeshellarg(__DIR__ . '/../ferramentas/auditoria_relatorios.php') . ' --empresa=' . $emp);
    $depois = (int) $pdo->query("SELECT COUNT(*) FROM leads WHERE empresa_id = $emp")->fetchColumn() + (int) $pdo->query("SELECT COUNT(*) FROM auditoria WHERE empresa_id = $emp")->fetchColumn();
    igual($depois, $antes, 'a auditoria não altera nada no banco');
} finally {
    apagar_empresa_teste($emp);
    apagar_empresa_teste($emp2);
    grupo('Limpeza');
    igual((int) db()->query("SELECT COUNT(*) FROM empresas WHERE nome LIKE 'Teste automatizado%'")->fetchColumn(), 0, 'nenhum dado de teste ficou no banco');
}
resumo_e_sair();
