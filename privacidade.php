<?php
/**
 * Página pública com as orientações de privacidade (docs/LGPD.md),
 * linkada no rodapé e no login. Converte o Markdown simples (títulos,
 * listas, parágrafos, negrito) — todo o texto é escapado.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/logo.php';

$md = (string) @file_get_contents(__DIR__ . '/docs/LGPD.md');
$html = '';
$lista = false;
foreach (preg_split('/\R/', $md) as $linha) {
    $t = trim($linha);
    $fmt = fn ($s) => preg_replace(['/\*\*(.+?)\*\*/', '/`(.+?)`/'], ['<b>$1</b>', '<code>$1</code>'], htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
    if (preg_match('/^[-*] (.+)$/', $t, $m)) {
        if (!$lista) { $html .= '<ul>'; $lista = true; }
        $html .= '<li>' . $fmt($m[1]) . '</li>';
        continue;
    }
    if ($lista) { $html .= '</ul>'; $lista = false; }
    if ($t === '') {
        continue;
    }
    if (preg_match('/^(#{1,3}) (.+)$/', $t, $m)) {
        $n = strlen($m[1]);
        $html .= "<h{$n}>" . $fmt($m[2]) . "</h{$n}>";
    } else {
        $html .= '<p>' . $fmt($t) . '</p>';
    }
}
if ($lista) { $html .= '</ul>'; }
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Privacidade e uso dos dados — <?= htmlspecialchars(APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
<?= favicon_tags() ?>
</head>
<body>
<main class="legal-doc card">
    <div class="brand" style="padding-left:0"><?= logo_html('claro') ?></div>
    <?= $html ?: '<p>Documento indisponível.</p>' ?>
    <p><a href="/login.php">Voltar</a></p>
</main>
</body>
</html>
