<?php
/**
 * Inteligência de Vendas: leitura e normalização da planilha de vendas
 * do sistema comercial externo (HM). Funções PURAS: nada é gravado aqui.
 *
 * - Dicionário de colunas conhecidas → campos canônicos (com categoria).
 * - Datas do Excel (número serial) e texto (dd/mm/aaaa, aaaa-mm-dd).
 * - Telefone → formato internacional só com dígitos (55 + DDD + número),
 *   sem inventar DDD nem "consertar" número inválido.
 * - E-mail → minúsculas e sem espaços nas pontas; NÃO remove pontos nem
 *   "+alias" (cada provedor tem regra própria).
 * - CPF → só para validação/estatística (não é usado no cruzamento v1).
 */
require_once __DIR__ . '/../importacao.php';

const VENDAS_SISTEMA_HM = 'hm_comercial';

/** DDDs brasileiros válidos (Anatel). */
const VENDAS_DDDS = [11, 12, 13, 14, 15, 16, 17, 18, 19, 21, 22, 24, 27, 28, 31, 32, 33, 34, 35, 37, 38, 41, 42, 43, 44, 45, 46, 47, 48, 49,
    51, 53, 54, 55, 61, 62, 63, 64, 65, 66, 67, 68, 69, 71, 73, 74, 75, 77, 79, 81, 82, 83, 84, 85, 86, 87, 88, 89, 91, 92, 93, 94, 95, 96, 97, 98, 99];

/**
 * Campos canônicos: campo => [rótulos aceitos (normalizados), categoria, rótulo para tela].
 * Categorias: chave, venda, pessoal, comercial, financeiro.
 */
function vendas_campos(): array
{
    return [
        'venda_id_origem'     => [['id', 'id venda', 'id proposta'], 'chave', 'Id da venda (origem)'],
        'venda_rm'            => [['venda rm', 'rm'], 'chave', 'Venda RM'],
        'situacao'            => [['situacao proposta', 'situacao', 'status proposta', 'status'], 'venda', 'Situação da proposta'],
        'data_venda'          => [['datahoravenda', 'data hora venda', 'data venda', 'data da venda'], 'venda', 'Data da venda'],
        'data_cadastro_origem' => [['datacadastro', 'data cadastro'], 'venda', 'Data de cadastro (sistema externo)'],
        'data_envio_rm'       => [['data envio rm'], 'venda', 'Data de envio ao RM'],
        'cliente_nome'        => [['cliente', 'nome cliente', 'comprador'], 'pessoal', 'Cliente'],
        'telefone'            => [['telefone', 'fone'], 'pessoal', 'Telefone'],
        'celular'             => [['celular', 'whatsapp'], 'pessoal', 'Celular'],
        'email'               => [['email', 'e mail'], 'pessoal', 'E-mail'],
        'cpf'                 => [['cpf'], 'pessoal', 'CPF'],
        'empreendimento'      => [['empreendimento'], 'comercial', 'Empreendimento'],
        'bloco'               => [['bloco', 'torre'], 'comercial', 'Bloco'],
        'unidade'             => [['unidade', 'apto', 'apartamento', 'lote'], 'comercial', 'Unidade'],
        'imobiliaria'         => [['imobiliaria'], 'comercial', 'Imobiliária'],
        'corretor_id_origem'  => [['id corretor'], 'comercial', 'Id do corretor (origem)'],
        'corretor_nome'       => [['corretor', 'vendedor'], 'comercial', 'Corretor (origem)'],
        'superintendente'     => [['superintendente'], 'comercial', 'Superintendente (origem)'],
        'gerente'             => [['gerente'], 'comercial', 'Gerente (origem)'],
        'coordenador'         => [['coordenador'], 'comercial', 'Coordenador (origem)'],
        'midia'               => [['midia', 'mídia', 'canal'], 'comercial', 'Mídia declarada na venda'],
        'modalidade'          => [['modalidadevenda', 'modalidade venda', 'modalidade'], 'financeiro', 'Modalidade'],
        'valor'               => [['valor', 'valor venda'], 'financeiro', 'Valor da venda'],
        'tabela'              => [['tabela', 'valor tabela'], 'financeiro', 'Valor de tabela'],
        'desconto'            => [['desconto'], 'financeiro', 'Desconto'],
        'entrada'             => [['entrada'], 'financeiro', 'Entrada'],
        'subsidio'            => [['subsidio'], 'financeiro', 'Subsídio'],
        'fgts'                => [['fgts'], 'financeiro', 'FGTS'],
        'financiamento'       => [['financiamento'], 'financeiro', 'Financiamento'],
        'comissao_cliente'    => [['comissao cliente'], 'financeiro', 'Comissão cliente'],
        'comissao_empresa'    => [['comissao empresa'], 'financeiro', 'Comissão empresa'],
        'valor_pagamento'     => [['valor pagamento'], 'financeiro', 'Valor pagamento'],
    ];
}

