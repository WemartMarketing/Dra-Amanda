<?php
/**
 * Ponto único de registro dos tipos de tarefa da automação (WhatsApp e
 * Jornadas). Cada fase acrescenta aqui os seus tratadores; o cron
 * (cron/rotinas.php) e, quando fizer sentido, o webhook chamam
 * automacao_executar().
 *
 * Tarefas de tipo sem tratador ficam paradas na fila (não são perdidas).
 */
require_once __DIR__ . '/automacao_fila.php';
require_once __DIR__ . '/whatsapp.php';

/** @return array<string, callable(array): array> */
function automacao_tratadores(): array
{
    return [
        'wa_enviar' => 'wa_tarefa_enviar',          // Fase 2: envio de WhatsApp (real ou simulado)
        // Fase 4: 'jornada_passo' => 'jornada_tarefa_passo', …
    ];
}

function automacao_executar(int $limite = 200): array
{
    $travadas = fila_recuperar_travadas();
    $r = fila_processar(automacao_tratadores(), $limite);
    $r['travadas_retomadas'] = $travadas;
    return $r;
}
