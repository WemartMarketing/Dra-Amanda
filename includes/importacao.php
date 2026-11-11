<?php
/**
 * Importação de planilhas para a oferta ativa (seção 11 do prompt mestre).
 *
 * Formatos: CSV (separador ; , ou tab detectado sozinho; UTF-8 com ou sem
 * BOM, ou ISO-8859-1/Windows-1252) e XLSX (lido com ZipArchive +
 * XMLReader, sem bibliotecas externas). Limites: 5 MB e 5.000 linhas.
 *
 * Segurança do XLSX: só lê as partes conhecidas do pacote, limita o
 * tamanho descompactado (anti zip-bomb), recusa qualquer XML com DOCTYPE
 * ou ENTITY (anti XXE) e carrega com LIBXML_NONET.
 *
 * O arquivo enviado fica em storage/importacoes/ com nome aleatório só
 * entre o envio e a confirmação; é apagado ao importar ou, se abandonado,
 * pelo cron depois de 1 hora. A planilha em si não é guardada.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/funil.php';
require_once __DIR__ . '/auditoria.php';

const IMPORTACAO_MAX_BYTES = 5 * 1024 * 1024;
const IMPORTACAO_MAX_LINHAS = 5000;
const IMPORTACAO_MAX_XML = 60 * 1024 * 1024;   // soma descompactada das partes lidas
const IMPORTACAO_DIR = __DIR__ . '/../storage/importacoes';

function importacao_dir(): string
{
    if (!is_dir(IMPORTACAO_DIR)) {
        mkdir(IMPORTACAO_DIR, 0750, true);
    }
    return IMPORTACAO_DIR;
}

/** Campos do lead que podem receber uma coluna. */
function importacao_campos(): array
{
    return ['nome' => 'Nome', 'telefone' => 'Telefone', 'email' => 'E-mail', 'interesse' => 'Interesse', 'observacao' => 'Observação'];
}

/** Caminho do arquivo temporário de um token (só hex — nunca vem do usuário sem validar). */
function importacao_caminho(string $token, string $ext): ?string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || !in_array($ext, ['csv', 'xlsx'], true)) {
        return null;
    }
    return importacao_dir() . '/' . $token . '.' . $ext;
}

/** Apaga arquivos temporários com mais de $segundos (cron). Retorna quantos. */
function importacao_limpar_temporarios(int $segundos = 3600): int
{
    $n = 0;
    foreach (glob(importacao_dir() . '/*.{csv,xlsx}', GLOB_BRACE) ?: [] as $arq) {
        if (is_file($arq) && filemtime($arq) < time() - $segundos) {
            @unlink($arq);
            $n++;
        }
    }
    return $n;
}

/**
 * Lê a planilha. Retorna ['ok' => true, 'linhas' => [[...], ...]] (a 1ª
 * linha é o cabeçalho) ou ['ok' => false, 'erro' => '...'].
 */
function importacao_ler(string $caminho, string $ext): array
{
    if (!is_file($caminho)) {
        return ['ok' => false, 'erro' => 'Arquivo não encontrado. Envie a planilha de novo.'];
    }
    if (filesize($caminho) > IMPORTACAO_MAX_BYTES) {
        return ['ok' => false, 'erro' => 'Arquivo maior que 5 MB.'];
    }
    $r = $ext === 'xlsx' ? importacao_ler_xlsx($caminho) : importacao_ler_csv($caminho);
    if (!$r['ok']) {
        return $r;
    }
    // Remove linhas totalmente vazias.
    $linhas = array_values(array_filter($r['linhas'], fn ($l) => implode('', array_map('trim', $l)) !== ''));
    if (count($linhas) < 2) {
        return ['ok' => false, 'erro' => 'A planilha não tem linhas de dados (a primeira linha deve ser o cabeçalho).'];
    }
    if (count($linhas) - 1 > IMPORTACAO_MAX_LINHAS) {
        return ['ok' => false, 'erro' => 'A planilha tem ' . (count($linhas) - 1) . ' linhas. O limite é ' . IMPORTACAO_MAX_LINHAS . ' por importação — divida o arquivo.'];
    }
    // Normaliza a largura de todas as linhas.
    $largura = min(50, max(array_map('count', $linhas)));
    foreach ($linhas as &$l) {
        $l = array_slice(array_pad(array_map(fn ($v) => mb_substr(trim((string) $v), 0, 500), $l), $largura, ''), 0, $largura);
    }
    return ['ok' => true, 'linhas' => $linhas];
}

