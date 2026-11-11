<?php
/**
 * Exportação de planilhas (seção 12 — "Exportação de dados").
 *
 * Gera .xlsx de verdade (OOXML: workbook, uma planilha, estilos com
 * cabeçalho em negrito, textos inline, números como números e datas como
 * datas) usando só o ZipArchive do PHP — sem bibliotecas. Se o servidor
 * não tiver ZipArchive, cai para CSV (UTF-8 com BOM, separador ";" e
 * quebra CRLF, que o Excel brasileiro abre direto).
 *
 * Proteção contra injeção de fórmula: todo TEXTO que começa com = + - @
 * (ou tab/CR) ganha um apóstrofo na frente, para o Excel não executar.
 *
 * Nada fica guardado: o .xlsx é montado em arquivos temporários do PHP,
 * enviado e apagado na mesma requisição.
 */

function exportacao_limite(): int
{
    return 50000;
}

/**
 * 'xlsx' quando o ZipArchive existe e pode ser usado, senão 'csv'.
 * Algumas hospedagens deixam a classe "existir" mas a bloqueiam em
 * disable_classes — por isso a segunda verificação.
 */
function exportacao_formato(): string
{
    if (!class_exists('ZipArchive')) {
        return 'csv';
    }
    $bloqueadas = array_map('strtolower', array_map('trim', explode(',', (string) ini_get('disable_classes'))));
    return in_array('ziparchive', $bloqueadas, true) ? 'csv' : 'xlsx';
}

/** Neutraliza texto que o Excel/LibreOffice interpretaria como fórmula. */
function exportacao_texto_seguro(string $s): string
{
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $s;
    }
    return $s;
}

/** Nome de arquivo seguro: letras, números, hífen. */
function exportacao_nome_arquivo(string $base): string
{
    $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: 'relatorio';
    $base = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $base), '-');
    return ($base !== '' ? strtolower($base) : 'relatorio') . '-' . date('Y-m-d-His');
}

/**
 * Converte uma linha em células tipadas. $tipos[coluna]: texto | int |
 * num | pct | data | datahora | tempo (segundos → texto "1 h 20 min").
 * pct e num ficam como número (pct com uma casa).
 */
function exportacao_celulas(array $linha, array $colunas): array
{
    $out = [];
    foreach ($colunas as $k => [$rotulo, $tipo]) {
        $v = $linha[$k] ?? null;
        if ($v === null || $v === '') {
            $out[] = ['t' => 'vazio', 'v' => ''];
            continue;
        }
        switch ($tipo) {
            case 'int':
                $out[] = ['t' => 'num', 'v' => (string) (int) $v];
                break;
            case 'num':
            case 'pct':
                $out[] = ['t' => 'num', 'v' => (string) round((float) $v, 1)];
                break;
            case 'data':
            case 'datahora':
                $ts = strtotime((string) $v);
                $out[] = $ts ? ['t' => $tipo, 'v' => $ts] : ['t' => 'texto', 'v' => exportacao_texto_seguro((string) $v)];
                break;
            case 'tempo':
                require_once __DIR__ . '/dashboard.php';
                $out[] = ['t' => 'texto', 'v' => dash_duracao((float) $v)];
                break;
            default:
                $out[] = ['t' => 'texto', 'v' => exportacao_texto_seguro((string) $v)];
        }
    }
    return $out;
}

/**
 * Envia a planilha para o navegador e encerra. $linhas: iterável de
 * arrays associativos; $colunas: [chave => [rótulo, tipo]].
 * $forcarFormato só é usado pelos testes de linha de comando.
 * Devolve o número de linhas enviadas (útil quando chamado em teste).
 */
function exportacao_enviar(string $titulo, array $colunas, iterable $linhas, ?string $destino = null, ?string $forcarFormato = null): int
{
    $formato = $forcarFormato ?? exportacao_formato();
    $nome = exportacao_nome_arquivo($titulo);
    if ($formato === 'xlsx') {
        return exportacao_xlsx($titulo, $colunas, $linhas, $nome, $destino);
    }
    return exportacao_csv($colunas, $linhas, $nome, $destino);
}

