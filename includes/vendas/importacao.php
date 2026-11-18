<?php
/**
 * Inteligência de Vendas — Fase 2: importação de planilhas de vendas.
 *
 * Fluxo: arquivo → mapeamento → vendas_analisar() (prévia, sem gravar) →
 * vendas_importar() (grava). As duas usam a MESMA análise, então a prévia
 * mostra exatamente o que será gravado.
 *
 * Regras:
 *  - Chave da venda: empresa + sistema de origem + "id" da origem. Sem id,
 *    usa "Venda RM". Sem nenhum dos dois: linha recusada.
 *  - "Venda RM" já usada por OUTRA venda: linha recusada (conflito de
 *    identificadores; nunca cria duplicata).
 *  - Mesmo id repetido no arquivo: só a 1ª linha vale; as outras são ignoradas.
 *  - Venda já importada e igual: "sem alteração". Diferente: atualizada
 *    (se autorizado na confirmação) com histórico do antes/depois.
 *  - Nada aqui toca em leads, Kanban, responsáveis ou automações.
 *  - CPF: só HMAC + 2 últimos dígitos. Colunas pessoais sem uso: não gravadas.
 */
require_once __DIR__ . '/planilha.php';
require_once __DIR__ . '/../auditoria.php';

const VENDAS_VERSAO_IMPORTADOR = '1';
const VENDAS_DIR = __DIR__ . '/../../storage/vendas';
const VENDAS_RETENCAO_DIAS = 30;

/** Campos pessoais: no histórico de versões só registramos que mudaram. */
const VENDAS_CAMPOS_PESSOAIS = ['cliente_nome', 'telefone1', 'telefone2', 'email', 'cpf_hash', 'cpf_final'];

function vendas_instalado(): bool
{
    return modulo_tabela_existe('vendas_externas') && modulo_tabela_existe('vendas_lotes');
}

function vendas_sistemas(): array
{
    return [VENDAS_SISTEMA_HM => 'Sistema comercial HM'];
}

function vendas_dir(): string
{
    if (!is_dir(VENDAS_DIR)) {
        mkdir(VENDAS_DIR, 0750, true);       // storage/.htaccess já nega acesso pelo navegador
    }
    return VENDAS_DIR;
}

/** Caminho do original guardado de um lote (só id numérico e extensão conhecida). */
function vendas_arquivo_lote(int $loteId, string $ext): ?string
{
    return $loteId > 0 && in_array($ext, ['csv', 'xlsx'], true) ? vendas_dir() . '/lote_' . $loteId . '.' . $ext : null;
}

/** Segredo do servidor para o HMAC do CPF (APP_KEY; sem ela, derivado da conexão). */
function vendas_segredo(): string
{
    if (defined('APP_KEY') && (string) APP_KEY !== '') {
        return (string) APP_KEY;
    }
    return hash('sha256', (defined('DB_PASS') ? (string) DB_PASS : '') . '|' . (defined('DB_NAME') ? (string) DB_NAME : '') . '|wemart-vendas');
}

function vendas_cpf_hash(int $empresaId, string $cpf): string
{
    return hash_hmac('sha256', 'cpf|' . $empresaId . '|' . $cpf, vendas_segredo());
}

/** Rótulo normalizado de situação (para comparar "Vendido" × "VENDIDO "). */
function vendas_situacao_chave(?string $s): string
{
    $s = trim((string) $s);
    return $s === '' ? '(vazia)' : mb_strtolower(preg_replace('/\s+/u', ' ', $s));
}

/**
 * Lê o arquivo. Retorna ['ok' => true, 'cabecalho' => [...], 'linhas' => [nº da linha => [...]], 'data1904' => bool]
 * ou ['ok' => false, 'erro' => '...']. Linhas vazias são puladas, mas a numeração segue a planilha.
 */
