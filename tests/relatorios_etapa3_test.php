<?php
/**
 * Central de Relatórios — Etapa 3: séries no tempo, agrupamentos por
 * estrutura, ranking, favoritos, permissões das telas e consistência entre
 * os números (série × total, grupos × corretores).
 *
 *   php -q tests/relatorios_etapa3_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';
require_once __DIR__ . '/../includes/roleta.php';
require_once __DIR__ . '/../includes/contratos.php';
require_once __DIR__ . '/../includes/relatorios/ui.php';

function v(string $sql)
{
    return db()->query($sql)->fetchColumn();
}

grupo('Intervalos do período');
$p = relatorio_periodo('personalizado', '2026-03-01', '2026-03-31');
$b = relatorio_baldes($p, 'dia');
igual(count($b), 31, 'março por dia: 31 intervalos (inclusive os vazios)');
$b = relatorio_baldes($p, 'semana');
igual(array_key_first($b), '2026-02-23', 'semana começa na segunda (01/03/2026 é domingo → semana de 23/02)');
igual(reset($b)['ini'], '2026-03-01', 'mas o 1º intervalo parcial começa no início do período');
igual(count($b), 6, 'março em semanas: 6 intervalos');
$b = relatorio_baldes(relatorio_periodo('personalizado', '2025-11-15', '2026-02-10'), 'mes');
igual(array_column(array_values($b), 'rotulo'), ['nov/25', 'dez/25', 'jan/26', 'fev/26'], 'por mês: nov/25 a fev/26');
igual(relatorio_balde_chave('2026-03-04 15:00:00', 'semana'), '2026-03-02', 'quarta 04/03 → semana de 02/03');
igual(relatorio_granularidade_padrao(['dias' => 30]), 'dia', '30 dias → por dia');
igual(relatorio_granularidade_padrao(['dias' => 90]), 'semana', '90 dias → por semana');
igual(relatorio_granularidade_padrao(['dias' => 365]), 'mes', 'ano → por mês');

grupo('Ranking por critério explícito');
$L = [
    ['id' => 1, 'nome' => 'Ana', 'leads_recebidos' => 20, 'taxa_contato_efetivo' => 40.0, 'tempo_primeiro_atendimento_min' => 30, 'contratos_validados' => 2],
    ['id' => 2, 'nome' => 'Bia', 'leads_recebidos' => 2, 'taxa_contato_efetivo' => 100.0, 'tempo_primeiro_atendimento_min' => 5, 'contratos_validados' => 2],
    ['id' => 3, 'nome' => 'Caio', 'leads_recebidos' => 10, 'taxa_contato_efetivo' => 60.0, 'tempo_primeiro_atendimento_min' => null, 'contratos_validados' => 0],
    ['id' => 4, 'nome' => 'Davi', 'leads_recebidos' => 8, 'taxa_contato_efetivo' => 40.0, 'tempo_primeiro_atendimento_min' => 90, 'contratos_validados' => 1],
];
$r = relatorio_ranking($L, 'taxa_contato_efetivo', 5);
igual(array_column($r['ranking'], 'nome'), ['Caio', 'Ana', 'Davi'], 'taxa: 100% com 2 leads fica FORA (base mínima 5)');
igual(array_column($r['ranking'], 'posicao'), [1, 2, 2], 'empate divide a posição (2º e 2º)');
igual(array_column($r['sem_base'], 'nome'), ['Bia'], 'quem ficou de fora é listado');
$r = relatorio_ranking($L, 'tempo_primeiro_atendimento_min', 5);
igual(array_column($r['ranking'], 'nome'), ['Ana', 'Davi'], 'tempo: menor é melhor; sem dado (Caio) fica fora');
$r = relatorio_ranking($L, 'contratos_validados', 5);
igual(array_column($r['ranking'], 'nome'), ['Ana', 'Bia', 'Davi', 'Caio'], 'contagem não exige base mínima');
igual(relatorio_ranking($L, 'indice_secreto')['ranking'], [], 'critério inexistente → sem ranking');

grupo('Formatação e parâmetros');
igual(relui_valor(null, '%'), '—', 'sem base → "—"');
igual(relui_valor(12.345, '%'), '12,3%', 'taxa com vírgula');
igual(relui_valor(485000, 'R$'), 'R$ 485.000,00', 'moeda');
igual(relui_valor(90, 'min'), '1 h 30 min', 'minutos → duração');
igual(relui_variacao(['valor' => 5, 'anterior' => 0, 'variacao' => null])[0], 'sem base de comparação', 'anterior 0 → sem base');
igual(relui_variacao(['valor' => 50.0, 'anterior' => 40.0, 'variacao' => 10.0, 'variacao_unidade' => 'p.p.', 'unidade' => '%']), ['+10,0 p.p. vs. anterior', 'up'], 'taxa sobe 10 p.p. → verde');
igual(relui_variacao(['valor' => 120, 'anterior' => 60, 'variacao' => 100.0, 'variacao_unidade' => '%'], true)[1], 'down', 'tempo que dobra → vermelho (menor é melhor)');
[$ent, $pub] = relui_entrada(['periodo' => '7d', 'equipe' => '3', 'hack' => '1', 'emp' => ['1', '2', ''], 'gran' => 'mes']);
ok(!isset($pub['hack']) && $pub['emp'] === ['1', '2'] && !isset($ent['gran']) && $ent['equipe'] === '3', 'URL: chaves desconhecidas descartadas; parâmetros de tela fora do motor');
igual(relui_filtros_limpos('periodo=ano&equipe=2&x=<script>'), 'periodo=ano&equipe=2', 'favorito guarda só filtros conhecidos');
igual(relui_qtd(1, 'lead', 'leads') . ' / ' . relui_qtd(3, 'lead', 'leads'), '1 lead / 3 leads', 'singular e plural');

$emp = criar_empresa_teste('Relatorios E3');
try {
    $pdo = db();
    $f = funcoes_seed_padrao($emp);
    funil_seed_padrao($emp);
    $etapa1 = funil_primeira_etapa($emp);
    $uid = function (string $nome, string $cargo) use ($pdo, $emp, $f) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, :h, :c)')
            ->execute(['e' => $emp, 'f' => $f[$cargo], 'n' => $nome, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => $cargo]);
        return (int) $pdo->lastInsertId();
    };
    $u = fn (int $id, string $esc, array $perms = []) => ['id' => $id, 'nome' => (string) v("SELECT nome FROM usuarios WHERE id = $id"), 'empresa_id' => $emp,
        'empresa_ativa_id' => $emp, 'eh_superadmin' => false, 'escopo' => $esc, 'permissoes' => $perms];
    $master = $uid('Master', 'master_empresa');
    $gA = $uid('Gerente A', 'gerente');
    $gB = $uid('Gerente B', 'gerente');
    $pdo->prepare("INSERT INTO superintendencias (empresa_id, nome) VALUES (:e, 'S1')")->execute(['e' => $emp]);
    $s1 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO equipes (empresa_id, nome, gerente_id, superintendencia_id) VALUES (:e, 'Equipe A', :g, :s)")->execute(['e' => $emp, 'g' => $gA, 's' => $s1]);
    $eqA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO equipes (empresa_id, nome, gerente_id, superintendencia_id) VALUES (:e, 'Equipe B', :g, :s)")->execute(['e' => $emp, 'g' => $gB, 's' => $s1]);
    $eqB = (int) $pdo->lastInsertId();
    $c1 = $uid('Corretor Um', 'corretor');
    $c2 = $uid('Corretor Dois', 'corretor');
    $c3 = $uid('Corretor Tres', 'corretor');
    $pdo->exec("UPDATE usuarios SET equipe_id = $eqA WHERE id IN ($c1, $c2)");
    $pdo->exec("UPDATE usuarios SET equipe_id = $eqB WHERE id = $c3");
    $M = $u($master, 'toda_empresa', ['visualizar_indicadores', 'validar_contratos', 'editar_leads']);
    $GA = $u($gA, 'equipe', ['visualizar_indicadores']);
    $C1 = $u($c1, 'propria_carteira', ['editar_leads']);
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Emp Um'), (:e2, 'Emp Dois')")->execute(['e' => $emp, 'e2' => $emp]);
    $emp1 = (int) $pdo->lastInsertId();
    $emp2 = $emp1 + 1;
    $novo = function (int $empreendimento, int $dono, string $quando) use ($pdo, $emp, $etapa1) {
        $pdo->prepare('INSERT INTO leads (empresa_id, empreendimento_id, nome, telefone, origem, etapa_id, etapa, criado_em, ultima_atividade_em) VALUES (:e, :emp, :n, :t, \'manual\', :et, :cat, :q, :q2)')
            ->execute(['e' => $emp, 'emp' => $empreendimento, 'n' => 'Cliente ' . bin2hex(random_bytes(2)), 't' => '119' . random_int(10000000, 99999999), 'et' => $etapa1['id'], 'cat' => $etapa1['categoria'], 'q' => $quando, 'q2' => $quando]);
        $id = (int) $pdo->lastInsertId();
        $eq = (int) v("SELECT equipe_id FROM usuarios WHERE id = $dono");
        $pdo->exec("UPDATE leads SET responsavel_id = $dono, equipe_id = $eq, atribuido_em = '$quando' WHERE id = $id");
        historico_abrir_atribuicao($id, $dono, 'cadastro', ['em' => $quando]);
        return $id;
    };
    // Março/2026: 3 leads do c1 (Emp Um), 2 do c2 (Emp Dois), 4 do c3 (Emp Um), espalhados.
    $leads = [];
    foreach ([[$emp1, $c1, '2026-03-02 10:00:00'], [$emp1, $c1, '2026-03-02 11:00:00'], [$emp1, $c1, '2026-03-20 09:00:00'],
              [$emp2, $c2, '2026-03-05 10:00:00'], [$emp2, $c2, '2026-03-28 10:00:00'],
              [$emp1, $c3, '2026-03-03 10:00:00'], [$emp1, $c3, '2026-03-10 10:00:00'], [$emp1, $c3, '2026-03-11 10:00:00'], [$emp1, $c3, '2026-03-31 18:00:00']] as [$e, $d, $q]) {
        $leads[] = $novo($e, $d, $q);
    }
    // Contato efetivo em 2 do c3 e 1 do c1; contrato validado de um lead do c3 em 12/03.
    $pdo->exec("UPDATE lead_atribuicoes SET primeiro_atendimento_em = DATE_ADD(atribuido_em, INTERVAL 30 MINUTE), primeiro_contato_efetivo_em = DATE_ADD(atribuido_em, INTERVAL 1 HOUR) WHERE lead_id IN ({$leads[5]}, {$leads[6]}, {$leads[0]})");
    $pdo->prepare("INSERT INTO lead_contratos (empresa_id, lead_id, empreendimento_id, usuario_id, equipe_id, gerente_id, superintendencia_id, assinado_em, valor_vgv, status, registrado_por)
                   VALUES (:e, :l, :emp, :u, :eq, :g, :s, '2026-03-12', 300000, 'validado', :r)")
        ->execute(['e' => $emp, 'l' => $leads[6], 'emp' => $emp1, 'u' => $c3, 'eq' => $eqB, 'g' => $gB, 's' => $s1, 'r' => $master]);

    // Ruído que NÃO pode entrar: mudança de equipe no período e entrega logo depois do fim.
    historico_abrir_atribuicao($leads[1], $c1, 'mudanca_equipe', ['em' => '2026-03-25 10:00:00']);
    $pdo->exec("INSERT INTO lead_atribuicoes (empresa_id, lead_id, usuario_id, equipe_id, forma, atribuido_em) VALUES ($emp, {$leads[2]}, $c2, $eqA, 'transferencia', '2026-04-01 00:30:00')");
    $F = relatorio_filtros($M, ['periodo' => 'personalizado', 'ini' => '2026-03-01', 'fim' => '2026-03-31']);

    grupo('Séries batem com os totais (mesma fonte)');
    $s = relatorio_serie($M, $F, 'leads_recebidos', 'dia');
    igual(count($s), 31, 'série diária com 31 pontos');
    igual(array_sum(array_column($s, 'total')), relatorio_leads($M, $F)['leads_recebidos']['valor'], 'soma da série = leads recebidos do período');
    igual($s[1]['total'], 2, '02/03: 2 leads');
    igual(array_column(relatorio_serie($M, $F, 'leads_recebidos', 'semana'), 'total'), [0, 4, 2, 1, 1, 1], 'por semana: 0 (só 01/03), 4, 2, 1, 1, 1 (conferido à mão)');
    igual(array_sum(array_column(relatorio_serie($M, $F, 'recebimentos', 'mes'), 'total')), 9, 'entregas no mês = 9');
    igual(array_sum(array_column(relatorio_serie($M, $F, 'contatos_efetivos', 'semana'), 'total')), 3, 'contatos efetivos = 3');
    $sc = relatorio_serie($M, $F, 'contratos_validados', 'dia');
    ok($sc[11]['total'] === 1 && array_sum(array_column($sc, 'total')) === relatorio_contratos($M, $F)['contratos_validados']['valor'], 'contrato no dia 12/03; soma = contratos validados');
    igual(array_sum(array_column(relatorio_serie($M, relatorio_filtros($M, ['periodo' => 'personalizado', 'ini' => '2026-03-01', 'fim' => '2026-03-31', 'equipe' => [999999]]), 'leads_recebidos'), 'total')), 0, 'filtro inválido → série zerada');
    igual(array_sum(array_column(relatorio_serie($GA, $F, 'recebimentos', 'mes'), 'total')), 5, 'gerente A: só as entregas da equipe A (5)');

    grupo('Agrupamentos consistentes entre si');
    $porC = relatorio_agrupado($M, $F, 'corretor');
    $porE = relatorio_agrupado($M, $F, 'equipe');
    $porS = relatorio_agrupado($M, $F, 'superintendencia');
    $porEmp = array_column(relatorio_agrupado($M, $F, 'empreendimento'), null, 'nome');
    $soma = fn (array $ls, string $k) => array_sum(array_column($ls, $k));
    ok($soma($porC, 'recebimentos') === 9 && $soma($porE, 'recebimentos') === 9 && $soma($porS, 'recebimentos') === 9, 'corretores = equipes = superintendências = 9 entregas');
    ok($soma($porC, 'contato_efetivo') === $soma($porE, 'contato_efetivo') && $soma($porC, 'contratos_validados') === 1 && $soma($porE, 'contratos_validados') === 1, 'contatos e contratos batem entre níveis');
    $eB = array_column($porE, null, 'nome')['Equipe B'];
    ok($eB['leads_recebidos'] === 4 && $eB['contato_efetivo'] === 2 && $eB['taxa_contato_efetivo'] === 50.0 && $eB['contratos_validados'] === 1 && $eB['vgv'] === 300000.0, 'Equipe B: 4 recebidos, 50% contato efetivo, 1 contrato, R$ 300 mil');
    ok($porEmp['Emp Um']['leads_recebidos'] === 7 && $porEmp['Emp Dois']['leads_recebidos'] === 2, 'por empreendimento: 7 e 2');
    $lc3 = array_column($porC, null, 'id')[$c3];
    igual($lc3['tempo_primeiro_atendimento_min'], 30.0, 'tempo mediano do c3 = 30 min');
    igual(array_column(relatorio_agrupado($GA, $F, 'corretor'), 'id'), [$c1, $c2], 'gerente A: só os corretores da equipe A');
    igual(relatorio_agrupado($M, $F, 'cargo_inventado'), [], 'agrupamento inexistente → vazio');
    // Contrato sem entrega no período continua aparecendo no grupo.
    $FA = relatorio_filtros($M, ['periodo' => 'personalizado', 'ini' => '2026-03-12', 'fim' => '2026-03-12']);
    $soContrato = array_column(relatorio_agrupado($M, $FA, 'corretor'), null, 'id');
    ok(isset($soContrato[$c3]) && $soContrato[$c3]['leads_recebidos'] === 0 && $soContrato[$c3]['contratos_validados'] === 1, 'quem vendeu no período sem receber lead nele aparece com o contrato');
    // Atribuição sem equipe registrada.
    $pdo->exec("UPDATE lead_atribuicoes SET equipe_id = NULL WHERE lead_id = {$leads[4]}");
    $semEq = array_filter(relatorio_agrupado($M, $F, 'equipe'), fn ($l) => $l['id'] === null);
    ok(count($semEq) === 1 && reset($semEq)['nome'] === 'Sem equipe registrado(a)' && reset($semEq)['recebimentos'] === 1, 'entrega sem equipe vira a linha "Sem equipe registrado(a)", sem sumir do total');

    grupo('Visitas do corretor');
    $pdo->prepare("INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, criado_em, concluido_em)
                   VALUES (:e, :l, :u, 'visita', 'V', '2026-03-15 10:00:00', 'realizado', :u2, '2026-03-10 10:00:00', '2026-03-15 11:00:00'),
                          (:e2, :l2, :u3, 'visita', 'V', '2026-03-16 10:00:00', 'cliente_ausente', :u4, '2026-03-10 10:00:00', '2026-03-16 11:00:00')")
        ->execute(['e' => $emp, 'l' => $leads[5], 'u' => $c3, 'u2' => $c3, 'e2' => $emp, 'l2' => $leads[7], 'u3' => $c3, 'u4' => $c3]);
    igual(relatorio_visitas_do_corretor($M, $F, $c3), ['realizadas' => 1, 'ausentes' => 1, 'agendadas' => 2], 'c3: 1 realizada, 1 ausente, 2 agendadas');
    igual(relatorio_visitas_do_corretor($GA, $F, $c3)['realizadas'], 0, 'gerente A não vê as visitas do c3 (equipe B)');

    grupo('Quem abre cada relatório');
    ok(relui_pode($M, 'executiva') && relui_pode($M, 'gestao') && relui_pode($M, 'empreendimentos') && relui_pode($M, 'corretor'), 'gestor: todos');
    ok(relui_pode($C1, 'corretor') && !relui_pode($C1, 'executiva') && !relui_pode($C1, 'gestao') && !relui_pode($C1, 'empreendimentos'), 'corretor: só o próprio desempenho');
    ok(!relui_pode(['eh_superadmin' => true, 'escopo' => 'toda_empresa', 'permissoes' => ['visualizar_indicadores']], 'executiva'), 'superadmin não usa relatórios de empresa');
    ok(!relui_pode($M, 'inventado'), 'relatório inexistente → não');
    ok(!relui_pode($u($gA, 'equipe', []), 'corretor') && !relui_pode($u($gA, 'equipe', []), 'executiva'), 'gestor sem "visualizar indicadores": nenhum relatório');
    $opC = relui_opcoes($C1);
    ok(count($opC['corretores']) === 1 && (int) $opC['corretores'][0]['id'] === $c1 && $opC['equipes'] === [] && $opC['roletas'] === [], 'opções do corretor: só ele mesmo, sem estrutura');

    grupo('Favoritos');
    if (relui_favoritos_instalados()) {
        ok(relui_favorito_salvar($GA, 'gestao', 'Equipe A — março', 'periodo=personalizado&ini=2026-03-01&fim=2026-03-31&equipe=' . $eqA . '&evil=1')['ok'], 'favorito salvo');
        $fav = relui_favoritos_listar($GA, 'gestao');
        ok(count($fav) === 1 && !str_contains($fav[0]['filtros'], 'evil'), 'listado, sem parâmetros desconhecidos');
        ok(relui_favorito_salvar($GA, 'gestao', 'Equipe A — março', 'periodo=ano')['ok'] && count(relui_favoritos_listar($GA, 'gestao')) === 1, 'mesmo nome → atualiza, não duplica');
        igual(relui_favoritos_listar($M, 'gestao'), [], 'outro usuário não vê o favorito');
        ok(!relui_favorito_remover($M, (int) $fav[0]['id']), 'outro usuário não remove');
        ok(!relui_favorito_salvar($GA, 'inventado', 'X1', 'periodo=ano')['ok'] && !relui_favorito_salvar($GA, 'gestao', 'X', 'periodo=ano')['ok'], 'relatório inválido ou nome curto → recusado');
        ok(relui_favorito_remover($GA, (int) $fav[0]['id']) && relui_favoritos_listar($GA, 'gestao') === [], 'dono remove');
    } else {
        ok(false, 'migração 014 não aplicada no banco de testes');
    }
} finally {
    apagar_empresa_teste($emp);
}
resumo_e_sair();