function exportacao_cabecalhos(string $tipo, string $arquivo): void
{
    if (headers_sent()) {
        return;
    }
    header('Content-Type: ' . $tipo);
    header('Content-Disposition: attachment; filename="' . $arquivo . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
}

function exportacao_csv(array $colunas, iterable $linhas, string $nome, ?string $destino): int
{
    $out = fopen($destino ?? 'php://output', 'wb');
    if (!$destino) {
        exportacao_cabecalhos('text/csv; charset=utf-8', $nome . '.csv');
    }
    $linhaCsv = function (array $valores) {
        return implode(';', array_map(function ($v) {
            $v = (string) $v;
            return preg_match('/[;"\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
        }, $valores)) . "\r\n";
    };
    fwrite($out, "\xEF\xBB\xBF" . $linhaCsv(array_map(fn ($c) => exportacao_texto_seguro($c[0]), $colunas)));
    $n = 0;
    foreach ($linhas as $linha) {
        $vals = [];
        foreach (exportacao_celulas($linha, $colunas) as $c) {
            if ($c['t'] === 'num') {
                $vals[] = str_replace('.', ',', $c['v']); // Excel pt-BR: vírgula decimal
            } elseif ($c['t'] === 'data') {
                $vals[] = date('d/m/Y', $c['v']);
            } elseif ($c['t'] === 'datahora') {
                $vals[] = date('d/m/Y H:i', $c['v']);
            } else {
                $vals[] = $c['v'];
            }
        }
        fwrite($out, $linhaCsv($vals));
        $n++;
    }
    fclose($out);
    return $n;
}

/** Letra(s) da coluna: 0 → A, 26 → AA. */
function exportacao_coluna(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

function exportacao_xml(string $s): string
{
    // Remove caracteres de controle que o XML 1.0 não aceita.
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Número de série do Excel (dias desde 1899-12-30) para um timestamp local. */
function exportacao_serial_excel(int $ts): string
{
    $offset = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone(date_default_timezone_get()))->getOffset();
    return rtrim(rtrim(number_format(($ts + $offset) / 86400 + 25569, 8, '.', ''), '0'), '.');
}

function exportacao_xlsx(string $titulo, array $colunas, iterable $linhas, string $nome, ?string $destino): int
{
    $tmpSheet = tempnam(sys_get_temp_dir(), 'wmx');
    $tmpZip = tempnam(sys_get_temp_dir(), 'wmz');
    try {
        $fh = fopen($tmpSheet, 'wb');
        fwrite($fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>');
        $i = 0;
        foreach ($colunas as [$rotulo, $tipo]) {
            $larg = $tipo === 'texto' ? max(14, min(45, mb_strlen($rotulo) + 6)) : max(12, min(30, mb_strlen($rotulo) + 2));
            fwrite($fh, '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $larg . '" customWidth="1"/>');
            $i++;
        }
        fwrite($fh, '</cols><sheetData><row r="1">');
        $i = 0;
        foreach ($colunas as [$rotulo, $tipo]) {
            fwrite($fh, '<c r="' . exportacao_coluna($i++) . '1" t="inlineStr" s="1"><is><t>' . exportacao_xml(exportacao_texto_seguro($rotulo)) . '</t></is></c>');
        }
        fwrite($fh, '</row>');
        $r = 1;
        foreach ($linhas as $linha) {
            $r++;
            $buf = '<row r="' . $r . '">';
            foreach (exportacao_celulas($linha, $colunas) as $ci => $c) {
                $ref = exportacao_coluna($ci) . $r;
                switch ($c['t']) {
                    case 'vazio':
                        break;
                    case 'num':
                        $buf .= '<c r="' . $ref . '"><v>' . $c['v'] . '</v></c>';
                        break;
                    case 'data':
                        $buf .= '<c r="' . $ref . '" s="2"><v>' . exportacao_serial_excel($c['v']) . '</v></c>';
                        break;
                    case 'datahora':
                        $buf .= '<c r="' . $ref . '" s="3"><v>' . exportacao_serial_excel($c['v']) . '</v></c>';
                        break;
                    default:
                        $buf .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . exportacao_xml($c['v']) . '</t></is></c>';
                }
            }
            fwrite($fh, $buf . '</row>');
        }
        fwrite($fh, '</sheetData></worksheet>');
        fclose($fh);

        $aba = exportacao_xml(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', explode(' — ', $titulo)[0]), 0, 31));
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível montar a planilha.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . exportacao_xml($titulo) . '</dc:title><dc:creator>WeMart Sales</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . ($aba !== '' ? $aba : 'Planilha') . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Estilos: 0 normal, 1 cabeçalho em negrito com fundo, 2 data, 3 data e hora.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy hh:mm"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEEECFF"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->addFile($tmpSheet, 'xl/worksheets/sheet1.xml');
        $zip->close();

        if ($destino) {
            copy($tmpZip, $destino);
        } else {
            exportacao_cabecalhos('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $nome . '.xlsx');
            header('Content-Length: ' . filesize($tmpZip));
            readfile($tmpZip);
        }
        return $r - 1;
    } finally {
        @unlink($tmpSheet);
        @unlink($tmpZip);
    }
}
