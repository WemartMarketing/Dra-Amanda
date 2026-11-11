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