/**
 * Colunas que NÃO são guardadas por minimização (LGPD): dados pessoais sem
 * uso no cruzamento nem nos relatórios. Aparecem na tela como "não
 * importadas (LGPD)" — nunca descartadas em silêncio.
 */
function vendas_colunas_minimizadas(): array
{
    return ['data nascimento', 'genero', 'logradouro', 'numero', 'bairro', 'cep', 'renda', 'renda2', 'renda total', 'tipo renda2',
        'profissao', 'comprador 2', 'cpf 2', 'data nascimento comprador 2', 'fiador', 'cidade', 'uf', 'observacoes', 'brinde'];
}

/**
 * Mapeia o cabeçalho. Retorna:
 *   mapa         [campo => índice]
 *   minimizadas  [índice => rótulo]  (não guardadas, LGPD)
 *   extras       [índice => rótulo]  (sem campo próprio; guardadas como metadados da linha)
 *   duplicadas   [rótulo, ...]       (cabeçalho repetido: só a 1ª ocorrência vale)
 */
function vendas_mapear_cabecalho(array $cabecalho): array
{
    $alvo = [];
    foreach (vendas_campos() as $campo => [$rotulos]) {
        foreach ($rotulos as $r) {
            $alvo[importacao_normalizar_rotulo($r)] ??= $campo;
        }
    }
    $min = array_map('importacao_normalizar_rotulo', vendas_colunas_minimizadas());
    $mapa = $minimizadas = $extras = $duplicadas = [];
    foreach ($cabecalho as $i => $rot) {
        $n = trim(importacao_normalizar_rotulo((string) $rot));
        $n = preg_replace('/\s+/', ' ', $n);
        if ($n === '') {
            continue;
        }
        $campo = $alvo[$n] ?? null;
        if ($campo !== null) {
            if (isset($mapa[$campo])) {
                $duplicadas[] = (string) $rot;
            } else {
                $mapa[$campo] = $i;
            }
        } elseif (in_array($n, $min, true)) {
            $minimizadas[$i] = trim((string) $rot);
        } else {
            $extras[$i] = trim((string) $rot);
        }
    }
    return ['mapa' => $mapa, 'minimizadas' => $minimizadas, 'extras' => $extras, 'duplicadas' => $duplicadas];
}

/** O workbook usa o calendário de 1904 (Excel antigo de Mac)? */
function vendas_xlsx_data1904(string $caminho): bool
{
    $zip = new ZipArchive();
    if ($zip->open($caminho) !== true) {
        return false;
    }
    $xml = (string) $zip->getFromName('xl/workbook.xml', 200000);
    $zip->close();
    return (bool) preg_match('/date1904\s*=\s*"(1|true)"/i', $xml);
}

/**
 * Converte data da planilha. Retorna ['valor' => 'Y-m-d H:i:s'|null,
 * 'so_data' => bool, 'problema' => null|'vazia'|'ilegivel'|'sentinela'|'futura'].
 * - Número: serial do Excel (base 1899-12-30, ou 1904-01-01).
 * - Texto: dd/mm/aaaa [hh:mm[:ss]] ou aaaa-mm-dd [hh:mm[:ss]].
 * - Datas antes de 2000 (ex.: 31/12/1969, "data zero" de outro sistema)
 *   são sentinelas: inválidas, nunca usadas.
 * - "futura": depois de $hoje (+1 dia de folga).
 */