function importacao_ler_csv(string $caminho): array
{
    $bruto = (string) file_get_contents($caminho);
    if (strncmp($bruto, "\xEF\xBB\xBF", 3) === 0) {
        $bruto = substr($bruto, 3);
    } elseif (!mb_check_encoding($bruto, 'UTF-8')) {
        // Excel no Windows salva CSV em Windows-1252 (superconjunto do ISO-8859-1).
        $conv = @iconv('Windows-1252', 'UTF-8//IGNORE', $bruto);
        $bruto = $conv !== false ? $conv : mb_convert_encoding($bruto, 'UTF-8', 'ISO-8859-1');
    }
    if (strpos($bruto, "\0") !== false) {
        return ['ok' => false, 'erro' => 'O arquivo não parece ser um CSV de texto. Salve a planilha como CSV ou XLSX.'];
    }
    $primeira = strtok($bruto, "\r\n") ?: '';
    $contagem = [';' => substr_count($primeira, ';'), ',' => substr_count($primeira, ','), "\t" => substr_count($primeira, "\t")];
    arsort($contagem);
    $sep = (string) array_key_first($contagem);
    if ($contagem[$sep] === 0) {
        $sep = ';';
    }
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, $bruto);
    rewind($fh);
    $linhas = [];
    while (($l = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
        if ($l === [null]) {
            continue;
        }
        $linhas[] = $l;
        if (count($linhas) > IMPORTACAO_MAX_LINHAS + 1) {
            break; // para cedo: já passou do limite
        }
    }
    fclose($fh);
    if (count($linhas) > IMPORTACAO_MAX_LINHAS + 1) {
        return ['ok' => false, 'erro' => 'A planilha tem mais de ' . IMPORTACAO_MAX_LINHAS . ' linhas. Divida o arquivo.'];
    }
    return ['ok' => true, 'linhas' => $linhas];
}

/** Conteúdo de uma parte do XLSX, com limite e recusa de DOCTYPE/ENTITY. */
function importacao_xlsx_parte(ZipArchive $zip, string $nome, int &$orcamento): ?string
{
    $stat = $zip->statName($nome);
    if ($stat === false) {
        return null;
    }
    if ($stat['size'] > $orcamento) {
        throw new RuntimeException('Planilha grande demais depois de descompactada.');
    }
    $orcamento -= $stat['size'];
    $xml = $zip->getFromName($nome);
    if ($xml === false) {
        return null;
    }
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
        throw new RuntimeException('A planilha contém declarações XML não permitidas.');
    }
    return $xml;
}

function importacao_xml_reader(string $xml): XMLReader
{
    $r = new XMLReader();
    if (!$r->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
        throw new RuntimeException('XML inválido na planilha.');
    }
    return $r;
}

