<?php
/**
 * Marca do sistema — ÚNICO lugar onde ela é desenhada (menu lateral, login,
 * página de privacidade). Logotipo oficial em PNG com fundo transparente:
 *   - assets/img/wemart-sales-logo-branco.png → fundos escuros (menu lateral)
 *   - assets/img/wemart-sales-logo-escuro.png → fundos claros (login, documentos)
 *
 * @param string $fundo 'escuro' (padrão, logo branca) ou 'claro' (logo azul-marinho)
 */
function logo_html(string $fundo = 'escuro'): string
{
    $arquivo = $fundo === 'claro' ? 'wemart-sales-logo-escuro.png' : 'wemart-sales-logo-branco.png';
    return '<img src="/assets/img/' . $arquivo . '?v=1" alt="WeMart Sales" class="brand-logo" width="1269" height="148">';
}

/**
 * Ícone da aba do navegador (favicon) e da tela inicial do celular: o
 * foguete da logo em branco sobre um quadrado azul-marinho. Chame dentro
 * do <head> de toda página.
 */
function favicon_tags(): string
{
    return '<link rel="icon" href="/favicon.ico?v=1" sizes="any">' . "\n"
         . '<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon/favicon-32.png?v=1">' . "\n"
         . '<link rel="icon" type="image/png" sizes="192x192" href="/assets/img/favicon/icon-192.png?v=1">' . "\n"
         . '<link rel="apple-touch-icon" href="/assets/img/favicon/apple-touch-icon.png?v=1">';
}
