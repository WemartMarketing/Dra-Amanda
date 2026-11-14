<?php
/**
 * Arquivos da Central de Relatórios: Excel (.xlsx real, várias abas) e CSV.
 * Os dados vêm de includes/relatorios/exportar.php (mesma fonte da tela).
 *
 * Excel: abas "Resumo" (cabeçalho com empresa, relatório, período,
 * filtros, data/hora e fuso + indicadores), "Detalhamento" (tabelas) e
 * "Notas" (avisos e definições do dicionário). Números, moeda e datas
 * tipados. Todo texto que começa com = + - @ ganha apóstrofo (sem fórmulas).
 * CSV: UTF-8 com BOM, ";" e vírgula decimal — só a tabela principal.
 */
require_once __DIR__ . '/../exportacao.php';
require_once __DIR__ . '/exportar.php';

// Estilos (índices de cellXfs).
const RELARQ_NORMAL = 0, RELARQ_CABECALHO = 1, RELARQ_DATA = 2, RELARQ_DATAHORA = 3, RELARQ_TITULO = 4, RELARQ_ROTULO = 5,
      RELARQ_MOEDA = 6, RELARQ_DEC1 = 7, RELARQ_INT = 8, RELARQ_TOTAL = 9, RELARQ_TOTAL_MOEDA = 10, RELARQ_TOTAL_DEC1 = 11, RELARQ_NOTA = 12, RELARQ_TOTAL_INT = 13;

/** Uma célula tipada. */
function relarq_cel($v, string $tipo = 'texto', bool $total = false): array
{
    return ['v' => $v, 't' => $tipo, 'total' => $total];
}

/** Linhas de uma tabela de relatório (cabeçalho + dados + total). */
function relarq_linhas_tabela(array $tab): Generator
{
    yield array_map(fn ($c) => relarq_cel($c[0], 'cabecalho'), array_values($tab['colunas']));
    foreach ($tab['linhas'] as $l) {
        $row = [];
        foreach ($tab['colunas'] as $k => [$rot, $tipo]) {
            $row[] = relarq_cel($l[$k] ?? null, $tipo);
        }
        yield $row;
    }
    if (!empty($tab['totais'])) {
        $row = [];
        foreach ($tab['colunas'] as $k => [$rot, $tipo]) {
            $row[] = relarq_cel($tab['totais'][$k] ?? null, $tipo, true);
        }
        yield $row;
    }
}