function vendas_ler_arquivo(string $caminho, string $ext): array
{
    if (!is_file($caminho)) {
        return ['ok' => false, 'erro' => 'Arquivo não encontrado. Envie a planilha de novo.'];
    }
    if (filesize($caminho) > IMPORTACAO_MAX_BYTES) {
        return ['ok' => false, 'erro' => 'Arquivo maior que 5 MB.'];
    }
    $r = $ext === 'xlsx' ? importacao_ler_xlsx($caminho, 200) : importacao_ler_csv($caminho);
    if (!$r['ok']) {
        return $r;
    }
    $cab = null;
    $linhas = [];
    foreach ($r['linhas'] as $i => $l) {
        if (implode('', array_map(fn ($v) => trim((string) $v), $l)) === '') {
            continue;
        }
        if ($cab === null) {
            $cab = array_map(fn ($v) => mb_substr(trim((string) $v), 0, 120), $l);
            continue;
        }
        $linhas[$i + 1] = array_map(fn ($v) => mb_substr(trim((string) $v), 0, 1000), $l);
    }
    if ($cab === null || !$linhas) {
        return ['ok' => false, 'erro' => 'A planilha não tem linhas de dados (a primeira linha deve ser o cabeçalho).'];
    }
    if (count($linhas) > IMPORTACAO_MAX_LINHAS) {
        return ['ok' => false, 'erro' => 'A planilha tem ' . count($linhas) . ' linhas. O limite é ' . IMPORTACAO_MAX_LINHAS . ' por importação — divida o arquivo.'];
    }
    return ['ok' => true, 'cabecalho' => $cab, 'linhas' => $linhas, 'data1904' => $ext === 'xlsx' && vendas_xlsx_data1904($caminho)];
}

/**
 * Valida um mapeamento vindo do formulário ([campo => índice|'']) contra a
 * largura do cabeçalho. Retorna [mapa, erro|null].
 */
function vendas_mapa_validar(array $entrada, int $largura): array
{
    $mapa = [];
    $usadas = [];
    foreach (vendas_campos() as $campo => [, , $rotulo]) {
        $v = $entrada[$campo] ?? '';
        if ($v === '' || $v === null) {
            continue;
        }
        $i = (int) $v;
        if ((string) $i !== (string) $v || $i < 0 || $i >= $largura) {
            return [[], 'Coluna inválida para "' . $rotulo . '".'];
        }
        if (isset($usadas[$i])) {
            return [[], 'A mesma coluna foi escolhida para "' . $usadas[$i] . '" e "' . $rotulo . '".'];
        }
        $usadas[$i] = $rotulo;
        $mapa[$campo] = $i;
    }
    foreach (['data_venda' => 'Data da venda', 'empreendimento' => 'Empreendimento'] as $c => $r) {
        if (!isset($mapa[$c])) {
            return [[], 'Escolha a coluna de "' . $r . '".'];
        }
    }
    if (!isset($mapa['venda_id_origem']) && !isset($mapa['venda_rm'])) {
        return [[], 'Escolha a coluna do id da venda ou da "Venda RM": sem identificador não dá para evitar duplicidade.'];
    }
    return [$mapa, null];
}

/** Registro do banco a partir da linha normalizada (só o que é guardado). */
function vendas_registro(int $empresaId, array $v, array $extras): array
{
    return [
        'venda_rm' => $v['venda_rm'], 'situacao' => $v['situacao'],
        'data_venda' => $v['data_venda'], 'data_venda_so_data' => $v['data_venda_so_data'] ? 1 : 0,
        'data_cadastro_origem' => $v['data_cadastro_origem'], 'data_envio_rm' => $v['data_envio_rm'],
        'cliente_nome' => $v['cliente_nome'] !== '' ? $v['cliente_nome'] : null,
        'telefone1' => $v['telefones'][0] ?? null, 'telefone2' => $v['telefones'][1] ?? null, 'email' => $v['email'],
        'cpf_hash' => $v['cpf'] ? vendas_cpf_hash($empresaId, $v['cpf']) : null, 'cpf_final' => $v['cpf'] ? substr($v['cpf'], -2) : null,
        'empreendimento_texto' => $v['empreendimento'] ?: null, 'bloco' => mb_substr($v['bloco'], 0, 40) ?: null, 'unidade' => mb_substr($v['unidade'], 0, 40) ?: null,
        'imobiliaria_texto' => $v['imobiliaria'] ?: null, 'corretor_id_origem' => $v['corretor_id_origem'] !== null ? mb_substr($v['corretor_id_origem'], 0, 40) : null,
        'corretor_texto' => $v['corretor_nome'] ?: null, 'superintendente_texto' => $v['superintendente'] ?: null, 'gerente_texto' => $v['gerente'] ?: null,
        'coordenador_texto' => $v['coordenador'] ?: null, 'midia_texto' => $v['midia'] ?: null, 'modalidade' => $v['modalidade'] ?: null,
        'valor' => $v['valor'], 'tabela' => $v['tabela'], 'desconto' => $v['desconto'], 'entrada' => $v['entrada'], 'subsidio' => $v['subsidio'],
        'fgts' => $v['fgts'], 'financiamento' => $v['financiamento'], 'comissao_cliente' => $v['comissao_cliente'], 'comissao_empresa' => $v['comissao_empresa'],
        'valor_pagamento' => $v['valor_pagamento'],
        'extras' => $extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null,
        'alertas' => $v['alertas'] ? json_encode(array_values($v['alertas']), JSON_UNESCAPED_UNICODE) : null,
    ];
}