function vendas_data(string $bruto, bool $data1904 = false, ?string $hoje = null): array
{
    $bruto = trim($bruto);
    $r = ['valor' => null, 'so_data' => true, 'problema' => null];
    if ($bruto === '') {
        $r['problema'] = 'vazia';
        return $r;
    }
    $dt = null;
    if (is_numeric($bruto) && (float) $bruto > 0 && (float) $bruto < 2958466) {
        $serial = (float) $bruto;
        $dias = (int) floor($serial);
        $seg = (int) round(($serial - $dias) * 86400);
        $base = new DateTimeImmutable($data1904 ? '1904-01-01 00:00:00' : '1899-12-30 00:00:00');
        $dt = $base->modify("+{$dias} days")->modify("+{$seg} seconds");
        $r['so_data'] = $seg === 0;
    } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $bruto, $m)) {
        if (checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            $dt = new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0));
            $r['so_data'] = !isset($m[4]) || $m[4] === '';
        }
    } elseif (preg_match('#^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?#', $bruto, $m)) {
        if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $dt = new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0));
            $r['so_data'] = !isset($m[4]) || $m[4] === '';
        }
    }
    if (!$dt) {
        $r['problema'] = 'ilegivel';
        return $r;
    }
    if ((int) $dt->format('Y') < 2000) {
        $r['problema'] = 'sentinela';
        return $r;
    }
    $r['valor'] = $dt->format('Y-m-d H:i:s');
    $limite = (new DateTimeImmutable($hoje ?? 'today'))->modify('+1 day')->format('Y-m-d 23:59:59');
    if ($r['valor'] > $limite) {
        $r['problema'] = 'futura';
    }
    return $r;
}

/**
 * Telefone → ['e164' => '55DDDNUMERO'|null, 'tipo' => 'celular'|'fixo'|null,
 * 'problema' => null|'vazio'|'tamanho'|'ddd'|'celular_sem_9'|'estrangeiro'].
 * Não acrescenta DDD, não acrescenta nem remove o 9: número fora do padrão
 * fica sem e164 (não entra no cruzamento automático).
 */
function vendas_telefone(string $bruto): array
{
    $r = ['e164' => null, 'tipo' => null, 'problema' => null];
    $bruto = trim($bruto);
    if ($bruto === '' || preg_match('/^0+$/', $bruto)) {
        $r['problema'] = 'vazio';
        return $r;
    }
    $internacional = str_starts_with($bruto, '+') || str_starts_with($bruto, '00');
    $d = preg_replace('/\D+/', '', $bruto);
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2);
    }
    if ($internacional && !str_starts_with($d, '55')) {
        $r['problema'] = 'estrangeiro';
        return $r;
    }
    if (!$internacional) {
        if ((strlen($d) === 12 || strlen($d) === 13) && str_starts_with($d, '55')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 11 || strlen($d) === 12) {
            $d = ltrim($d, '0');                  // 0 + DDD (discagem nacional)
        }
    } else {
        $d = substr($d, 2);
    }
    if (strlen($d) !== 10 && strlen($d) !== 11) {
        $r['problema'] = 'tamanho';
        return $r;
    }
    if (!in_array((int) substr($d, 0, 2), VENDAS_DDDS, true)) {
        $r['problema'] = 'ddd';
        return $r;
    }
    $n = substr($d, 2);
    if (strlen($n) === 9) {
        if ($n[0] !== '9') {
            $r['problema'] = 'tamanho';
            return $r;
        }
        $r['tipo'] = 'celular';
    } elseif (in_array($n[0], ['2', '3', '4', '5'], true)) {
        $r['tipo'] = 'fixo';
    } else {
        // 8 dígitos começando com 6–9: celular antigo, sem o 9. Não é
        // completado automaticamente.
        $r['problema'] = 'celular_sem_9';
        return $r;
    }
    $r['e164'] = '55' . $d;
    return $r;
}

/** E-mail → ['email' => string|null, 'problema' => null|'vazio'|'invalido']. */
function vendas_email(string $bruto): array
{
    $e = mb_strtolower(trim($bruto));
    if ($e === '') {
        return ['email' => null, 'problema' => 'vazio'];
    }
    if (!filter_var($e, FILTER_VALIDATE_EMAIL) || !preg_match('/\.[a-z]{2,}$/', $e)) {
        return ['email' => null, 'problema' => 'invalido'];
    }
    return ['email' => $e, 'problema' => null];
}