/** Abas de um relatório. */
function relarq_abas_relatorio(array $d): array
{
    $cab = function () use ($d) {
        yield [relarq_cel('WeMart Sales — ' . $d['titulo'], 'titulo')];
        yield [];
        foreach ([['Empresa', $d['empresa']], ['Relatório', $d['titulo']], ['Período', $d['periodo']]] as $p) {
            yield [relarq_cel($p[0], 'rotulo'), relarq_cel($p[1])];
        }
        if ($d['comparado']) {
            yield [relarq_cel('Comparado com', 'rotulo'), relarq_cel($d['comparado'] . ' (mesma duração)')];
        }
        yield [relarq_cel('Filtros', 'rotulo'), relarq_cel($d['filtros'] ? implode(' · ', array_map(fn ($x) => $x[0] . ': ' . $x[1], $d['filtros'])) : 'Nenhum — tudo o que o usuário pode ver')];
        foreach ([['Gerado em', $d['gerado_em']], ['Fuso horário', $d['fuso']], ['Gerado por', $d['usuario']], ['Tipo', relexp_niveis()[$d['nivel']] ?? $d['nivel']]] as $p) {
            yield [relarq_cel($p[0], 'rotulo'), relarq_cel($p[1])];
        }
        yield [];
    };
    $resumo = function () use ($d, $cab) {
        yield from $cab();
        yield array_map(fn ($c) => relarq_cel($c, 'cabecalho'), ['Indicador', 'Valor', 'Unidade', 'Período anterior', 'Variação', 'Observação']);
        foreach ($d['indicadores'] as $i) {
            $t = relexp_tipo_unidade($i['unidade']);
            $un = ['%' => '%', 'R$' => 'R$', 'min' => 'minutos', 'h' => 'horas'][$i['unidade']] ?? 'quantidade';
            $var = $i['variacao'] === null ? null : $i['variacao'];
            yield [relarq_cel($i['nome']), relarq_cel($i['valor'], $t), relarq_cel($un), relarq_cel($i['anterior'], $t),
                   relarq_cel($var, 'dec1'), relarq_cel(($var !== null ? ($i['variacao_unidade'] === 'p.p.' ? 'variação em pontos percentuais. ' : 'variação em %. ') : ($i['anterior'] === null ? '' : 'sem base de comparação. ')) . $i['nota'])];
        }
        if (!$d['indicadores']) {
            yield [relarq_cel('Sem indicadores para esta seleção.', 'nota')];
        }
    };
    $abas = [['nome' => 'Resumo', 'linhas' => $resumo, 'larguras' => [42, 22, 14, 18, 12, 70]]];
    if ($d['tabelas']) {
        $det = function () use ($d) {
            foreach ($d['tabelas'] as $i => $tab) {
                if ($i > 0) {
                    yield [];
                }
                yield [relarq_cel($tab['titulo'], 'titulo')];
                if (!$tab['linhas']) {
                    yield [relarq_cel('Nada no período para esta seleção.', 'nota')];
                    continue;
                }
                yield from relarq_linhas_tabela($tab);
            }
        };
        $larg = [];
        foreach ($d['tabelas'] as $tab) {
            foreach (array_values($tab['colunas']) as $j => [$rot, $tipo]) {
                $larg[$j] = max($larg[$j] ?? 0, $tipo === 'texto' ? 30 : min(30, max(12, mb_strlen($rot) + 2)));
            }
        }
        $abas[] = ['nome' => 'Detalhamento', 'linhas' => $det, 'larguras' => $larg];
    }
    $notas = function () use ($d) {
        yield [relarq_cel('Notas', 'titulo')];
        foreach ($d['notas'] as $n) {
            yield [relarq_cel($n)];
        }
        if ($d['definicoes']) {
            yield [];
            yield [relarq_cel('Definições (dicionário de indicadores)', 'titulo')];
            yield array_map(fn ($c) => relarq_cel($c, 'cabecalho'), ['Indicador', 'O que é', 'Como é calculado']);
            foreach ($d['definicoes'] as $x) {
                yield [relarq_cel($x[0]), relarq_cel($x[1]), relarq_cel($x[2])];
            }
        }
    };
    $abas[] = ['nome' => 'Notas', 'linhas' => $notas, 'larguras' => [40, 70, 90]];
    return $abas;
}

/** Abas da exportação de dados (uma linha por lead). */
function relarq_abas_dados(array $d, iterable $linhas): array
{
    $cols = relexp_colunas_dados();
    $dados = function () use ($cols, $linhas) {
        yield array_map(fn ($c) => relarq_cel($c[0], 'cabecalho'), array_values($cols));
        foreach ($linhas as $l) {
            $row = [];
            foreach ($cols as $k => [$rot, $tipo]) {
                $row[] = relarq_cel($l[$k] ?? null, $tipo);
            }
            yield $row;
        }
    };
    $sobre = function () use ($d) {
        yield [relarq_cel('WeMart Sales — exportação de dados', 'titulo')];
        yield [];
        foreach ([['Empresa', $d['empresa']], ['A partir do relatório', $d['titulo']], ['Período (entrada do lead)', $d['periodo']],
                  ['Filtros', $d['filtros'] ? implode(' · ', array_map(fn ($x) => $x[0] . ': ' . $x[1], $d['filtros'])) : 'Nenhum'],
                  ['Gerado em', $d['gerado_em']], ['Fuso horário', $d['fuso']], ['Gerado por', $d['usuario']]] as $p) {
            yield [relarq_cel($p[0], 'rotulo'), relarq_cel($p[1])];
        }
        yield [];
        yield [relarq_cel('LGPD: arquivo com dados pessoais (nome). Use só para a gestão comercial, não compartilhe fora da empresa e apague quando não precisar mais. Telefone e e-mail não são exportados aqui. A exportação fica registrada na auditoria.', 'nota')];
        yield [relarq_cel('Limite: ' . dash_num(exportacao_limite()) . ' leads por arquivo. Datas no fuso indicado acima.', 'nota')];
    };
    return [['nome' => 'Dados', 'linhas' => $dados, 'larguras' => array_map(fn ($c) => $c[1] === 'texto' ? 26 : 18, array_values($cols)), 'congelar' => 1],
            ['nome' => 'Sobre', 'linhas' => $sobre, 'larguras' => [28, 90]]];
}

