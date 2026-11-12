<?php
/**
 * Rotinas automáticas — UMA linha de cron a cada 15 minutos (cPanel):
 *   *\/15 * * * * /usr/local/bin/php /home/USUARIO/caminho/cron/rotinas.php >/dev/null 2>&1
 *
 *   1. encerra presenças vencidas na roleta;
 *   2. tenta distribuir a fila de espera da roleta;
 *   3. tenta de novo os avisos do Meta Lead Ads com erro (espera crescente) e
 *      sincroniza os criativos dos anúncios que geraram leads;
 *   4. aplica a recuperação automática por inatividade;
 *   5. apaga arquivos temporários de importação com mais de 1 hora;
 *   6. reprocessa avisos do WhatsApp que falharam (espera crescente);
 *   7. agenda o próximo lote das campanhas de WhatsApp em andamento;
 *   8. executa a fila de automação (envios de WhatsApp, Jornadas) — tarefas vencidas.
 *
 * Só roda pela linha de comando (403 via navegador). GET_LOCK impede duas
 * execuções ao mesmo tempo; cada etapa falha sozinha sem parar as outras.
 */
require_once __DIR__ . '/../includes/terminal.php';
terminal_exigir();

require_once __DIR__ . '/../includes/roleta.php';
require_once __DIR__ . '/../includes/meta.php';
require_once __DIR__ . '/../includes/recuperacao.php';
require_once __DIR__ . '/../includes/importacao.php';
require_once __DIR__ . '/../includes/automacao.php';
require_once __DIR__ . '/../includes/campanhas.php';

$pdo = db();
// Mesma trava de cron/processar_fila.php: as duas nunca rodam juntas.
if ((int) $pdo->query("SELECT GET_LOCK('wemart_fila', 0)")->fetchColumn() !== 1) {
    fwrite(STDOUT, "Outra execução em andamento — nada a fazer.\n");
    exit(0);
}
$log = [];
$etapa = function (string $nome, callable $fn) use (&$log) {
    try {
        $log[] = $nome . ': ' . $fn();
    } catch (Throwable $e) {
        $log[] = $nome . ': ERRO ' . meta_texto_seguro($e->getMessage(), [], 200);
        error_log('[cron/rotinas] ' . $nome . ': ' . $e->getMessage());
    }
};
try {
    $etapa('presenças encerradas', fn () => (string) roleta_fechar_expiradas());
    $etapa('fila', function () {
        $r = roleta_processar_fila(null, null, 500);
        return $r['tentados'] . ' tentado(s), ' . $r['distribuidos'] . ' distribuído(s)';
    });
    $etapa('meta', function () {
        $r = meta_processar_pendentes(100);
        return $r['tentados'] . ' tentado(s), ' . $r['processados'] . ' lead(s), ' . $r['duplicados'] . ' duplicado(s), ' . $r['erros'] . ' erro(s)';
    });
    $etapa('criativos', function () {
        require_once __DIR__ . '/../includes/meta_criativos.php';
        if (!modulo_tabela_existe('meta_anuncios')) {
            return 'migração 011 pendente';
        }
        $r = meta_criativos_processar(30);
        return $r['tentados'] . ' anúncio(s), ' . $r['ok'] . ' ok, ' . $r['falhas'] . ' falha(s)';
    });
    $etapa('recuperação', function () {
        $r = recuperacao_executar(null, 500);
        return $r['perdidos'] . ' recuperado(s), ' . $r['redistribuidos'] . ' redistribuído(s), ' . $r['em_fila'] . ' na fila';
    });
    $etapa('temporários', fn () => importacao_limpar_temporarios(3600) . ' apagado(s)');
    $etapa('whatsapp', function () {
        $r = wa_processar_pendentes(300);
        return $r['tentados'] . ' aviso(s), ' . $r['processados'] . ' ok, ' . $r['erros'] . ' erro(s)';
    });
    $etapa('campanhas', function () {
        $r = campanhas_processar();
        return $r['campanhas'] . ' campanha(s), ' . $r['agendadas'] . ' envio(s) agendado(s), ' . $r['ignoradas'] . ' não enviado(s), ' . $r['concluidas'] . ' concluída(s)';
    });
    $etapa('automação', function () {
        $r = automacao_executar(200);
        return $r['executadas'] . ' tarefa(s), ' . $r['ok'] . ' ok, ' . $r['reagendadas'] . ' reagendada(s), ' . $r['falhas'] . ' falha(s), ' . $r['travadas_retomadas'] . ' retomada(s)';
    });
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . implode(' · ', $log) . "\n");
} finally {
    $pdo->query("SELECT RELEASE_LOCK('wemart_fila')");
}