/** Hash do conteúdo comercial (decide "sem alteração" × "atualizada"). Números com 2 casas para não variar com o formato. */
function vendas_hash_conteudo(array $reg): string
{
    $base = $reg;
    unset($base['alertas'], $base['eh_venda']);   // eh_venda é comparado à parte
    foreach ($base as $k => $val) {
        if (is_float($val) || (is_string($val) && is_numeric($val) && str_contains($val, '.') && preg_match('/^-?\d+\.\d+$/', $val))) {
            $base[$k] = number_format((float) $val, 2, '.', '');
        } elseif (is_int($val)) {
            $base[$k] = (string) $val;
        }
    }
    ksort($base);
    return hash('sha256', json_encode($base, JSON_UNESCAPED_UNICODE));
}

/**
 * Análise completa (sem gravar). $situacoesVenda: chaves de vendas_situacao_chave() que contam como venda.
 * Retorna:
 *   linhas: [nº => [resultado, chave, motivo, alertas[], registro, venda_id, alteracoes]]
 *   contagens, situacoes (encontradas => qtd), arquivo_ja_importado (lote) …
 */
function vendas_analisar(int $empresaId, string $sistema, array $leitura, array $mapa, array $situacoesVenda, bool $atualizarAlteradas = true, ?string $hoje = null): array
{
    $m = vendas_mapear_cabecalho($leitura['cabecalho']);
    $extrasCols = $m['extras'];
    // Normaliza todas as linhas primeiro (para buscar as vendas existentes de uma vez).
    $norm = [];
    $chaves = $rms = [];
    foreach ($leitura['linhas'] as $n => $l) {
        $v = vendas_normalizar_linha($l, $mapa, (bool) $leitura['data1904'], $hoje);
        $norm[$n] = $v;
        if ($v['venda_id_origem'] !== null) {
            $chaves[$v['venda_id_origem']] = true;
        }
        if ($v['venda_rm'] !== null) {
            $rms[$v['venda_rm']] = true;
        }
    }
    $existPorChave = $existPorRm = [];
    if (vendas_instalado()) {
        foreach (array_chunk(array_merge(array_keys($chaves), array_map(fn ($r) => 'rm:' . $r, array_keys($rms))), 500) as $lote) {
            $ph = implode(',', array_fill(0, count($lote), '?'));
            $st = db()->prepare("SELECT * FROM vendas_externas WHERE empresa_id = ? AND sistema_origem = ? AND chave_origem IN ($ph)");
            $st->execute(array_merge([$empresaId, $sistema], array_map('strval', $lote)));
            foreach ($st->fetchAll() as $row) {
                $existPorChave[$row['chave_origem']] = $row;
            }
        }
        foreach (array_chunk(array_map('strval', array_keys($rms)), 500) as $lote) {
            $ph = implode(',', array_fill(0, count($lote), '?'));
            $st = db()->prepare("SELECT * FROM vendas_externas WHERE empresa_id = ? AND sistema_origem = ? AND venda_rm IN ($ph)");
            $st->execute(array_merge([$empresaId, $sistema], $lote));
            foreach ($st->fetchAll() as $row) {
                $existPorRm[$row['venda_rm']][] = $row;
            }
        }
    }

    $res = [];
    $vistasChave = $vistasRm = [];
    $cont = ['linhas' => count($norm), 'incluida' => 0, 'atualizada' => 0, 'sem_alteracao' => 0, 'ignorada' => 0, 'erro' => 0, 'com_alerta' => 0,
             'repetidas_no_arquivo' => 0, 'alteradas_nao_autorizadas' => 0, 'valor_novas_e_atualizadas' => 0.0];
    $situacoes = [];
    $motivosErro = $motivosAlerta = [];
    foreach ($norm as $n => $v) {
        $sit = vendas_situacao_chave($v['situacao']);
        $r = ['resultado' => null, 'chave' => null, 'motivo' => null, 'alertas' => array_values($v['alertas']), 'registro' => null, 'venda_id' => null, 'alteracoes' => []];
        $chave = $v['venda_id_origem'];
        $existenteRm = null;
        if ($chave === null && $v['venda_rm'] !== null) {
            $doRm = $existPorRm[$v['venda_rm']] ?? [];
            $existenteRm = count($doRm) === 1 ? $doRm[0] : null;      // sem id: acha a venda pela Venda RM
            $chave = $existenteRm ? $existenteRm['chave_origem'] : 'rm:' . $v['venda_rm'];
        }
        $r['chave'] = $chave;
        $erro = $v['problemas'] ? implode(' ', $v['problemas']) : null;
        if (!$erro && $chave !== null && isset($vistasChave[$chave])) {
            $r['resultado'] = 'ignorada';
            $r['motivo'] = 'Repetida no arquivo (mesmo id da linha ' . $vistasChave[$chave] . ').';
            $cont['repetidas_no_arquivo']++;
        }
        if (!$erro && !$r['resultado'] && $v['venda_rm'] !== null) {
            foreach ($existPorRm[$v['venda_rm']] ?? [] as $outra) {
                if ($outra['chave_origem'] !== $chave) {
                    $erro = 'Conflito: a Venda RM ' . $v['venda_rm'] . ' já pertence à venda de id ' . $outra['chave_origem'] . '.';
                }
            }
            if (!$erro && isset($vistasRm[$v['venda_rm']]) && $vistasRm[$v['venda_rm']][0] !== $chave) {
                $erro = 'Conflito: a Venda RM ' . $v['venda_rm'] . ' aparece com outro id na linha ' . $vistasRm[$v['venda_rm']][1] . '.';
            }
        }
        if ($erro) {
            $r['resultado'] = 'erro';
            $r['motivo'] = $erro;
            $motivosErro[preg_replace('/\d+/', 'N', $erro)] = ($motivosErro[preg_replace('/\d+/', 'N', $erro)] ?? 0) + 1;
        } elseif (!$r['resultado']) {
            $vistasChave[$chave] = $n;
            if ($v['venda_rm'] !== null) {
                $vistasRm[$v['venda_rm']] = [$chave, $n];
            }
            $extras = [];
            foreach ($extrasCols as $i => $rot) {
                if (($leitura['linhas'][$n][$i] ?? '') !== '') {
                    $extras[$rot] = mb_substr((string) $leitura['linhas'][$n][$i], 0, 300);
                }
            }
            $reg = vendas_registro($empresaId, $v, $extras);
            $reg['eh_venda'] = in_array($sit, $situacoesVenda, true) ? 1 : 0;
            $r['registro'] = $reg;
            $existente = $existPorChave[$chave] ?? $existenteRm;
            if (!$existente) {
                $r['resultado'] = 'incluida';
            } else {
                $r['venda_id'] = (int) $existente['id'];
                if (hash_equals((string) $existente['hash_conteudo'], vendas_hash_conteudo($reg)) && (int) $existente['eh_venda'] === $reg['eh_venda']) {
                    $r['resultado'] = 'sem_alteracao';
                } else {
                    foreach ($reg as $campo => $novo) {
                        $antigo = $existente[$campo] ?? null;
                        $igual = is_numeric($antigo) && is_numeric($novo) ? abs((float) $antigo - (float) $novo) < 0.005 : (string) $antigo === (string) $novo;
                        if (!$igual && !in_array($campo, ['alertas', 'extras'], true)) {
                            $r['alteracoes'][$campo] = in_array($campo, VENDAS_CAMPOS_PESSOAIS, true) ? 'alterado' : [$antigo, $novo];
                        } elseif (!$igual) {
                            $r['alteracoes'][$campo] = 'alterado';
                        }
                    }
                    if (!$r['alteracoes']) {
                        $r['resultado'] = 'sem_alteracao';
                    } elseif ($atualizarAlteradas) {
                        $r['resultado'] = 'atualizada';
                    } else {
                        $r['resultado'] = 'ignorada';
                        $r['motivo'] = 'Mudou na origem (' . implode(', ', array_keys($r['alteracoes'])) . '), mas a atualização não foi autorizada neste envio.';
                        $cont['alteradas_nao_autorizadas']++;
                    }
                }
            }
            if (in_array($r['resultado'], ['incluida', 'atualizada'], true) && $reg['eh_venda']) {
                $cont['valor_novas_e_atualizadas'] += (float) $reg['valor'];
            }
        }
        if ($r['alertas'] && $r['resultado'] !== 'erro') {
            $cont['com_alerta']++;
            foreach ($v['alertas'] as $txt) {
                $k = preg_replace('/: .*$/', '', $txt);
                $motivosAlerta[$k] = ($motivosAlerta[$k] ?? 0) + 1;
            }
        }
        if (in_array($r['resultado'], ['incluida', 'atualizada', 'sem_alteracao'], true)) {
            $situacoes[$sit] = ($situacoes[$sit] ?? 0) + 1;   // só linhas que ficam gravadas
        }
        $cont[$r['resultado']]++;
        $res[$n] = $r;
    }
    arsort($situacoes);
    return ['linhas' => $res, 'contagens' => $cont, 'situacoes' => $situacoes, 'motivos_erro' => $motivosErro, 'motivos_alerta' => $motivosAlerta,
            'minimizadas' => array_values($m['minimizadas']), 'extras' => array_values($m['extras'])];
}

