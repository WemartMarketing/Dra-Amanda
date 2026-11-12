<?php
/**
 * Cartão de pendência (Início e página Pendências). Botões grandes, sem
 * pop-up: a pergunta "o que o cliente respondeu?" abre no próprio cartão.
 */
require_once __DIR__ . '/pendencias.php';
require_once __DIR__ . '/icons.php';

function render_pendencia(array $p, array $etapas, bool $podeWhatsapp): void
{
    $id = (int) $p['id'];
    $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
    ?>
    <div class="pend" id="pend-<?= $id ?>">
        <div class="pend-topo">
            <span class="pend-icone pend-<?= $h($p['tipo']) ?>"><?= icon($p['tipo'] === 'responder_whatsapp' ? 'whatsapp' : ($p['tipo'] === 'confirmar_resposta' ? 'phone' : 'check')) ?></span>
            <div class="pend-texto">
                <strong><?= $h($p['titulo']) ?></strong>
                <span><a href="/pages/lead.php?id=<?= (int) $p['lead_id'] ?>"><?= $h($p['lead_nome']) ?></a><?= $p['empreendimento'] ? ' · ' . $h($p['empreendimento']) : '' ?> · <?= $h(mb_strtolower(tempo_relativo($p['criado_em']))) ?></span>
            </div>
        </div>
        <?php if ($p['tipo'] === 'confirmar_resposta'): ?>
            <div class="pend-acoes">
                <button type="button" class="btn btn-success" data-pend-abrir="sim-<?= $id ?>"><?= icon('check') ?>Sim, respondeu</button>
                <button type="button" class="btn btn-outline" data-api-acao="/api/pendencias.php" data-id="<?= $id ?>" data-resposta="nao_respondeu" data-confirmar="Registrar que o cliente NÃO respondeu? (Nenhuma mensagem será enviada por isso.)">Não respondeu</button>
                <button type="button" class="btn btn-outline" data-pend-abrir="tent-<?= $id ?>">Registrar tentativa</button>
                <button type="button" class="link-btn" data-api-acao="/api/pendencias.php" data-id="<?= $id ?>" data-resposta="adiar" data-horas="2">Lembrar mais tarde</button>
            </div>
            <form class="pend-form" id="sim-<?= $id ?>" data-api-form action="/api/pendencias.php" hidden>
                <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="resposta" value="respondeu">
                <div class="field"><label for="pobs-<?= $id ?>">O que o cliente respondeu?</label><textarea id="pobs-<?= $id ?>" name="observacao" required maxlength="400" placeholder="Ex.: atendeu, quer visitar o decorado no sábado"></textarea></div>
                <div class="field"><label for="petapa-<?= $id ?>">Atualizar a etapa (opcional)</label>
                    <select id="petapa-<?= $id ?>" name="etapa_id"><option value="">Manter a etapa atual</option>
                        <?php foreach ($etapas as $et): ?><option value="<?= (int) $et['id'] ?>" <?= (int) $et['id'] === (int) $p['etapa_id'] ? 'disabled' : '' ?>><?= $h($et['nome']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-alert" data-form-alerta hidden></div>
                <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar</button>
            </form>
            <form class="pend-form" id="tent-<?= $id ?>" data-api-form action="/api/pendencias.php" hidden>
                <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="resposta" value="tentativa">
                <div class="field"><label for="ptobs-<?= $id ?>">Como foi a tentativa? (opcional)</label><textarea id="ptobs-<?= $id ?>" name="observacao" maxlength="400" placeholder="Ex.: liguei às 10h, caixa postal"></textarea></div>
                <div class="form-alert" data-form-alerta hidden></div>
                <button type="submit" class="btn btn-primary"><?= icon('check') ?>Registrar tentativa</button>
            </form>
        <?php elseif ($p['tipo'] === 'responder_whatsapp'): ?>
            <div class="pend-acoes">
                <?php if ($podeWhatsapp): ?><a class="btn btn-success" href="/pages/conversas.php?lead=<?= (int) $p['lead_id'] ?>"><?= icon('whatsapp') ?>Responder agora</a><?php endif; ?>
                <button type="button" class="btn btn-outline" data-api-acao="/api/pendencias.php" data-id="<?= $id ?>" data-resposta="resolvida">Já respondi por outro canal</button>
            </div>
        <?php else: ?>
            <div class="pend-acoes">
                <button type="button" class="btn btn-primary" data-api-acao="/api/pendencias.php" data-id="<?= $id ?>" data-resposta="resolvida"><?= icon('check') ?>Concluir</button>
                <button type="button" class="link-btn" data-api-acao="/api/pendencias.php" data-id="<?= $id ?>" data-resposta="adiar" data-horas="2">Lembrar mais tarde</button>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
