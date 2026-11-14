<?php
/**
 * PDF executivo de um relatório da Central (identidade WeMart Sales):
 * faixa de título, cabeçalho (empresa, período, filtros, data/hora, fuso),
 * indicadores com variação, gráfico de barras da evolução, tabelas
 * paginadas (cabeçalho repetido) e notas/definições. Mesmos dados do Excel.
 */
require_once __DIR__ . '/../pdf_simples.php';
require_once __DIR__ . '/exportar.php';

const RELPDF_ACENTO = [99, 91, 250];
const RELPDF_TINTA = [21, 26, 45];
const RELPDF_CINZA = [104, 114, 138];
const RELPDF_CLARO = [238, 236, 255];
const RELPDF_FUNDO = [246, 247, 251];
const RELPDF_VERDE = [23, 137, 88];
const RELPDF_VERMELHO = [194, 60, 81];

/** Valor de célula para o PDF. */
function relpdf_valor($v, string $tipo): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    switch ($tipo) {
        case 'int':
            return is_numeric($v) ? number_format((float) $v, 0, ',', '.') : (string) $v;
        case 'dec1':
            return is_numeric($v) ? number_format((float) $v, 1, ',', '.') : (string) $v;
        case 'moeda':
            return is_numeric($v) ? 'R$ ' . number_format((float) $v, 2, ',', '.') : (string) $v;
        case 'data':
            return ($ts = strtotime((string) $v)) ? date('d/m/Y', $ts) : (string) $v;
        case 'datahora':
            return ($ts = strtotime((string) $v)) ? date('d/m/Y H:i', $ts) : (string) $v;
        default:
            return (string) $v;
    }
}