/** XML de uma célula. $ref: "B3". */
function relarq_xml_celula(string $ref, array $c): string
{
    $v = $c['v'];
    $t = $c['t'];
    $tot = !empty($c['total']);
    if ($v === null || $v === '') {
        return $tot ? '<c r="' . $ref . '" s="' . RELARQ_TOTAL . '"/>' : '';
    }
    switch ($t) {
        case 'int':
            return is_numeric($v) ? '<c r="' . $ref . '" s="' . ($tot ? RELARQ_TOTAL_INT : RELARQ_INT) . '"><v>' . (int) round((float) $v) . '</v></c>' : relarq_xml_texto($ref, (string) $v, $tot ? RELARQ_TOTAL : RELARQ_NORMAL);
        case 'dec1':
            return is_numeric($v) ? '<c r="' . $ref . '" s="' . ($tot ? RELARQ_TOTAL_DEC1 : RELARQ_DEC1) . '"><v>' . round((float) $v, 1) . '</v></c>' : relarq_xml_texto($ref, (string) $v, $tot ? RELARQ_TOTAL : RELARQ_NORMAL);
        case 'moeda':
            return is_numeric($v) ? '<c r="' . $ref . '" s="' . ($tot ? RELARQ_TOTAL_MOEDA : RELARQ_MOEDA) . '"><v>' . round((float) $v, 2) . '</v></c>' : relarq_xml_texto($ref, (string) $v, $tot ? RELARQ_TOTAL : RELARQ_NORMAL);
        case 'data':
        case 'datahora':
            $ts = strtotime((string) $v);
            return $ts ? '<c r="' . $ref . '" s="' . ($t === 'data' ? RELARQ_DATA : RELARQ_DATAHORA) . '"><v>' . exportacao_serial_excel($ts) . '</v></c>' : relarq_xml_texto($ref, (string) $v, RELARQ_NORMAL);
        case 'cabecalho':
            return relarq_xml_texto($ref, (string) $v, RELARQ_CABECALHO);
        case 'titulo':
            return relarq_xml_texto($ref, (string) $v, RELARQ_TITULO);
        case 'rotulo':
            return relarq_xml_texto($ref, (string) $v, RELARQ_ROTULO);
        case 'nota':
            return relarq_xml_texto($ref, (string) $v, RELARQ_NOTA);
        default:
            return relarq_xml_texto($ref, (string) $v, $tot ? RELARQ_TOTAL : RELARQ_NORMAL);
    }
}

function relarq_xml_texto(string $ref, string $v, int $s): string
{
    return '<c r="' . $ref . '" t="inlineStr"' . ($s ? ' s="' . $s . '"' : '') . '><is><t xml:space="preserve">' . exportacao_xml(exportacao_texto_seguro($v)) . '</t></is></c>';
}