/** CPF → ['cpf' => 11 dígitos|null, 'zeros_recuperados' => bool, 'problema' => null|'vazio'|'invalido']. */
function vendas_cpf(string $bruto): array
{
    $d = preg_replace('/\D+/', '', $bruto);
    if ($d === '') {
        return ['cpf' => null, 'zeros_recuperados' => false, 'problema' => 'vazio'];
    }
    $zeros = false;
    if (strlen($d) >= 9 && strlen($d) < 11 && ctype_digit(trim($bruto))) {
        $d = str_pad($d, 11, '0', STR_PAD_LEFT);   // gravado como número: perdeu zeros à esquerda
        $zeros = true;
    }
    if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) {
        return ['cpf' => null, 'zeros_recuperados' => $zeros, 'problema' => 'invalido'];
    }
    for ($t = 9; $t < 11; $t++) {
        $s = 0;
        for ($i = 0; $i < $t; $i++) {
            $s += (int) $d[$i] * (($t + 1) - $i);
        }
        if ((int) $d[$t] !== ((10 * $s) % 11) % 10) {
            return ['cpf' => null, 'zeros_recuperados' => $zeros, 'problema' => 'invalido'];
        }
    }
    return ['cpf' => $d, 'zeros_recuperados' => $zeros, 'problema' => null];
}

/** Valor monetário: "316800", "316800.5", "R$ 316.800,50", "316,800.50". null se ilegível. */
function vendas_valor(string $bruto): ?float
{
    $s = preg_replace('/[^\d,.\-]/', '', trim($bruto));
    if ($s === '' || $s === '-') {
        return null;
    }
    $virg = strrpos($s, ',');
    $ponto = strrpos($s, '.');
    if ($virg !== false && ($ponto === false || $virg > $ponto)) {
        $s = str_replace(['.', ','], ['', '.'], $s);       // 1.234,56
    } else {
        $s = str_replace(',', '', $s);                      // 1,234.56 ou 1234.56
    }
    return is_numeric($s) ? round((float) $s, 2) : null;
}

/** Texto limpo (espaços duplicados e nas pontas). */
function vendas_texto(string $bruto, int $max = 190): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $bruto)), 0, $max);
}

