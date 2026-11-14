<?php
/**
 * Card "Contratos" da ficha do lead + modais (registrar, corrigir, distrato).
 */
require_once __DIR__ . '/contratos.php';

function contrato_moeda(?string $v): string
{
    return $v === null ? 'Não informado' : 'R$ ' . number_format((float) $v, 2, ',', '.');
}

function render_contratos_card(array $lead, array $contratos, bool $podeEditar, bool $podeValidar): void
{
    $pill = ['registrado' => 'pill-amber', 'validado' => 'pill-green', 'distratado' => 'pill-muted'];
    $leadId = (int) $lead['id'];
    ?>
    <div class="card contrato-card">
        <div class="card-head">
            <div><h2>Contrato</h2><p>A venda conta nos relatórios quando o contrato é registrado e <b>validado</b>. Mover para "Contrato assinado" no Kanban sozinho é só um sinal.</p></div>
            <?php if ($podeEditar): ?><button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-contrato-novo"><?= icon('plus') ?>Registrar contrato</button><?php endif; ?>
        </div>
        <?php if (!$contratos): ?>
            <p class="empty-note"><?= $lead['etapa'] === 'contrato_assinado' ? 'O lead está em "Contrato assinado", mas o contrato ainda não foi registrado.' : 'Nenhum contrato registrado.' ?></p>
        <?php endif; ?>
        <?php foreach ($contratos as $c): $cid = (int) $c['id']; ?>
            <div class="contrato-linha">
                <div class="contrato-info">
                    <strong>Assinado em <?= date('d/m/Y', strtotime($c['assinado_em'])) ?><?= $c['unidade'] ? ' · Unidade ' . e($c['unidade']) : '' ?></strong>
                    <span>VGV: <?= e(contrato_moeda($c['valor_vgv'])) ?> · Corretor: <?= e($c['corretor_nome'] ?? '—') ?><?= $c['empreendimento_nome'] ? ' · ' . e($c['empreendimento_nome']) : '' ?></span>
                    <span class="muted">Registrado por <?= e($c['registrado_por_nome'] ?? '—') ?> em <?= date('d/m/Y H\hi', strtotime($c['registrado_em'])) ?><?php if ($c['validado_em']): ?> · validado por <?= e($c['validado_por_nome'] ?? '—') ?> em <?= date('d/m/Y H\hi', strtotime($c['validado_em'])) ?><?php endif; ?></span>
                    <?php if ($c['status'] === 'distratado'): ?><span class="muted">Distrato em <?= date('d/m/Y', strtotime($c['distratado_em'])) ?>: <?= e($c['distrato_motivo']) ?></span><?php endif; ?>
                </div>
                <span class="pill <?= $pill[$c['status']] ?>"><?= e(contrato_status()[$c['status']]) ?></span>
                <div class="contrato-acoes">
                    <?php if ($c['status'] === 'registrado' && $podeValidar): ?>
                        <button type="button" class="btn btn-primary btn-sm" data-api-acao="/api/lead_contrato.php" data-acao="validar" data-id="<?= $cid ?>" data-confirmar="Validar este contrato? Ele passa a contar como venda nos relatórios."><?= icon('check') ?>Validar</button>
                    <?php endif; ?>
                    <?php if ($c['status'] === 'registrado' && $podeEditar): ?>
                        <button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-contrato-<?= $cid ?>"><?= icon('edit') ?>Corrigir</button>
                    <?php endif; ?>
                    <?php if ($c['status'] !== 'distratado' && $podeValidar): ?>
                        <button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-distrato-<?= $cid ?>">Distrato</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    if ($podeEditar) {
        render_modal_contrato('modal-contrato-novo', 'Registrar contrato', ['acao' => 'registrar', 'lead_id' => $leadId], null, $lead['etapa'] !== 'contrato_assinado');
        foreach ($contratos as $c) {
            if ($c['status'] === 'registrado') {
                render_modal_contrato('modal-contrato-' . (int) $c['id'], 'Corrigir contrato', ['acao' => 'editar', 'id' => (int) $c['id']], $c, false);
            }
        }
    }
    if ($podeValidar) {
        foreach ($contratos as $c) {
            if ($c['status'] === 'distratado') {
                continue;
            }
            $cid = (int) $c['id']; ?>
            <div class="modal-overlay" id="modal-distrato-<?= $cid ?>" hidden>
                <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="titulo-distrato-<?= $cid ?>">
                    <button type="button" class="overlay-close" data-close-modal="modal-distrato-<?= $cid ?>" aria-label="Fechar">&times;</button>
                    <h2 id="titulo-distrato-<?= $cid ?>">Registrar distrato</h2>
                    <p class="muted">O contrato continua no histórico, marcado como distratado, e deixa de contar como venda ativa.</p>
                    <form data-api-form action="/api/lead_contrato.php" data-recarregar>
                        <input type="hidden" name="acao" value="distratar"><input type="hidden" name="id" value="<?= $cid ?>">
                        <div class="field"><label for="dist-data-<?= $cid ?>">Data do distrato</label><input type="date" id="dist-data-<?= $cid ?>" name="distratado_em" required min="<?= e($c['assinado_em']) ?>" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></div>
                        <div class="field"><label for="dist-motivo-<?= $cid ?>">Motivo</label><input type="text" id="dist-motivo-<?= $cid ?>" name="motivo" required maxlength="190" placeholder="Ex.: crédito não aprovado"></div>
                        <div class="form-alert" data-form-alerta hidden></div>
                        <button type="submit" class="btn btn-primary"><?= icon('check') ?>Registrar distrato</button>
                    </form>
                </div>
            </div>
        <?php }
    }
}