function importacao_ler_xlsx(string $caminho): array
{
    if (!class_exists('ZipArchive') || !class_exists('XMLReader')) {
        return ['ok' => false, 'erro' => 'O servidor não tem as extensões zip/xmlreader. Envie em CSV.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($caminho) !== true) {
        return ['ok' => false, 'erro' => 'Não foi possível abrir o XLSX. Confira se o arquivo não está corrompido.'];
    }
    $orcamento = IMPORTACAO_MAX_XML;
    try {
        if ($zip->numFiles > 2000) {
            throw new RuntimeException('Pacote XLSX com arquivos demais.');
        }
        // Primeira planilha: workbook.xml → rels → caminho real.
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wb = importacao_xlsx_parte($zip, 'xl/workbook.xml', $orcamento);
        $rels = importacao_xlsx_parte($zip, 'xl/_rels/workbook.xml.rels', $orcamento);
        if ($wb !== null && $rels !== null) {
            $wbx = simplexml_load_string($wb, 'SimpleXMLElement', LIBXML_NONET);
            $rlx = simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET);
            if ($wbx && $rlx && isset($wbx->sheets->sheet[0])) {
                $rid = (string) $wbx->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($rlx->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $alvo = ltrim(str_replace('\\', '/', (string) $rel['Target']), '/');
                        $alvo = strpos($alvo, 'xl/') === 0 ? $alvo : 'xl/' . $alvo;
                        if (preg_match('#^xl/worksheets/[A-Za-z0-9_\-]+\.xml$#', $alvo)) {
                            $sheetPath = $alvo;
                        }
                    }
                }
            }
        }
        // Textos compartilhados.
        $compartilhados = [];
        $ss = importacao_xlsx_parte($zip, 'xl/sharedStrings.xml', $orcamento);
        if ($ss !== null) {
            $r = importacao_xml_reader($ss);
            $atual = null;
            while ($r->read()) {
                if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si') {
                    $atual = '';
                } elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 't' && $atual !== null) {
                    $atual .= $r->readString();
                } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'si') {
                    $compartilhados[] = $atual;
                    $atual = null;
                    if (count($compartilhados) > 200000) {
                        throw new RuntimeException('Planilha com textos demais.');
                    }
                }
            }
            $r->close();
        }
        $sheet = importacao_xlsx_parte($zip, $sheetPath, $orcamento);
        if ($sheet === null) {
            throw new RuntimeException('A planilha não tem abas legíveis.');
        }
        $r = importacao_xml_reader($sheet);
        $linhas = [];
        $linha = null;
        $col = 0;
        $tipo = '';
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'row') {
                $linha = [];
                if ($r->isEmptyElement) {
                    $linhas[] = [];
                    $linha = null;
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'row') {
                $linhas[] = $linha ?? [];
                $linha = null;
                if (count($linhas) > IMPORTACAO_MAX_LINHAS + 1) {
                    throw new RuntimeException('A planilha tem mais de ' . IMPORTACAO_MAX_LINHAS . ' linhas. Divida o arquivo.');
                }
            } elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'c' && $linha !== null) {
                $ref = (string) $r->getAttribute('r');
                $col = $ref !== '' ? importacao_coluna_indice($ref) : count($linha);
                $tipo = (string) $r->getAttribute('t');
                if ($col > 49) {
                    $col = -1;
                }
                if ($r->isEmptyElement) {
                    continue;
                }
                $valor = '';
                $profundidade = $r->depth;
                while ($r->read() && !($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'c' && $r->depth === $profundidade)) {
                    if ($r->nodeType === XMLReader::ELEMENT && ($r->localName === 'v' || $r->localName === 't')) {
                        $valor .= $r->readString();
                    }
                }
                if ($col < 0) {
                    continue;
                }
                if ($tipo === 's') {
                    $valor = $compartilhados[(int) $valor] ?? '';
                } elseif (($tipo === '' || $tipo === 'n') && is_numeric($valor) && stripos($valor, 'e') !== false) {
                    $valor = sprintf('%.0f', (float) $valor); // telefone gravado como número científico
                } elseif (($tipo === '' || $tipo === 'n') && preg_match('/^\d+\.0+$/', $valor)) {
                    $valor = substr($valor, 0, strpos($valor, '.'));
                }
                for ($i = count($linha); $i < $col; $i++) {
                    $linha[$i] = '';
                }
                $linha[$col] = $valor;
            }
        }
        $r->close();
        $zip->close();
        return ['ok' => true, 'linhas' => $linhas];
    } catch (Throwable $e) {
        $zip->close();
        return ['ok' => false, 'erro' => $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível ler o XLSX.'];
    }
}

/** "AB12" → 27 (base zero). */
function importacao_coluna_indice(string $ref): int
{
    $letras = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n = 0;
    foreach (str_split($letras) as $c) {
        $n = $n * 26 + (ord($c) - 64);
    }
    return max(0, $n - 1);
}

