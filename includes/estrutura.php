<?php
/**
 * Estrutura comercial (Etapa 2): rótulos, upload seguro de imagem de
 * empreendimento e a tela padrão de "Acesso restrito".
 */

const EMPREENDIMENTO_IMAGEM_MAX_BYTES = 3 * 1024 * 1024;
const EMPREENDIMENTO_IMAGEM_DIR = __DIR__ . '/../uploads/empreendimentos';

function empreendimento_status_rotulos(): array
{
    return [
        'lancamento' => 'Lançamento',
        'em_obras'   => 'Em obras',
        'pronto'     => 'Pronto para morar',
        'esgotado'   => 'Esgotado',
        'suspenso'   => 'Vendas suspensas',
    ];
}

/** Classe de pill por status (verde = vendendo, âmbar = atenção, cinza = parado). */
function empreendimento_status_pill(string $status): string
{
    return [
        'lancamento' => 'pill-green',
        'em_obras'   => 'pill-green',
        'pronto'     => 'pill-green',
        'esgotado'   => 'pill-muted',
        'suspenso'   => 'pill-amber',
    ][$status] ?? 'pill-muted';
}

function empreendimento_imagem_url(?string $imagem): ?string
{
    if (!$imagem || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $imagem)) {
        return null;
    }
    return '/uploads/empreendimentos/' . $imagem;
}

function estados_brasil(): array
{
    return ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
}

/** Limite efetivo: o menor entre 3 MB e o upload_max_filesize do servidor. */
function upload_limite_bytes(): int
{
    $ini = trim((string) ini_get('upload_max_filesize'));
    $num = (int) $ini;
    $unidade = strtoupper(substr($ini, -1));
    $bytes = $unidade === 'G' ? $num * 1024 ** 3 : ($unidade === 'M' ? $num * 1024 ** 2 : ($unidade === 'K' ? $num * 1024 : $num));
    return $bytes > 0 ? min(EMPREENDIMENTO_IMAGEM_MAX_BYTES, $bytes) : EMPREENDIMENTO_IMAGEM_MAX_BYTES;
}

/**
 * Valida e grava a imagem enviada em $_FILES[$campo].
 * Retorna ['ok' => true, 'arquivo' => 'hash.ext'] | ['ok' => true, 'arquivo' => null] (nada enviado)
 *       | ['ok' => false, 'erro' => mensagem].
 *
 * O tipo é decidido pelo conteúdo (finfo + getimagesize), nunca pela
 * extensão ou pelo Content-Type do navegador; a imagem é decodificada e
 * re-gravada pelo GD (quando disponível), o que descarta qualquer
 * conteúdo extra embutido no arquivo. Nome final aleatório, extensão
 * definida pelo tipo real.
 */
function empreendimento_salvar_imagem(string $campo): array
{
    if (empty($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'arquivo' => null];
    }
    $arquivo = $_FILES[$campo];
    $limiteMb = round(upload_limite_bytes() / 1024 / 1024, 1);

    if (in_array($arquivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        return ['ok' => false, 'erro' => "A imagem é maior que o limite de {$limiteMb} MB."];
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($arquivo['tmp_name'])) {
        return ['ok' => false, 'erro' => 'Não foi possível receber a imagem. Tente novamente.'];
    }
    if ($arquivo['size'] > upload_limite_bytes()) {
        return ['ok' => false, 'erro' => "A imagem é maior que o limite de {$limiteMb} MB."];
    }

    $tiposPermitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($arquivo['tmp_name']);
    $info = @getimagesize($arquivo['tmp_name']);
    if (!isset($tiposPermitidos[$mime]) || !$info || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1) {
        return ['ok' => false, 'erro' => 'Envie uma imagem JPG, PNG ou WEBP válida.'];
    }
    if ($info[0] * $info[1] > 40000000) {
        return ['ok' => false, 'erro' => 'Imagem com resolução grande demais. Use até 40 megapixels.'];
    }

    if (!is_dir(EMPREENDIMENTO_IMAGEM_DIR) && !mkdir(EMPREENDIMENTO_IMAGEM_DIR, 0755, true)) {
        return ['ok' => false, 'erro' => 'Pasta de imagens indisponível no servidor.'];
    }

    $extensao = $tiposPermitidos[$mime];
    $nome = bin2hex(random_bytes(16)) . '.' . $extensao;
    $destino = EMPREENDIMENTO_IMAGEM_DIR . '/' . $nome;

    $gravou = false;
    if (function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring(file_get_contents($arquivo['tmp_name']));
        if ($img === false) {
            return ['ok' => false, 'erro' => 'Envie uma imagem JPG, PNG ou WEBP válida.'];
        }
        if ($extensao === 'png' || $extensao === 'webp') {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        $gravou = $extensao === 'jpg' ? imagejpeg($img, $destino, 85)
            : ($extensao === 'png' ? imagepng($img, $destino, 6) : imagewebp($img, $destino, 85));
        imagedestroy($img);
    } else {
        $gravou = move_uploaded_file($arquivo['tmp_name'], $destino);
    }

    if (!$gravou) {
        @unlink($destino);
        return ['ok' => false, 'erro' => 'Não foi possível salvar a imagem no servidor.'];
    }
    @chmod($destino, 0644);
    return ['ok' => true, 'arquivo' => $nome];
}

function empreendimento_remover_imagem(?string $imagem): void
{
    if ($imagem && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $imagem)) {
        @unlink(EMPREENDIMENTO_IMAGEM_DIR . '/' . $imagem);
    }
}

/** Renderiza a página "Acesso restrito" dentro do layout e encerra. */
function pagina_acesso_restrito(string $crumb, string $activeNav, string $rotuloPermissao): void
{
    global $usuario;
    http_response_code(403);
    $pageTitle = 'Acesso restrito — ' . APP_NAME;
    require __DIR__ . '/layout_start.php';
    echo '<div class="card stub-card"><div class="stub-icon">' . icon('alert') . '</div><h2>Acesso restrito</h2><p>Sua função não tem a permissão "' . htmlspecialchars($rotuloPermissao) . '".</p></div>';
    require __DIR__ . '/layout_end.php';
    exit;
}
