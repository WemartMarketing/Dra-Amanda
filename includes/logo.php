<?php
/**
 * Marca do sistema — ÚNICO lugar onde ela é desenhada (menu lateral, login,
 * superadmin). Ainda não há arquivo oficial do logotipo: usamos a marca em
 * texto. Para trocar pelo logo oficial, salve a imagem em
 * assets/img/logo.svg (ou .png) e troque o conteúdo de logo_html() por
 *   '<img src="/assets/img/logo.svg" alt="WeMart Sales" class="brand-logo">'
 * — veja "Logo" no docs/README.md.
 */
function logo_html(): string
{
    return '<span class="brandmark" aria-hidden="true">W</span>WeMart <span class="hl">Sales</span>';
}
