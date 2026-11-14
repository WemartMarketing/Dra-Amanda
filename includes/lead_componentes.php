<?php
require_once __DIR__ . '/whatsapp_conversas.php';
/**
 * Componentes de interface reaproveitados entre Kanban, lista de leads,
 * ficha e agenda: card do lead e os modais "Mover etapa", "Transferir" e
 * "Agendar". Todo texto vindo do banco passa por e().
 */
require_once __DIR__ . '/leads_lista.php';
require_once __DIR__ . '/agenda.php';

function e(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

/** Card do lead (Kanban e lista em cards no celular). */
function render_lead_card(array $lead, bool $mostrarResponsavel, bool $podeMover): void
{
    $whats = wa_link_conversar(current_user(), $lead);
    $tel = lead_telefone_normalizado($lead['telefone']);
    ?>
    <article class="kcard<?= $lead['atrasado'] ? ' is-late' : '' ?>" <?= $podeMover ? 'draggable="true"' : '' ?> data-id="<?= (int) $lead['id'] ?>" data-etapa="<?= (int) $lead['etapa_id'] ?>">
        <div class="kcard-top">
            <a class="kcard-name" href="/pages/lead.php?id=<?= (int) $lead['id'] ?>"><?= e($lead['nome']) ?></a>
            <?php if ($lead['prioridade'] === 'alta'): ?><span class="pill pill-red">Alta</span><?php endif; ?>
        </div>
        <div class="kcard-meta">
            <span><?= e(lead_formatar_telefone($lead['telefone'])) ?></span>
            <span><?= e($lead['empreendimento_nome'] ?? 'Sem empreendimento') ?></span>
            <?php if ($mostrarResponsavel): ?><span class="kcard-resp"><?= icon('user') ?><?= e($lead['responsavel_nome'] ?? '—') ?></span><?php endif; ?>
        </div>
        <?php if (($lead['origem'] ?? '') === 'meta_ads' || !empty($lead['meta_ad_id'])): ?>
            <button type="button" class="kcard-criativo" data-criativo-lead="<?= (int) $lead['id'] ?>" aria-label="Ver criativo do anúncio de <?= e($lead['nome']) ?>"><?= icon('anuncio') ?>Ver criativo</button>
        <?php endif; ?>
        <div class="kcard-next<?= $lead['atrasado'] ? ' is-late' : '' ?>">
            <?= icon($lead['atrasado'] ? 'alert' : 'calendar') ?>
            <span><?= $lead['atrasado'] ? '<b>Atrasado</b> · ' : '' ?><?= e(lead_proxima_acao($lead)) ?></span>
        </div>
        <?php if (!empty($lead['recuperacao_quando'])): ?>
            <div class="kcard-recup"><?= icon('alert') ?><span>Vai para recuperação <b><?= e($lead['recuperacao_quando']) ?></b> — registre o atendimento</span></div>
        <?php endif; ?>
        <div class="kcard-foot"><?= icon('clock') ?> Última atividade: <?= e(mb_strtolower(tempo_relativo($lead['ultima_atividade_em'] ?? $lead['criado_em']))) ?></div>
        <div class="kcard-actions">
            <?php if ($whats): ?>
                <a class="icon-btn icon-btn-whats" <?= wa_link_atributos($whats, (int) $lead['id']) ?> aria-label="Conversar no WhatsApp com <?= e($lead['nome']) ?>"><?= icon('whatsapp') ?></a>
                <a class="icon-btn" href="tel:<?= e($tel) ?>" data-registrar="ligacao" data-lead="<?= (int) $lead['id'] ?>" aria-label="Ligar para <?= e($lead['nome']) ?>"><?= icon('phone') ?></a>
            <?php endif; ?>
            <?php if ($podeMover): ?>
                <button type="button" class="btn btn-outline btn-sm kcard-move-btn" data-mover-lead="<?= (int) $lead['id'] ?>" data-etapa-atual="<?= (int) $lead['etapa_id'] ?>" data-lead-nome="<?= e($lead['nome']) ?>"><?= icon('move') ?>Mover etapa</button>
            <?php endif; ?>
            <a class="icon-btn" href="/pages/lead.php?id=<?= (int) $lead['id'] ?>" aria-label="Abrir ficha de <?= e($lead['nome']) ?>"><?= icon('arrow-r') ?></a>
        </div>
    </article>
    <?php
}

/** Modal com a lista de etapas (um botão grande por etapa). */
function render_modal_mover_etapa(array $etapas): void
{
    ?>
    <div class="modal-overlay" id="modal-mover" hidden>
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-mover-titulo">
            <button type="button" class="overlay-close" data-close-modal="modal-mover" aria-label="Fechar">&times;</button>
            <h2 id="modal-mover-titulo">Mover etapa</h2>
            <p class="muted" data-mover-nome></p>
            <div class="etapa-lista">
                <?php foreach ($etapas as $etapa): ?>
                    <button type="button" class="etapa-opcao" data-destino-etapa="<?= (int) $etapa['id'] ?>">
                        <span class="etapa-dot" style="background: <?= e($etapa['cor']) ?>"></span><?= e($etapa['nome']) ?>
                        <small class="etapa-atual-tag">Etapa atual</small>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
}

/** Modal de transferência (um ou vários leads). */
function render_modal_transferir(array $destinos): void
{
    ?>
    <div class="modal-overlay" id="modal-transferir" hidden>
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-transferir-titulo">
            <button type="button" class="overlay-close" data-close-modal="modal-transferir" aria-label="Fechar">&times;</button>
            <h2 id="modal-transferir-titulo">Transferir lead</h2>
            <p class="muted" data-transferir-resumo>O lead passa para o corretor escolhido. A equipe anterior deixa de ver o cadastro e fica só com o número agregado nos relatórios.</p>
            <form data-api-form action="/api/lead_transferir.php" data-recarregar>
                <div data-transferir-ids></div>
                <div class="field">
                    <label for="transferir-destino">Novo responsável</label>
                    <select id="transferir-destino" name="destino_id" required>
                        <option value="">Escolha o corretor</option>
                        <?php foreach ($destinos as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"><?= e($d['nome']) ?><?= $d['equipe_nome'] ? ' — ' . e($d['equipe_nome']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="transferir-obs">Motivo (opcional)</label>
                    <input type="text" id="transferir-obs" name="observacao" maxlength="500" placeholder="Ex.: corretor de férias">
                </div>
                <button type="submit" class="btn btn-primary"><?= icon('users') ?>Transferir</button>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Modal "Agendar" (visita/retorno/tarefa). Com $lead, fica preso a ele;
 * $responsaveis (gestores) permite agendar para alguém da equipe.
 */
function render_modal_agendar(?array $lead, array $responsaveis, bool $podeMoverEtapa, string $tipoPadrao = 'visita'): void
{
    $sugestao = date('Y-m-d\TH:i', strtotime('tomorrow 10:00'));
    ?>
    <div class="modal-overlay" id="modal-agendar" hidden>
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-agendar-titulo">
            <button type="button" class="overlay-close" data-close-modal="modal-agendar" aria-label="Fechar">&times;</button>
            <h2 id="modal-agendar-titulo">Novo compromisso</h2>
            <?php if ($lead): ?><p class="muted">Cliente: <b><?= e($lead['nome']) ?></b></p><?php endif; ?>
            <form data-api-form action="/api/agenda_salvar.php" data-recarregar>
                <?php if ($lead): ?><input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>"><?php endif; ?>
                <fieldset class="field segmented">
                    <legend>Tipo</legend>
                    <?php foreach (agenda_tipos() as $valor => $rotulo): ?>
                        <label><input type="radio" name="tipo" value="<?= $valor ?>" <?= $valor === $tipoPadrao ? 'checked' : '' ?> data-tipo-agenda><span><?= icon(agenda_tipo_icon($valor)) ?><?= $rotulo ?></span></label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="field">
                    <label for="agendar-inicio">Data e horário</label>
                    <input type="datetime-local" id="agendar-inicio" name="inicio" required value="<?= $sugestao ?>">
                </div>
                <div class="field">
                    <label for="agendar-titulo">Título (opcional)</label>
                    <input type="text" id="agendar-titulo" name="titulo" maxlength="190" placeholder="<?= $lead ? 'Ex.: Visita ao decorado' : 'Ex.: Ligar para cliente' ?>">
                </div>
                <?php if ($responsaveis): ?>
                    <div class="field">
                        <label for="agendar-responsavel">Responsável</label>
                        <select id="agendar-responsavel" name="usuario_id">
                            <option value=""><?= $lead ? 'Responsável pelo lead' : 'Eu mesmo' ?></option>
                            <?php foreach ($responsaveis as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['nome']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label for="agendar-obs">Observação (opcional)</label>
                    <input type="text" id="agendar-obs" name="observacao" maxlength="500">
                </div>
                <?php if ($lead && $podeMoverEtapa): ?>
                    <label class="checkbox-row" data-so-visita><input type="checkbox" name="mover_etapa" value="1" checked> Mover o lead para "Visita agendada"</label>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= icon('calendar') ?>Agendar</button>
            </form>
        </div>
    </div>
    <?php
}

/** Modal de reagendar / concluir compromisso (agenda e ficha). */
function render_modal_compromisso(): void
{
    ?>
    <div class="modal-overlay" id="modal-reagendar" hidden>
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-reagendar-titulo">
            <button type="button" class="overlay-close" data-close-modal="modal-reagendar" aria-label="Fechar">&times;</button>
            <h2 id="modal-reagendar-titulo">Reagendar</h2>
            <p class="muted" data-reagendar-titulo></p>
            <form data-api-form action="/api/agenda_status.php" data-recarregar>
                <input type="hidden" name="acao" value="reagendar">
                <input type="hidden" name="id" value="">
                <div class="field">
                    <label for="reagendar-inicio">Nova data e horário</label>
                    <input type="datetime-local" id="reagendar-inicio" name="novo_inicio" required>
                </div>
                <div class="field">
                    <label for="reagendar-obs">Motivo (opcional)</label>
                    <input type="text" id="reagendar-obs" name="observacao" maxlength="500">
                </div>
                <button type="submit" class="btn btn-primary"><?= icon('calendar') ?>Reagendar</button>
            </form>
        </div>
    </div>
    <?php
}

/** Linha de compromisso com as ações rápidas. */
function render_compromisso(array $c, bool $mostrarResponsavel, bool $mostrarLead = true): void
{
    $vencido = $c['status'] === 'agendado' && strtotime($c['inicio']) < time();
    ?>
    <div class="agenda-item<?= $vencido ? ' is-late' : '' ?><?= $c['status'] !== 'agendado' ? ' is-done' : '' ?>">
        <div class="agenda-when">
            <strong><?= date('H\hi', strtotime($c['inicio'])) ?></strong>
            <span><?= date('d/m', strtotime($c['inicio'])) ?></span>
        </div>
        <div class="agenda-icon agenda-icon-<?= e($c['tipo']) ?>"><?= icon(agenda_tipo_icon($c['tipo'])) ?></div>
        <div class="agenda-info">
            <strong><?= e($c['titulo']) ?></strong>
            <span>
                <?= e(agenda_tipos()[$c['tipo']]) ?>
                <?php if ($mostrarLead && $c['lead_id']): ?> · <a href="/pages/lead.php?id=<?= (int) $c['lead_id'] ?>"><?= e($c['lead_nome']) ?></a><?php endif; ?>
                <?php if ($mostrarResponsavel): ?> · <?= e($c['usuario_nome']) ?><?php endif; ?>
            </span>
            <?php if ($c['observacao']): ?><small><?= e($c['observacao']) ?></small><?php endif; ?>
        </div>
        <?php if ($c['status'] === 'agendado'): ?>
            <div class="agenda-actions">
                <?php if ($vencido): ?><span class="pill pill-red">Atrasado</span><?php endif; ?>
                <?php if ($c['tipo'] === 'visita' && array_key_exists('confirmada_em', $c)): ?>
                    <?php if ($c['confirmada_em']): ?><span class="pill pill-green" title="Confirmada em <?= date('d/m H\hi', strtotime($c['confirmada_em'])) ?>">Confirmada</span>
                    <?php else: ?><button type="button" class="btn btn-sm btn-outline" data-agenda-acao="confirmar" data-id="<?= (int) $c['id'] ?>">Cliente confirmou</button><?php endif; ?>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-success" data-agenda-acao="realizado" data-id="<?= (int) $c['id'] ?>" data-tipo="<?= e($c['tipo']) ?>" data-com-lead="<?= $c['lead_id'] ? '1' : '0' ?>"><?= icon('check') ?>Realizado</button>
                <?php if ($c['tipo'] !== 'tarefa'): ?>
                    <button type="button" class="btn btn-sm btn-outline" data-agenda-acao="cliente_ausente" data-id="<?= (int) $c['id'] ?>">Cliente ausente</button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline" data-reagendar="<?= (int) $c['id'] ?>" data-titulo="<?= e($c['titulo']) ?>" data-inicio="<?= date('Y-m-d\TH:i', strtotime($c['inicio'])) ?>">Reagendar</button>
                <button type="button" class="link-btn danger" data-agenda-acao="cancelar" data-id="<?= (int) $c['id'] ?>">Cancelar</button>
            </div>
        <?php else: ?>
            <span class="pill <?= agenda_status_pill($c['status']) ?>"><?= e(agenda_status()[$c['status']]) ?></span>
        <?php endif; ?>
    </div>
    <?php
}
