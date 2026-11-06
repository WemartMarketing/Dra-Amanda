<?php
/**
 * Ícones inline (SVG, estilo stroke) — evita depender de uma CDN de
 * ícones (requisito de funcionar sem Node/pacotes externos).
 */
function icon(string $name): string
{
    $icons = [
        'home'     => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v9a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1v-9"/>',
        'kanban'   => '<rect x="3" y="4" width="7" height="16" rx="1.5"/><rect x="14" y="4" width="7" height="10" rx="1.5"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.5-7 8-7s8 3 8 7"/>',
        'chart'    => '<path d="M4 19V5"/><path d="M4 19h16"/><rect x="7" y="11" width="3" height="6"/><rect x="12" y="7" width="3" height="10"/><rect x="17" y="13" width="3" height="4"/>',
        'pin'      => '<path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.3"/>',
        'shield'   => '<path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6Z"/>',
        'bell'     => '<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6Z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'arrow-r'  => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'plus'     => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'phone'    => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2C9.6 21 3 14.4 3 6a2 2 0 0 1 2-2Z"/>',
        'whatsapp' => '<path d="M4 20l1.3-3.9A8 8 0 1 1 8.9 19L4 20Z"/><path d="M8.5 9.5c.3 2.8 2.7 5.2 5.5 5.5" stroke-linecap="round"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4"/><path d="M16 3v4"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check'    => '<path d="M20 6 9 17l-5-5"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'chevron'  => '<path d="m6 9 6 6 6-6"/>',
        'filter'   => '<path d="M4 5h16l-6 8v5l-4 2v-7Z"/>',
        'zap'      => '<path d="M13 3 4 14h6l-1 7 9-11h-6Z"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 8h1M14 8h1M9 12h1M14 12h1M9 16h1M14 16h1"/>',
        'alert'    => '<path d="M12 3 2 20h20Z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
    ];
    $inner = $icons[$name] ?? $icons['home'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
}