/** Grava um .xlsx com as abas em $destino. Retorna o número de linhas de dados escritas. */
function relarq_xlsx(array $abas, string $titulo, string $destino): int
{
    $tmp = [];
    $linhasTotal = 0;
    try {
        foreach ($abas as $i => $aba) {
            $tmp[$i] = tempnam(sys_get_temp_dir(), 'wmr');
            $fh = fopen($tmp[$i], 'wb');
            fwrite($fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">');
            if (!empty($aba['congelar'])) {
                fwrite($fh, '<sheetViews><sheetView workbookViewId="0"' . ($i === 0 ? ' tabSelected="1"' : '') . '><pane ySplit="' . (int) $aba['congelar'] . '" topLeftCell="A' . ((int) $aba['congelar'] + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>');
            } else {
                fwrite($fh, '<sheetViews><sheetView workbookViewId="0"' . ($i === 0 ? ' tabSelected="1"' : '') . ' showGridLines="0"/></sheetViews>');
            }
            if (!empty($aba['larguras'])) {
                fwrite($fh, '<cols>');
                foreach (array_values($aba['larguras']) as $j => $w) {
                    fwrite($fh, '<col min="' . ($j + 1) . '" max="' . ($j + 1) . '" width="' . (int) $w . '" customWidth="1"/>');
                }
                fwrite($fh, '</cols>');
            }
            fwrite($fh, '<sheetData>');
            $r = 0;
            foreach ($aba['linhas']() as $row) {
                $r++;
                $buf = '<row r="' . $r . '">';
                foreach (array_values($row) as $ci => $c) {
                    $buf .= relarq_xml_celula(exportacao_coluna($ci) . $r, $c);
                }
                fwrite($fh, $buf . '</row>');
                if ($r % 500 === 0) {
                    fflush($fh);
                }
            }
            $linhasTotal += $r;
            fwrite($fh, '</sheetData><pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/><pageSetup orientation="landscape" fitToWidth="1"/></worksheet>');
            fclose($fh);
        }
        $zip = new ZipArchive();
        if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível montar a planilha.');
        }
        $tipos = '';
        $sheets = '';
        $rels = '';
        foreach ($abas as $i => $aba) {
            $n = $i + 1;
            $tipos .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $nomeAba = exportacao_xml(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $aba['nome']), 0, 31));
            $sheets .= '<sheet name="' . $nomeAba . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $zip->addFile($tmp[$i], 'xl/worksheets/sheet' . $n . '.xml');
        }
        $nEst = count($abas) + 1;
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $tipos
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . exportacao_xml($titulo) . '</dc:title><dc:creator>WeMart Sales</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels
            . '<Relationship Id="rId' . $nEst . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', relarq_estilos());
        $zip->close();
        return $linhasTotal;
    } finally {
        foreach ($tmp as $t) {
            @unlink($t);
        }
    }
}