function render_modal_contrato(string $id, string $titulo, array $ocultos, ?array $c, bool $oferecerMover): void
{
    ?>
    <div class="modal-overlay" id="<?= e($id) ?>" hidden>
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="<?= e($id) ?>-titulo">
            <button type="button" class="overlay-close" data-close-modal="<?= e($id) ?>" aria-label="Fechar">&times;</button>
            <h2 id="<?= e($id) ?>-titulo"><?= e($titulo) ?></h2>
            <form data-api-form action="/api/lead_contrato.php" data-recarregar>
                <?php foreach ($ocultos as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
                <div class="form-grid">
                    <div class="field"><label for="<?= e($id) ?>-data">Data de assinatura *</label><input type="date" id="<?= e($id) ?>-data" name="assinado_em" required max="<?= date('Y-m-d') ?>" value="<?= e($c['assinado_em'] ?? date('Y-m-d')) ?>"></div>
                    <div class="field"><label for="<?= e($id) ?>-valor">Valor do contrato (VGV)</label><input type="text" id="<?= e($id) ?>-valor" name="valor_vgv" inputmode="decimal" placeholder="Ex.: 450.000,00" value="<?= $c && $c['valor_vgv'] !== null ? e(number_format((float) $c['valor_vgv'], 2, ',', '.')) : '' ?>"></div>
                    <div class="field"><label for="<?= e($id) ?>-un">Unidade</label><input type="text" id="<?= e($id) ?>-un" name="unidade" maxlength="60" placeholder="Ex.: Torre B, apto 1204" value="<?= e($c['unidade'] ?? '') ?>"></div>
                </div>
                <div class="field"><label for="<?= e($id) ?>-obs">Observação</label><textarea id="<?= e($id) ?>-obs" name="observacao" maxlength="500"><?= e($c['observacao'] ?? '') ?></textarea></div>
                <p class="muted">VGV é o Valor Geral de Vendas do contrato — não é receita nem comissão. Pode ficar em branco.</p>
                <?php if ($oferecerMover): ?><label class="checkbox-row"><input type="checkbox" name="mover_etapa" value="1" checked> Mover o lead para "Contrato assinado"</label><?php endif; ?>
                <div class="form-alert" data-form-alerta hidden></div>
                <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar</button>
            </form>
        </div>
    </div>
    <?php
}
