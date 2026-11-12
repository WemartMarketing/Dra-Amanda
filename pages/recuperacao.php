<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/recuperacao.php';

$usuario = auth_require();
$crumb = 'Recuperação de leads';
$activeNav = 'recuperacao';
if (!usuario_tem_permissao($usuario, 'configurar_recuperacao')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Configurar recuperação de leads');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$regra = recuperacao_regra($empresaId);
$categorias = recuperacao_categorias_permitidas();

// Números da empresa (Master): só contagens.
$stmt = db()->prepare("SELECT COUNT(*) FROM lead_transferencias WHERE empresa_id = :e AND motivo = 'inatividade' AND criado_em >= :d");
$stmt->execute(['e' => $empresaId, 'd' => date('Y-m-d', strtotime('-30 days'))]);
$perdas30 = (int) $stmt->fetchColumn();
$stmt = db()->prepare("SELECT COUNT(*) FROM roleta_fila f INNER JOIN leads l ON l.id = f.lead_id AND l.excluido_em IS NULL AND l.responsavel_id IS NULL
    WHERE f.empresa_id = :e AND f.origem = 'recuperacao' AND f.resolvido_em IS NULL");
$stmt->execute(['e' => $empresaId]);
$naFila = (int) $stmt->fetchColumn();
$emRisco = 0;
if ($regra['ativa'] && $regra['avisar_dias_antes'] > 0) {
    [$cond, $p] = recuperacao_condicoes($regra, $regra['dias_sem_feedback'] - $regra['avisar_dias_antes']);
    $stmt = db()->prepare('SELECT COUNT(*) FROM leads WHERE leads.empresa_id = :rc_e AND ' . $cond);
    $stmt->execute($p + ['rc_e' => $empresaId]);
    $emRisco = (int) $stmt->fetchColumn();
}

$pageTitle = 'Recuperação de leads — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>

<div class="header">
    <div>
        <div class="eyebrow">Configurações</div>
        <h1>Recuperação de leads</h1>
        <p>Lead sem <b>feedback válido</b> pelo prazo definido sai do corretor e volta para a roleta — sem cair de novo para quem o perdeu.</p>
    </div>
    <div class="actions">
        <a href="/pages/relatorios.php#perdas-inatividade" class="btn btn-outline"><?= icon('chart') ?>Ver perdas nos relatórios</a>
    </div>
</div>

<div class="kpi-grid kpi-grid-3">
    <div class="kpi"><div class="kpi-top"><span class="label">Perdas por inatividade</span><div class="kpi-icon"><?= icon('clock') ?></div></div><div class="kpi-value"><?= $perdas30 ?></div><div class="kpi-sub">nos últimos 30 dias</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Aguardando novo corretor</span><div class="kpi-icon"><?= icon('zap') ?></div></div><div class="kpi-value"><?= $naFila ?></div><div class="kpi-sub">na fila da roleta</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Em risco agora</span><div class="kpi-icon"><?= icon('alert') ?></div></div><div class="kpi-value"><?= $emRisco ?></div><div class="kpi-sub">corretor já foi avisado</div></div>
</div>

<div class="grid-2">
    <div class="card form-card">
        <div class="card-head"><div><h2>Regra da empresa</h2><p>Vale para todos os corretores. A rotina roda a cada 15 minutos.</p></div>
            <span class="pill <?= $regra['ativa'] ? 'pill-green' : 'pill-muted' ?>"><?= $regra['ativa'] ? 'Ativa' : 'Desligada' ?></span></div>
        <form data-api-form action="/api/recuperacao_salvar.php">
            <label class="checkbox-row"><input type="checkbox" name="ativa" value="1" <?= $regra['ativa'] ? 'checked' : '' ?>> Recuperação automática ligada</label>
            <div class="form-grid" style="margin-top:14px;">
                <div class="field"><label for="dias">Dias sem feedback válido</label>
                    <input type="number" id="dias" name="dias_sem_feedback" min="1" max="60" required value="<?= (int) $regra['dias_sem_feedback'] ?>">
                    <small class="field-hint">Depois disso o lead sai do corretor.</small></div>
                <div class="field"><label for="aviso">Avisar o corretor antes (dias)</label>
                    <input type="number" id="aviso" name="avisar_dias_antes" min="0" max="59" required value="<?= (int) $regra['avisar_dias_antes'] ?>">
                    <small class="field-hint">Aparece no Início e no Kanban: “Vai para recuperação amanhã”. 0 = sem aviso.</small></div>
            </div>
            <div class="subhead">Etapas sujeitas à recuperação</div>
            <div class="checkbox-grid">
                <?php foreach ($categorias as $valor => $rotulo): ?>
                    <label class="checkbox-row"><input type="checkbox" name="categorias[]" value="<?= e($valor) ?>" <?= in_array($valor, $regra['lista_categorias'], true) ? 'checked' : '' ?>> <?= e($rotulo) ?></label>
                <?php endforeach; ?>
            </div>
            <small class="field-hint">Sem interesse, contrato assinado e perdido nunca entram. Lead com visita ou retorno agendado para o futuro também fica com o corretor.</small>
            <label class="checkbox-row" style="margin-top:14px;"><input type="checkbox" name="redistribuir_automaticamente" value="1" <?= $regra['redistribuir_automaticamente'] ? 'checked' : '' ?>> Redistribuir pela roleta na hora (senão o lead espera na fila para um gestor decidir)</label>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-primary" style="margin-top:16px;"><?= icon('check') ?>Salvar regra</button>
        </form>
    </div>

    <div class="card">
        <div class="card-head"><div><h2>O que conta como feedback válido</h2><p>Só ações que comprovam atendimento reiniciam o prazo.</p></div></div>
        <ul class="regra-lista">
            <li class="is-ok"><?= icon('check') ?>Mudar a etapa do lead</li>
            <li class="is-ok"><?= icon('check') ?>Registrar atendimento ou contato com o que aconteceu</li>
            <li class="is-ok"><?= icon('check') ?>Marcar visita/retorno como realizado ou cliente ausente</li>
            <li class="is-ok"><?= icon('check') ?>Receber o lead (cadastro, roleta, transferência, oferta ativa)</li>
            <li class="is-ok"><?= icon('check') ?>Responder o cliente pela Central de conversas (mensagem real; no modo de teste não conta)</li>
            <li class="is-ok"><?= icon('check') ?>Responder “Sim, respondeu” ou “Não respondeu” em Pendências</li>
            <li class="is-no"><?= icon('x') ?>Anotação, arquivo ou edição de dados</li>
            <li class="is-no"><?= icon('x') ?>Só clicar em Ligar ou WhatsApp</li>
            <li class="is-no"><?= icon('x') ?>Só criar um compromisso (mas visita/retorno futuro segura o lead)</li>
        </ul>
        <div class="subhead">Com o WhatsApp e as jornadas</div>
        <ul class="regra-lista">
            <li class="is-ok"><?= icon('check') ?><span>Lead de corretor <b>desativado</b> vai para a recuperação na próxima rodada, sem esperar o prazo</span></li>
            <li class="is-ok"><?= icon('check') ?>O novo corretor recebe o histórico, a conversa do WhatsApp e as pendências (“responder o cliente”)</li>
            <li class="is-ok"><?= icon('check') ?>As jornadas continuam de onde estavam: boas-vindas não são enviadas de novo e o limite de mensagens já conta o que foi enviado</li>
        </ul>
        <div class="rule-box"><?= icon('alert') ?><span>Quando o lead é recuperado, o gerente e a superintendência anteriores deixam de ver o cadastro e ficam só com o número agregado em Relatórios → Perdas por inatividade.</span></div>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