/** Lote anterior da empresa com o MESMO arquivo (sha256), se houver. */
function vendas_lote_mesmo_arquivo(int $empresaId, string $sha): ?array
{
    if (!vendas_instalado()) {
        return null;
    }
    $st = db()->prepare("SELECT id, criado_em FROM vendas_lotes WHERE empresa_id = :e AND arquivo_sha256 = :s AND status = 'concluido' ORDER BY id DESC LIMIT 1");
    $st->execute(['e' => $empresaId, 's' => $sha]);
    return $st->fetch() ?: null;
}

/**
 * Importa (grava). $origem: ['caminho', 'ext', 'nome'] do arquivo temporário (ou do original guardado, no reprocessamento).
 * Retorna o id do lote. Em falha, o lote fica "falhou" e nada das vendas é gravado (transação).
 */
function vendas_importar(array $usuario, array $origem, array $mapa, array $situacoesVenda, bool $atualizarAlteradas, ?int $reprocessaLoteId = null, ?string $hoje = null): int
{
    if (!vendas_instalado()) {
        throw new RuntimeException('Rode a migração 017 antes de importar vendas.');
    }
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $uid = (int) $usuario['id'];
    $sistema = VENDAS_SISTEMA_HM;
    $leitura = vendas_ler_arquivo($origem['caminho'], $origem['ext']);
    if (!$leitura['ok']) {
        throw new RuntimeException($leitura['erro']);
    }
    $pdo = db();
    $pdo->prepare('INSERT INTO vendas_lotes (empresa_id, sistema_origem, arquivo_nome, arquivo_ext, arquivo_sha256, usuario_id, mapeamento, situacoes_venda,
                   atualizar_alteradas, reprocessa_lote_id, versao_importador, linhas_lidas) VALUES (:e, :s, :n, :x, :h, :u, :m, :sv, :a, :r, :v, :l)')
        ->execute(['e' => $empresaId, 's' => $sistema, 'n' => mb_substr($origem['nome'], 0, 200), 'x' => $origem['ext'], 'h' => hash_file('sha256', $origem['caminho']),
                   'u' => $uid, 'm' => json_encode($mapa), 'sv' => json_encode(array_values($situacoesVenda), JSON_UNESCAPED_UNICODE), 'a' => $atualizarAlteradas ? 1 : 0,
                   'r' => $reprocessaLoteId, 'v' => VENDAS_VERSAO_IMPORTADOR, 'l' => count($leitura['linhas'])]);
    $loteId = (int) $pdo->lastInsertId();
    try {
        $an = vendas_analisar($empresaId, $sistema, $leitura, $mapa, $situacoesVenda, $atualizarAlteradas, $hoje);
        $pdo->beginTransaction();
        $colunas = array_keys(vendas_registro($empresaId, vendas_normalizar_linha([], []), []));
        $colunas[] = 'eh_venda';
        $ins = $pdo->prepare('INSERT INTO vendas_externas (empresa_id, sistema_origem, chave_origem, primeiro_lote_id, ultimo_lote_id, hash_conteudo, '
            . implode(', ', $colunas) . ') VALUES (:empresa_id, :sistema_origem, :chave_origem, :lote, :lote2, :hash_conteudo, :' . implode(', :', $colunas) . ')');
        $upd = $pdo->prepare('UPDATE vendas_externas SET ultimo_lote_id = :lote, hash_conteudo = :hash_conteudo, atualizado_em = NOW(), '
            . implode(', ', array_map(fn ($c) => "$c = :$c", $colunas)) . ' WHERE id = :id AND empresa_id = :empresa_id');
        $toca = $pdo->prepare('UPDATE vendas_externas SET ultimo_lote_id = :lote WHERE id = :id AND empresa_id = :e');
        $ver = $pdo->prepare('INSERT INTO vendas_externas_versoes (venda_id, lote_id, usuario_id, alteracoes) VALUES (:v, :l, :u, :a)');
        $lin = $pdo->prepare('INSERT INTO vendas_lote_linhas (lote_id, linha_num, chave_origem, resultado, venda_id, motivo, alertas) VALUES (:l, :n, :c, :r, :v, :m, :a)');
        foreach ($an['linhas'] as $n => $r) {
            $vendaId = $r['venda_id'];
            if ($r['resultado'] === 'incluida' || $r['resultado'] === 'atualizada') {
                $reg = $r['registro'];
                $hash = vendas_hash_conteudo($reg);
                if ($r['resultado'] === 'incluida') {
                    $ins->execute(array_merge($reg, ['empresa_id' => $empresaId, 'sistema_origem' => $sistema, 'chave_origem' => $r['chave'], 'lote' => $loteId, 'lote2' => $loteId, 'hash_conteudo' => $hash]));
                    $vendaId = (int) $pdo->lastInsertId();
                } else {
                    $upd->execute(array_merge($reg, ['lote' => $loteId, 'hash_conteudo' => $hash, 'id' => $vendaId, 'empresa_id' => $empresaId]));
                    $ver->execute(['v' => $vendaId, 'l' => $loteId, 'u' => $uid, 'a' => json_encode($r['alteracoes'], JSON_UNESCAPED_UNICODE)]);
                }
            } elseif ($r['resultado'] === 'sem_alteracao' && $vendaId) {
                $toca->execute(['lote' => $loteId, 'id' => $vendaId, 'e' => $empresaId]);
            }
            $lin->execute(['l' => $loteId, 'n' => $n, 'c' => $r['chave'] !== null ? mb_substr($r['chave'], 0, 60) : null, 'r' => $r['resultado'], 'v' => $vendaId,
                           'm' => $r['motivo'] !== null ? mb_substr($r['motivo'], 0, 300) : null, 'a' => $r['alertas'] ? mb_substr(implode(' | ', $r['alertas']), 0, 600) : null]);
        }
        $c = $an['contagens'];
        $pdo->prepare("UPDATE vendas_lotes SET status = 'concluido', concluido_em = NOW(), incluidas = :i, atualizadas = :a, sem_alteracao = :s, ignoradas = :g,
                       com_erro = :r, com_alerta = :al WHERE id = :id")
            ->execute(['i' => $c['incluida'], 'a' => $c['atualizada'], 's' => $c['sem_alteracao'], 'g' => $c['ignorada'], 'r' => $c['erro'], 'al' => $c['com_alerta'], 'id' => $loteId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->prepare("UPDATE vendas_lotes SET status = 'falhou', erro = :m, concluido_em = NOW() WHERE id = :id")
            ->execute(['m' => mb_substr($e->getMessage(), 0, 300), 'id' => $loteId]);
        throw $e;
    }
    // Guarda o original (fora da pasta pública) até o prazo de descarte; reprocessamento usa o do lote original.
    if ($reprocessaLoteId === null) {
        $destino = vendas_arquivo_lote($loteId, $origem['ext']);
        if ($destino && @copy($origem['caminho'], $destino)) {
            @chmod($destino, 0640);
            $pdo->prepare('UPDATE vendas_lotes SET arquivo_guardado = 1, descartar_arquivo_em = DATE_ADD(NOW(), INTERVAL ' . VENDAS_RETENCAO_DIAS . ' DAY) WHERE id = :id')
                ->execute(['id' => $loteId]);
        }
    }
    auditoria_registrar($empresaId, $uid, $reprocessaLoteId ? 'vendas_reprocessar' : 'vendas_importar', 'vendas_lote', $loteId, [
        'arquivo' => mb_substr($origem['nome'], 0, 120), 'linhas' => $an['contagens']['linhas'], 'incluidas' => $an['contagens']['incluida'],
        'atualizadas' => $an['contagens']['atualizada'], 'sem_alteracao' => $an['contagens']['sem_alteracao'], 'ignoradas' => $an['contagens']['ignorada'],
        'com_erro' => $an['contagens']['erro'], 'reprocessa_lote' => $reprocessaLoteId,
    ]);
    return $loteId;
}

/** Lote da empresa (ou null). */
function vendas_lote_carregar(int $empresaId, int $loteId): ?array
{
    if (!vendas_instalado()) {
        return null;
    }
    $st = db()->prepare('SELECT l.*, u.nome AS usuario_nome FROM vendas_lotes l LEFT JOIN usuarios u ON u.id = l.usuario_id WHERE l.id = :id AND l.empresa_id = :e');
    $st->execute(['id' => $loteId, 'e' => $empresaId]);
    return $st->fetch() ?: null;
}

function vendas_lotes_listar(int $empresaId, int $limite = 50): array
{
    if (!vendas_instalado()) {
        return [];
    }
    $st = db()->prepare('SELECT l.*, u.nome AS usuario_nome FROM vendas_lotes l LEFT JOIN usuarios u ON u.id = l.usuario_id WHERE l.empresa_id = :e ORDER BY l.id DESC LIMIT ' . max(1, min(500, $limite)));
    $st->execute(['e' => $empresaId]);
    return $st->fetchAll();
}

/** Reprocessa um lote a partir do original guardado, com o mesmo mapeamento. Idempotente: o que não mudou fica "sem alteração". */
function vendas_reprocessar(array $usuario, int $loteId): int
{
    $lote = vendas_lote_carregar((int) $usuario['empresa_ativa_id'], $loteId);
    if (!$lote) {
        throw new RuntimeException('Lote não encontrado.');
    }
    $orig = $lote['reprocessa_lote_id'] ? vendas_lote_carregar((int) $usuario['empresa_ativa_id'], (int) $lote['reprocessa_lote_id']) : $lote;
    $caminho = $orig ? vendas_arquivo_lote((int) $orig['id'], $orig['arquivo_ext']) : null;
    if (!$orig || !(int) $orig['arquivo_guardado'] || !$caminho || !is_file($caminho)) {
        throw new RuntimeException('O arquivo original deste lote já foi descartado (prazo de ' . VENDAS_RETENCAO_DIAS . ' dias). Envie a planilha de novo.');
    }
    return vendas_importar($usuario, ['caminho' => $caminho, 'ext' => $orig['arquivo_ext'], 'nome' => $orig['arquivo_nome']],
        json_decode($lote['mapeamento'], true) ?: [], json_decode($lote['situacoes_venda'], true) ?: [], (bool) $lote['atualizar_alteradas'], (int) $orig['id']);
}

/** Cron: apaga os originais vencidos. Os dados normalizados continuam. */
function vendas_descartar_arquivos(): int
{
    if (!vendas_instalado()) {
        return 0;
    }
    $n = 0;
    foreach (db()->query('SELECT id, arquivo_ext FROM vendas_lotes WHERE arquivo_guardado = 1 AND descartar_arquivo_em <= NOW()')->fetchAll() as $l) {
        $c = vendas_arquivo_lote((int) $l['id'], $l['arquivo_ext']);
        if ($c && is_file($c)) {
            @unlink($c);
        }
        db()->prepare('UPDATE vendas_lotes SET arquivo_guardado = 0 WHERE id = :id')->execute(['id' => $l['id']]);
        $n++;
    }
    return $n;
}