/** Gera o PDF em $destino; retorna o número de páginas. */
function relpdf_gerar(array $d, string $destino): int
{
    $pdf = new PdfSimples();
    $M = 40;
    $W = $pdf->largura - 2 * $M;
    $base = $pdf->altura - 50; // limite inferior do conteúdo
    $y = 0;
    $pdf->rodape = function (PdfSimples $p, int $pag, int $tot) use ($d, $M) {
        $p->linha($M, $p->altura - 34, $p->largura - $M, $p->altura - 34);
        $p->fonte(false, 7);
        $p->texto($M, $p->altura - 22, $p->caber('WeMart Sales · ' . $d['empresa'] . ' · ' . $d['titulo'] . ' · gerado em ' . $d['gerado_em'], 420), RELPDF_CINZA);
        $p->texto($p->largura - $M, $p->altura - 22, 'Página ' . $pag . ' de ' . $tot, RELPDF_CINZA, 'D');
    };
    $nova = function () use ($pdf, &$y, $M, $W, $d) {
        $pdf->novaPagina();
        $pdf->retangulo(0, 0, $pdf->largura, 6, RELPDF_ACENTO);
        $pdf->fonte(true, 8);
        $pdf->texto($M, 24, 'WeMart Sales', RELPDF_ACENTO);
        $pdf->fonte(false, 8);
        $pdf->texto($M + $W, 24, $pdf->caber($d['titulo'] . ' · ' . $d['periodo'], $W - 80), RELPDF_CINZA, 'D');
        $y = 44;
    };
    $espaco = function (float $h) use (&$y, $base, $nova) {
        if ($y + $h > $base) {
            $nova();
        }
    };

    // ------------------------------------------------ capa / cabeçalho
    $pdf->novaPagina();
    $pdf->retangulo(0, 0, $pdf->largura, 92, RELPDF_ACENTO);
    $pdf->fonte(true, 11);
    $pdf->texto($M, 30, 'WeMart Sales', [255, 255, 255]);
    $pdf->fonte(true, 20);
    $pdf->texto($M, 58, $pdf->caber($d['titulo'], $W), [255, 255, 255]);
    $pdf->fonte(false, 10);
    $pdf->texto($M, 78, $pdf->caber($d['empresa'] . ' · ' . $d['periodo'], $W), [230, 228, 255]);
    $y = 114;
    $pdf->fonte(false, 8.5);
    $meta = [];
    if ($d['comparado']) {
        $meta[] = ['Comparado com', $d['comparado'] . ' (mesma duração)'];
    }
    $meta[] = ['Filtros', $d['filtros'] ? implode(' · ', array_map(fn ($x) => $x[0] . ': ' . $x[1], $d['filtros'])) : 'Nenhum — tudo o que o usuário pode ver'];
    $meta[] = ['Gerado em', $d['gerado_em'] . ' · fuso ' . $d['fuso'] . ' · por ' . $d['usuario']];
    foreach ($meta as [$r, $v]) {
        $pdf->fonte(true, 8.5);
        $pdf->texto($M, $y, $r, RELPDF_CINZA);
        $pdf->fonte(false, 8.5);
        foreach ($pdf->quebrar($v, $W - 90) as $l) {
            $pdf->texto($M + 90, $y, $l);
            $y += 12;
        }
    }
    $y += 8;

    // ------------------------------------------------ indicadores
    if ($d['indicadores']) {
        $col = 3;
        $gap = 10;
        $cw = ($W - ($col - 1) * $gap) / $col;
        $ch = 62;
        foreach (array_chunk($d['indicadores'], $col) as $linha) {
            $espaco($ch + $gap);
            foreach ($linha as $i => $ind) {
                $x = $M + $i * ($cw + $gap);
                $pdf->retangulo($x, $y, $cw, $ch, RELPDF_FUNDO);
                $pdf->retangulo($x, $y, 3, $ch, RELPDF_ACENTO);
                $pdf->fonte(true, 7.5);
                $ly = $y + 13;
                foreach (array_slice($pdf->quebrar($ind['nome'], $cw - 18), 0, 2) as $l) {
                    $pdf->texto($x + 10, $ly, $l, RELPDF_CINZA);
                    $ly += 9;
                }
                $pdf->fonte(true, 15);
                $pdf->texto($x + 10, $y + 44, $pdf->caber(relui_valor($ind['valor'], $ind['unidade']), $cw - 18));
                [$vt, $vc] = $ind['anterior'] !== null || $ind['variacao'] !== null ? relui_variacao($ind) : ['', ''];
                if ($vt !== '') {
                    $pdf->fonte(false, 7);
                    $pdf->texto($x + 10, $y + 56, $pdf->caber($vt, $cw - 18), $vc === 'up' ? RELPDF_VERDE : ($vc === 'down' ? RELPDF_VERMELHO : RELPDF_CINZA));
                }
            }
            $y += $ch + $gap;
        }
        $y += 6;
    }

    // ------------------------------------------------ gráfico
    if (!empty($d['serie']['pontos'])) {
        $pts = $d['serie']['pontos'];
        $gh = 150;
        $espaco($gh + 40);
        $pdf->fonte(true, 11);
        $pdf->texto($M, $y + 12, $d['serie']['titulo']);
        $y += 24;
        $max = max(1, max(array_column($pts, 'total')));
        $topo = $max <= 4 ? 4 : (int) (ceil($max / 4) * 4);
        $ex = $M + 28;
        $ew = $W - 28;
        $pdf->fonte(false, 7);
        for ($g = 0; $g <= 4; $g++) {
            $gy = $y + $gh - $gh * $g / 4;
            $pdf->linha($ex, $gy, $ex + $ew, $gy, $g === 0 ? [180, 186, 200] : [236, 238, 244]);
            $pdf->texto($ex - 5, $gy + 2.5, number_format($topo * $g / 4, 0, ',', '.'), RELPDF_CINZA, 'D');
        }
        $n = count($pts);
        $slot = $ew / $n;
        $bw = max(1.5, min(28, $slot * 0.7));
        $passo = max(1, (int) ceil($n / 10));
        foreach ($pts as $i => $p) {
            $bh = $gh * $p['total'] / $topo;
            $bx = $ex + $i * $slot + ($slot - $bw) / 2;
            if ($bh > 0) {
                $pdf->retangulo($bx, $y + $gh - $bh, $bw, $bh, RELPDF_ACENTO);
            }
            if ($i % $passo === 0 && ($n - 1 - $i >= $passo * 0.75 || $i === $n - 1) || $i === $n - 1) {
                $pdf->texto($ex + $i * $slot + $slot / 2, $y + $gh + 11, $p['rotulo'], RELPDF_CINZA, 'C');
            }
        }
        $y += $gh + 26;
        $pdf->fonte(false, 7);
        $pdf->texto($M, $y - 6, 'Total no período: ' . number_format(array_sum(array_column($pts, 'total')), 0, ',', '.') . '. Valores de cada intervalo no Excel (aba Detalhamento).', RELPDF_CINZA);
        $y += 8;
    }

    // ------------------------------------------------ tabelas
    foreach ($d['tabelas'] as $tab) {
        if (str_starts_with($tab['titulo'], 'Evolução') && !empty($d['serie'])) {
            continue; // já está no gráfico; a série completa vai no Excel
        }
        $cols = $tab['colunas'];
        $nc = count($cols);
        $fs = $nc > 8 ? 7 : 8;
        // Larguras pelo conteúdo: a maior palavra do título e o maior valor de cada
        // coluna; o que sobrar vai para as colunas de texto. Se não couber, a fonte diminui.
        $linhasAmostra = array_slice(array_merge($tab['linhas'], $tab['totais'] ? [$tab['totais']] : []), 0, 300);
        for ($tent = 0; $tent < 4; $tent++) {
            $need = [];
            foreach (array_values($cols) as $j => [$rot, $tipo]) {
                $k = array_keys($cols)[$j];
                $pdf->fonte(true, $fs);
                $palavra = 0;
                foreach (preg_split('/\s+/', $rot) as $p) {
                    $palavra = max($palavra, $pdf->larguraTexto($p));
                }
                $pdf->fonte(false, $fs);
                $valor = 0;
                foreach ($linhasAmostra as $l) {
                    $valor = max($valor, $pdf->larguraTexto(relpdf_valor($l[$k] ?? null, $tipo)));
                }
                $need[$j] = max($palavra, $tipo === 'texto' ? min($valor, 150) : $valor) + 8;
            }
            if (array_sum($need) <= $W || $fs <= 5.5) {
                break;
            }
            $fs -= 0.5;
        }
        $sobra = $W - array_sum($need);
        $textos = array_keys(array_filter(array_values($cols), fn ($c) => $c[1] === 'texto'));
        $larg = $need;
        if ($sobra > 0) {
            $alvo = $textos ?: array_keys($need);
            foreach ($alvo as $j) {
                $larg[$j] += $sobra / count($alvo);
            }
        } else {
            $larg = array_map(fn ($w) => $w * $W / array_sum($need), $need);
        }
        $pdf->fonte(true, $fs);
        $cab = [];
        $ch = 0;
        foreach (array_values($cols) as $j => $c) {
            $cab[$j] = array_slice($pdf->quebrar($c[0], $larg[$j] - 6), 0, 5);
            $ch = max($ch, count($cab[$j]));
        }
        $hCab = 6 + $ch * ($fs + 2);
        $hLin = $fs + 7;
        $desenharCab = function () use ($pdf, &$y, $M, $W, $cols, $cab, $larg, $fs, $hCab) {
            $pdf->retangulo($M, $y, $W, $hCab, RELPDF_CLARO);
            $pdf->fonte(true, $fs);
            $x = $M;
            foreach (array_values($cols) as $j => $c) {
                foreach ($cab[$j] as $k => $l) {
                    $tx = $c[1] === 'texto' ? $x + 3 : $x + $larg[$j] - 3;
                    $pdf->texto($tx, $y + 4 + ($k + 1) * ($fs + 2) - 1, $l, RELPDF_TINTA, $c[1] === 'texto' ? 'E' : 'D');
                }
                $x += $larg[$j];
            }
            $y += $hCab;
        };
        $espaco(24 + $hCab + $hLin * min(3, max(1, count($tab['linhas']))));
        $pdf->fonte(true, 11);
        $pdf->texto($M, $y + 12, $tab['titulo']);
        $y += 20;
        if (!$tab['linhas']) {
            $pdf->fonte(false, 8.5);
            $pdf->texto($M, $y + 8, 'Nada no período para esta seleção.', RELPDF_CINZA);
            $y += 22;
            continue;
        }
        $desenharCab();
        $linhas = $tab['linhas'];
        if (!empty($tab['totais'])) {
            $linhas[] = $tab['totais'] + ['__total' => true];
        }
        foreach ($linhas as $idx => $l) {
            if ($y + $hLin > $base) {
                $nova();
                $pdf->fonte(false, 7.5);
                $pdf->texto($M, $y + 8, $tab['titulo'] . ' (continuação)', RELPDF_CINZA);
                $y += 14;
                $desenharCab();
            }
            $total = !empty($l['__total']);
            if ($total) {
                $pdf->retangulo($M, $y, $W, $hLin, [242, 243, 248]);
            } elseif ($idx % 2 === 1) {
                $pdf->retangulo($M, $y, $W, $hLin, [251, 251, 253]);
            }
            $pdf->fonte($total, $fs);
            $x = $M;
            foreach ($cols as $k => [$rot, $tipo]) {
                $j = array_search($k, array_keys($cols), true);
                $v = relpdf_valor($l[$k] ?? null, $tipo);
                if ($total && $v === '—') {
                    $v = '';
                }
                $v = $pdf->caber($v, $larg[$j] - 6);
                $pdf->texto($tipo === 'texto' ? $x + 3 : $x + $larg[$j] - 3, $y + $hLin - 4, $v, RELPDF_TINTA, $tipo === 'texto' ? 'E' : 'D');
                $x += $larg[$j];
            }
            $pdf->linha($M, $y + $hLin, $M + $W, $y + $hLin, [236, 238, 244], 0.4);
            $y += $hLin;
        }
        $y += 14;
    }

    // ------------------------------------------------ notas e definições
    $blocos = [['Notas', $d['notas']]];
    if ($d['nivel'] !== 'resumido' && $d['definicoes']) {
        $blocos[] = ['Definições (dicionário de indicadores)', array_map(fn ($x) => $x[0] . ': ' . $x[1] . ' Cálculo: ' . $x[2], $d['definicoes'])];
    }
    foreach ($blocos as [$tit, $itens]) {
        if (!$itens) {
            continue;
        }
        $espaco(40);
        $pdf->fonte(true, 11);
        $pdf->texto($M, $y + 12, $tit);
        $y += 22;
        $pdf->fonte(false, 7.5);
        foreach ($itens as $it) {
            foreach ($pdf->quebrar('• ' . $it, $W) as $l) {
                $espaco(11);
                $pdf->fonte(false, 7.5);
                $pdf->texto($M, $y + 8, $l, RELPDF_CINZA);
                $y += 10.5;
            }
            $y += 3;
        }
        $y += 8;
    }
    file_put_contents($destino, $pdf->saida());
    return $pdf->paginaAtual();
}