function relarq_estilos(): string
{
    $xf = fn (int $fmt, int $font, int $fill = 0, string $extra = '') => '<xf numFmtId="' . $fmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . ($fill === 2 || $font === 1 ? 1 : 0) . '" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"' . ($extra ? ' applyAlignment="1"><alignment ' . $extra . '/></xf>' : '/>');
    $cell = [
        $xf(0, 0),                     // 0 normal
        $xf(0, 1, 2, 'wrapText="1" vertical="center"'), // 1 cabeçalho
        $xf(164, 0),                   // 2 data
        $xf(165, 0),                   // 3 data e hora
        $xf(0, 2),                     // 4 título
        $xf(0, 1),                     // 5 rótulo
        $xf(166, 0),                   // 6 moeda
        $xf(167, 0),                   // 7 1 casa
        $xf(3, 0),                     // 8 inteiro com milhar
        $xf(0, 1, 3),                  // 9 total
        $xf(166, 1, 3),                // 10 total moeda
        $xf(167, 1, 3),                // 11 total 1 casa
        $xf(0, 3, 0, 'wrapText="1" vertical="top"'), // 12 nota
        $xf(3, 1, 3),                  // 13 total inteiro
    ];
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="4"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy hh:mm"/>'
        . '<numFmt numFmtId="166" formatCode="&quot;R$&quot; #,##0.00"/><numFmt numFmtId="167" formatCode="0.0"/></numFmts>'
        . '<fonts count="4"><font><sz val="11"/><color rgb="FF151A2D"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF151A2D"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="14"/><color rgb="FF635BFA"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF68728A"/><name val="Calibri"/></font></fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEEECFF"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F3F8"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FFD5D9E6"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="' . count($cell) . '">' . implode('', $cell) . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

/** CSV de uma tabela ([k => [rótulo, tipo]] + linhas) em $destino. Retorna as linhas de dados. */
function relarq_csv(array $colunas, iterable $linhas, string $destino): int
{
    $out = fopen($destino, 'wb');
    $l = fn (array $vals) => implode(';', array_map(function ($v) {
        $v = (string) $v;
        return preg_match('/[;"\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
    }, $vals)) . "\r\n";
    fwrite($out, "\xEF\xBB\xBF" . $l(array_map(fn ($c) => exportacao_texto_seguro($c[0]), array_values($colunas))));
    $n = 0;
    foreach ($linhas as $linha) {
        $vals = [];
        foreach ($colunas as $k => [$rot, $tipo]) {
            $v = $linha[$k] ?? null;
            if ($v === null || $v === '') {
                $vals[] = '';
            } elseif (in_array($tipo, ['int', 'dec1', 'moeda'], true) && is_numeric($v)) {
                $vals[] = $tipo === 'int' ? (string) (int) round((float) $v) : number_format((float) $v, $tipo === 'moeda' ? 2 : 1, ',', '');
            } elseif ($tipo === 'data' || $tipo === 'datahora') {
                $ts = strtotime((string) $v);
                $vals[] = $ts ? date($tipo === 'data' ? 'd/m/Y' : 'd/m/Y H:i', $ts) : exportacao_texto_seguro((string) $v);
            } else {
                $vals[] = exportacao_texto_seguro((string) $v);
            }
        }
        fwrite($out, $l($vals));
        $n++;
    }
    fclose($out);
    return $n;
}

/**
 * Gera o arquivo de um relatório (ou da exportação de dados) em $destino.
 * Retorna ['linhas' => n, 'extensao' => 'xlsx'|'csv'|'pdf', 'mime' => ...].
 */
function relarq_gerar(array $d, string $formato, string $destino, ?iterable $dados = null): array
{
    // "linhas" = linhas de DADOS (leads, ou linhas das tabelas), não do arquivo.
    $contador = 0;
    if ($dados !== null) {
        $dados = (function () use ($dados, &$contador) {
            foreach ($dados as $l) {
                $contador++;
                yield $l;
            }
        })();
    }
    $r = relarq_gerar_bruto($d, $formato, $destino, $dados);
    $r['linhas'] = $dados !== null ? $contador : array_sum(array_map(fn ($t) => count($t['linhas']), $d['tabelas']));
    return $r;
}

function relarq_gerar_bruto(array $d, string $formato, string $destino, ?iterable $dados = null): array
{
    if ($formato === 'xlsx' && exportacao_formato() !== 'xlsx') {
        $formato = 'csv'; // servidor sem ZipArchive
    }
    if ($formato === 'pdf') {
        require_once __DIR__ . '/pdf_relatorio.php';
        $n = relpdf_gerar($d, $destino);
        return ['linhas' => 0, 'paginas' => $n, 'extensao' => 'pdf', 'mime' => 'application/pdf'];
    }
    if ($formato === 'csv') {
        if ($dados !== null) {
            $n = relarq_csv(relexp_colunas_dados(), $dados, $destino);
        } else {
            $tab = $d['tabelas'][0] ?? null;
            $n = $tab ? relarq_csv($tab['colunas'], array_merge($tab['linhas'], $tab['totais'] ? [$tab['totais']] : []), $destino)
                : relarq_csv(['nome' => ['Indicador', 'texto'], 'valor' => ['Valor', 'dec1'], 'unidade' => ['Unidade', 'texto'], 'nota' => ['Observação', 'texto']], $d['indicadores'], $destino);
        }
        return ['linhas' => $n, 'extensao' => 'csv', 'mime' => 'text/csv; charset=utf-8'];
    }
    $abas = $dados !== null ? relarq_abas_dados($d, $dados) : relarq_abas_relatorio($d);
    $n = relarq_xlsx($abas, $d['titulo'], $destino);
    return ['linhas' => $n, 'extensao' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
}