/** Nome de empreendimento comparável (sem acento, sem caixa, sem pontuação). Só sugere equivalência; nunca decide sozinho. */
function vendas_nome_comparavel(string $s): string
{
    $s = importacao_normalizar_rotulo($s);
    $s = strtr($s, ['è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ü' => 'u', 'ï' => 'i']);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Normaliza UMA linha mapeada. Retorna os campos canônicos limpos, os
 * contatos normalizados e a lista de problemas (código => descrição).
 * $hoje permite testes determinísticos.
 */
function vendas_normalizar_linha(array $linha, array $mapa, bool $data1904 = false, ?string $hoje = null): array
{
    $pega = fn (string $c) => isset($mapa[$c]) ? trim((string) ($linha[$mapa[$c]] ?? '')) : '';
    $out = ['problemas' => [], 'alertas' => []];
    foreach (['venda_id_origem', 'venda_rm'] as $c) {
        $v = $pega($c);
        $out[$c] = preg_match('/^\d+(\.0+)?$/', $v) ? (string) (int) $v : ($v !== '' ? vendas_texto($v, 60) : null);
    }
    if ($out['venda_id_origem'] === null && $out['venda_rm'] === null) {
        $out['problemas']['sem_identificador'] = 'Sem "id" e sem "Venda RM": não dá para evitar duplicidade.';
    }
    $out['situacao'] = $pega('situacao') !== '' ? vendas_texto($pega('situacao'), 60) : null;

    $dv = vendas_data($pega('data_venda'), $data1904, $hoje);
    $out['data_venda'] = $dv['valor'];
    $out['data_venda_so_data'] = $dv['so_data'];
    if ($dv['problema'] !== null && $dv['problema'] !== 'futura') {
        $out['problemas']['data_venda'] = 'Data da venda ' . ($dv['problema'] === 'vazia' ? 'vazia' : 'inválida') . '.';
        $out['data_venda'] = null;
    } elseif ($dv['problema'] === 'futura') {
        $out['problemas']['data_venda'] = 'Data da venda no futuro.';
    }
    $dc = vendas_data($pega('data_cadastro_origem'), $data1904, $hoje);
    $out['data_cadastro_origem'] = $dc['valor'];
    if ($dc['problema'] === 'sentinela' || $dc['problema'] === 'ilegivel') {
        $out['alertas']['data_cadastro'] = 'Data de cadastro do sistema externo inválida (ignorada).';
    } elseif ($dc['problema'] === 'futura') {
        $out['alertas']['data_cadastro'] = 'Data de cadastro do sistema externo no futuro (possível dia/mês trocados; ignorada).';
        $out['data_cadastro_origem'] = null;
    } elseif ($dc['valor'] && $out['data_venda'] && substr($dc['valor'], 0, 10) > substr($out['data_venda'], 0, 10)) {
        $out['alertas']['data_cadastro'] = 'Data de cadastro do sistema externo depois da venda.';
    }
    $de = vendas_data($pega('data_envio_rm'), $data1904, $hoje);
    $out['data_envio_rm'] = $de['problema'] === null ? $de['valor'] : null;

    $out['cliente_nome'] = vendas_texto($pega('cliente_nome'));
    $out['telefones'] = [];
    $out['telefones_invalidos'] = [];
    $out['telefones_brutos'] = [];
    foreach (['celular', 'telefone'] as $c) {
        if ($pega($c) === '') {
            continue;
        }
        $t = vendas_telefone($pega($c));
        if ($t['e164']) {
            if (!in_array($t['e164'], $out['telefones'], true)) {
                $out['telefones'][] = $t['e164'];         // lista de strings (chave numérica viraria int)
            }
        } elseif ($t['problema'] !== 'vazio') {
            $out['telefones_invalidos'][] = $c . ':' . $t['problema'];
            if ($t['problema'] === 'celular_sem_9') {
                $out['telefones_brutos'][] = $pega($c);   // só para apontar a variante com 9 (revisão humana)
            }
        }
    }
    $em = vendas_email($pega('email'));
    $out['email'] = $em['email'];
    if ($em['problema'] === 'invalido') {
        $out['alertas']['email'] = 'E-mail com formato inválido (não usado no cruzamento).';
    }
    if ($out['telefones_invalidos']) {
        $out['alertas']['telefone'] = 'Telefone fora do padrão (não usado no cruzamento): ' . implode(', ', $out['telefones_invalidos']) . '.';
    }
    if (!$out['telefones'] && !$out['email']) {
        $out['alertas']['sem_contato'] = 'Sem telefone nem e-mail válidos: não há como cruzar com o CRM.';
    }
    $cpf = vendas_cpf($pega('cpf'));
    $out['cpf'] = $cpf['cpf'];
    $out['cpf_problema'] = $cpf['problema'];
    $out['cpf_zeros_recuperados'] = $cpf['zeros_recuperados'];

    foreach (['empreendimento', 'bloco', 'unidade', 'imobiliaria', 'corretor_nome', 'superintendente', 'gerente', 'coordenador', 'midia', 'modalidade'] as $c) {
        $out[$c] = vendas_texto($pega($c));
    }
    $out['corretor_id_origem'] = preg_match('/^\d+(\.0+)?$/', $pega('corretor_id_origem')) ? (string) (int) $pega('corretor_id_origem') : ($pega('corretor_id_origem') ?: null);
    if ($out['empreendimento'] === '') {
        $out['problemas']['empreendimento'] = 'Empreendimento vazio.';
    }
    foreach (['valor', 'tabela', 'desconto', 'entrada', 'subsidio', 'fgts', 'financiamento', 'comissao_cliente', 'comissao_empresa', 'valor_pagamento'] as $c) {
        $out[$c] = $pega($c) === '' ? null : vendas_valor($pega($c));
    }
    if ($out['valor'] === null || $out['valor'] <= 0) {
        $out['alertas']['valor'] = 'Valor da venda ausente ou zero.';
    }
    return $out;
}