function importacao_normalizar_rotulo(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    return preg_replace('/[^a-z0-9]+/', ' ', $s);
}

/** Sugere [campo => índice da coluna] a partir do cabeçalho. */
function importacao_sugerir(array $cabecalho): array
{
    $padroes = [
        'nome' => '/^(nome|name|cliente|nome completo|full name|contato|lead)\b/',
        'telefone' => '/(telefone|celular|fone|whats|phone|tel\b|contato tel)/',
        'email' => '/(e ?mail|correio)/',
        'interesse' => '/(interesse|produto|imovel|empreendimento|tipologia)/',
        'observacao' => '/(obs|observ|coment|nota|anotac)/',
    ];
    $sug = [];
    foreach ($cabecalho as $i => $rot) {
        $n = importacao_normalizar_rotulo((string) $rot);
        foreach ($padroes as $campo => $re) {
            if (!isset($sug[$campo]) && preg_match($re, $n)) {
                $sug[$campo] = $i;
                break;
            }
        }
    }
    return $sug;
}

/**
 * Importa as linhas com o mapeamento escolhido. Cria a lista e os leads
 * (sem responsável). Retorna [lista_id, relatório].
 * $mapa: [campo => índice|null]
 */
function importacao_executar(array $usuario, array $linhas, array $mapa, string $nomeLista, ?int $empreendimentoId, string $arquivoNome): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $uid = (int) $usuario['id'];
    $pdo = db();
    $etapa = funil_primeira_etapa($empresaId);
    if (!$etapa) {
        throw new RuntimeException('O funil da empresa não tem etapas ativas.');
    }
    $dados = array_slice($linhas, 1);
    $pega = fn (array $l, string $campo) => isset($mapa[$campo]) && $mapa[$campo] !== null ? trim((string) ($l[$mapa[$campo]] ?? '')) : '';

    // Telefones já existentes na empresa (uma consulta só).
    $stmt = $pdo->prepare('SELECT ' . lead_sql_telefone_digitos('telefone') . ' AS d FROM leads WHERE empresa_id = :e AND excluido_em IS NULL AND telefone IS NOT NULL');
    $stmt->execute(['e' => $empresaId]);
    $existentes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $existentes[lead_telefone_normalizado($d)] = true;
    }

    $rel = ['lidas' => count($dados), 'importados' => 0, 'duplicados' => 0, 'invalidos' => 0, 'rejeitados' => []];
    $vistos = [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO oferta_listas (empresa_id, nome, empreendimento_id, arquivo_nome, criado_por) VALUES (:e, :n, :emp, :a, :u)')
            ->execute(['e' => $empresaId, 'n' => $nomeLista, 'emp' => $empreendimentoId, 'a' => mb_substr($arquivoNome, 0, 255), 'u' => $uid]);
        $listaId = (int) $pdo->lastInsertId();
        $insLead = $pdo->prepare("INSERT INTO leads (empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, email, interesse, origem, oferta_lista_id,
                etapa_id, etapa, prioridade, ultima_atividade_em)
            VALUES (:e, :emp, NULL, NULL, :nome, :tel, :email, :int, 'importacao', :lista, :etapa_id, :etapa, 'normal', NOW())");
        $insEvento = $pdo->prepare('INSERT INTO lead_eventos (lead_id, usuario_id, tipo, descricao) VALUES (:l, :u, :t, :d)');
        $textoEvento = 'Importado na lista de oferta ativa "' . $nomeLista . '" (' . $arquivoNome . ') por ' . $usuario['nome'];

        foreach ($dados as $i => $l) {
            $numLinha = $i + 2; // linha na planilha (1 = cabeçalho)
            $nome = mb_substr($pega($l, 'nome'), 0, 190);
            $telBruto = $pega($l, 'telefone');
            $tel = lead_telefone_normalizado($telBruto);
            $motivo = null;
            if ($nome === '') {
                $motivo = 'Nome vazio';
            } elseif ($telBruto === '') {
                $motivo = 'Telefone vazio';
            } elseif (strlen($tel) < 10 || strlen($tel) > 11) {
                $motivo = 'Telefone inválido';
            }
            if ($motivo) {
                $rel['invalidos']++;
                $rel['rejeitados'][] = ['linha' => $numLinha, 'motivo' => $motivo, 'nome' => $nome, 'telefone' => $telBruto];
                continue;
            }
            if (isset($vistos[$tel])) {
                $rel['duplicados']++;
                $rel['rejeitados'][] = ['linha' => $numLinha, 'motivo' => 'Repetido na planilha (linha ' . $vistos[$tel] . ')', 'nome' => $nome, 'telefone' => $telBruto];
                continue;
            }
            $vistos[$tel] = $numLinha;
            if (isset($existentes[$tel])) {
                $rel['duplicados']++;
                $rel['rejeitados'][] = ['linha' => $numLinha, 'motivo' => 'Telefone já cadastrado na empresa', 'nome' => $nome, 'telefone' => $telBruto];
                continue;
            }
            $email = $pega($l, 'email');
            $email = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 190) : null;
            $interesse = mb_substr($pega($l, 'interesse'), 0, 190) ?: null;
            $insLead->execute([
                'e' => $empresaId, 'emp' => $empreendimentoId, 'nome' => $nome, 'tel' => lead_formatar_telefone($tel),
                'email' => $email, 'int' => $interesse, 'lista' => $listaId, 'etapa_id' => $etapa['id'], 'etapa' => $etapa['categoria'],
            ]);
            $leadId = (int) $pdo->lastInsertId();
            $insEvento->execute(['l' => $leadId, 'u' => $uid, 't' => 'importacao', 'd' => $textoEvento]);
            $obs = mb_substr($pega($l, 'observacao'), 0, 500);
            if ($obs !== '') {
                $insEvento->execute(['l' => $leadId, 'u' => $uid, 't' => 'nota', 'd' => $obs]);
            }
            $existentes[$tel] = true;
            $rel['importados']++;
        }
        $pdo->prepare('UPDATE oferta_listas SET linhas_lidas = :l, importados = :i, duplicados = :d, invalidos = :v WHERE id = :id')
            ->execute(['l' => $rel['lidas'], 'i' => $rel['importados'], 'd' => $rel['duplicados'], 'v' => $rel['invalidos'], 'id' => $listaId]);
        auditoria_registrar($empresaId, $uid, 'oferta.lista_importada', 'oferta_lista', $listaId, [
            'arquivo' => $arquivoNome, 'lidas' => $rel['lidas'], 'importados' => $rel['importados'], 'duplicados' => $rel['duplicados'], 'invalidos' => $rel['invalidos'],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return [$listaId, $rel];
}

/** Lista de oferta visível ao usuário (empresa; quem não vê a empresa toda só vê as que criou). */
function oferta_lista_carregar(array $usuario, int $listaId): ?array
{
    $sql = 'SELECT ol.*, emp.nome AS empreendimento_nome, u.nome AS criado_por_nome FROM oferta_listas ol
        LEFT JOIN empreendimentos emp ON emp.id = ol.empreendimento_id LEFT JOIN usuarios u ON u.id = ol.criado_por
        WHERE ol.id = :id AND ol.empresa_id = :e';
    $params = ['id' => $listaId, 'e' => (int) $usuario['empresa_ativa_id']];
    if (!oferta_ve_todas($usuario)) {
        $sql .= ' AND ol.criado_por = :u';
        $params['u'] = (int) $usuario['id'];
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function oferta_ve_todas(array $usuario): bool
{
    return in_array($usuario['escopo'] ?? '', ['toda_empresa', 'todos_produtos'], true);
}

/** Corretores (escopo própria carteira, ativos) para quem o usuário pode dividir a lista. */
function oferta_corretores(array $usuario): array
{
    return array_values(array_filter(rbac_usuarios_atribuiveis($usuario), fn ($u) => ($u['funcao_escopo'] ?? '') === 'propria_carteira'));
}
