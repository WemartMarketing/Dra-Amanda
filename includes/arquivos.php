<?php
/**
 * Arquivos de leads (seção 8 — "quando autorizados").
 *
 * Guardados em storage/lead_arquivos/ com nome aleatório SEM extensão
 * (nada ali é executável nem tem tipo deduzido pelo servidor), pasta
 * bloqueada por storage/.htaccess e pelo .htaccess da raiz. O download
 * só acontece por api/lead_arquivo_baixar.php, que confere sessão,
 * permissão acessar_arquivos_leads e o escopo do lead a cada pedido.
 */

const LEAD_ARQUIVO_MAX_BYTES = 10 * 1024 * 1024;
const LEAD_ARQUIVO_DIR = __DIR__ . '/../storage/lead_arquivos';

/** Tipos aceitos, decididos pelo conteúdo (finfo) — nunca pela extensão. */
function lead_arquivo_tipos(): array
{
    return [
        'application/pdf' => 'PDF',
        'image/jpeg' => 'Imagem JPG',
        'image/png' => 'Imagem PNG',
        'image/webp' => 'Imagem WEBP',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word (.docx)',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel (.xlsx)',
    ];
}

function lead_arquivo_limite_bytes(): int
{
    $limite = LEAD_ARQUIVO_MAX_BYTES;
    foreach (['upload_max_filesize', 'post_max_size'] as $chave) {
        $ini = trim((string) ini_get($chave));
        $num = (int) $ini;
        $unidade = strtoupper(substr($ini, -1));
        $bytes = $unidade === 'G' ? $num * 1024 ** 3 : ($unidade === 'M' ? $num * 1024 ** 2 : ($unidade === 'K' ? $num * 1024 : $num));
        if ($bytes > 0) {
            $limite = min($limite, $bytes);
        }
    }
    return $limite;
}

/**
 * docx/xlsx são ZIPs. Algumas versões do libmagic devolvem
 * "application/zip"; nesse caso confirmamos o formato Office olhando o
 * conteúdo do pacote.
 */
function lead_arquivo_mime_office(string $caminho): ?string
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($caminho) !== true) {
        return null;
    }
    $tipos = $zip->locateName('[Content_Types].xml') !== false;
    $mime = null;
    if ($tipos && $zip->locateName('word/document.xml') !== false) {
        $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    } elseif ($tipos && $zip->locateName('xl/workbook.xml') !== false) {
        $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
    $zip->close();
    return $mime;
}

/**
 * Valida e grava $_FILES[$campo].
 * Retorna ['ok' => true, 'arquivo', 'mime', 'tamanho', 'nome_original'] ou ['ok' => false, 'erro'].
 */
function lead_arquivo_receber(string $campo): array
{
    $limiteMb = round(lead_arquivo_limite_bytes() / 1024 / 1024, 1);
    if (empty($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'erro' => 'Escolha um arquivo.'];
    }
    $arquivo = $_FILES[$campo];
    if (in_array($arquivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $arquivo['size'] > lead_arquivo_limite_bytes()) {
        return ['ok' => false, 'erro' => "O arquivo é maior que o limite de {$limiteMb} MB."];
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($arquivo['tmp_name']) || $arquivo['size'] < 1) {
        return ['ok' => false, 'erro' => 'Não foi possível receber o arquivo. Tente novamente.'];
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($arquivo['tmp_name']);
    if (in_array($mime, ['application/zip', 'application/octet-stream'], true)) {
        $mime = lead_arquivo_mime_office($arquivo['tmp_name']) ?? $mime;
    }
    if (!isset(lead_arquivo_tipos()[$mime])) {
        return ['ok' => false, 'erro' => 'Tipo de arquivo não permitido. Envie PDF, imagem (JPG, PNG, WEBP), Word (.docx) ou Excel (.xlsx).'];
    }
    if (strpos($mime, 'image/') === 0 && !@getimagesize($arquivo['tmp_name'])) {
        return ['ok' => false, 'erro' => 'A imagem enviada está corrompida.'];
    }

    if (!is_dir(LEAD_ARQUIVO_DIR) && !mkdir(LEAD_ARQUIVO_DIR, 0750, true)) {
        return ['ok' => false, 'erro' => 'Pasta de arquivos indisponível no servidor.'];
    }
    $nome = bin2hex(random_bytes(16));
    if (!move_uploaded_file($arquivo['tmp_name'], LEAD_ARQUIVO_DIR . '/' . $nome)) {
        return ['ok' => false, 'erro' => 'Não foi possível salvar o arquivo no servidor.'];
    }
    @chmod(LEAD_ARQUIVO_DIR . '/' . $nome, 0640);

    // Nome exibido: sem caminho, sem caracteres de controle, tamanho limitado.
    $original = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', basename((string) $arquivo['name']));
    $original = mb_substr(trim($original) ?: 'arquivo', 0, 200);

    return ['ok' => true, 'arquivo' => $nome, 'mime' => $mime, 'tamanho' => (int) $arquivo['size'], 'nome_original' => $original];
}

function lead_arquivo_caminho(string $arquivo): ?string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $arquivo)) {
        return null;
    }
    $caminho = LEAD_ARQUIVO_DIR . '/' . $arquivo;
    return is_file($caminho) ? $caminho : null;
}

function tamanho_legivel(int $bytes): string
{
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    return $bytes . ' B';
}
