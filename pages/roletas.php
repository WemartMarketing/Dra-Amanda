<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';

$usuario = auth_require();
$empresaId = (int) $usuario['empresa_ativa_id'];
$podeGerenciar = usuario_tem_permissao($usuario, 'gerenciar_roletas');

$stmt = db()->prepare('
    SELECT roletas.*,
        plantoes.nome AS plantao_nome, plantoes.endereco AS plantao_endereco, plantoes.bairro AS plantao_bairro,
        plantoes.cidade AS plantao_cidade, plantoes.estado AS plantao_estado, plantoes.raio_metros AS plantao_raio,
        plantoes.latitude AS plantao_latitude, plantoes.ativo AS plantao_ativo,
        (SELECT COUNT(*) FROM roleta_empreendimentos WHERE roleta_empreendimentos.roleta_id = roletas.id) AS total_empreendimentos,
        (SELECT COUNT(*) FROM roleta_corretores WHERE roleta_corretores.roleta_id = roletas.id) AS total_corretores
    FROM roletas
    LEFT JOIN plantoes ON plantoes.id = roletas.plantao_id AND plantoes.empresa_id = roletas.empresa_id
    WHERE roletas.empresa_id = :empresa_id ORDER BY roletas.nome
');
$stmt->execute(['empresa_id' => $empresaId]);
$roletas = $stmt->fetchAll();

$plantoesOpcoes = [];
if ($podeGerenciar) {
    $stmt = db()->prepare('SELECT id, nome, ativo FROM plantoes WHERE empresa_id = :empresa_id ORDER BY ativo DESC, nome');
    $stmt->execute(['empresa_id' => $empresaId]);
    $plantoesOpcoes = $stmt->fetchAll();
}

$selecionadaId = (int) ($_GET['id'] ?? 0);
if (!$selecionadaId || !in_array($selecionadaId, array_column($roletas, 'id'), true)) {
    $selecionadaId = $roletas[0]['id'] ?? 0;
}

$roleta = null;
$corretoresHabilitados = [];
$empreendimentosVinculados = [];
$corretoresDisponiveis = [];
$empreendimentosDisponiveis = [];

if ($selecionadaId) {
    foreach ($roletas as $r) {
        if ((int) $r['id'] === $selecionadaId) {
            $roleta = $r;
            break;
        }
    }

    $stmt = db()->prepare('
        SELECT rc.usuario_id, usuarios.nome,
            (SELECT rp.dentro_do_raio FROM roleta_presencas rp WHERE rp.roleta_id = rc.roleta_id AND rp.usuario_id = rc.usuario_id ORDER BY rp.confirmado_em DESC LIMIT 1) AS dentro_do_raio,
            (SELECT rp.confirmado_em FROM roleta_presencas rp WHERE rp.roleta_id = rc.roleta_id AND rp.usuario_id = rc.usuario_id ORDER BY rp.confirmado_em DESC LIMIT 1) AS confirmado_em
        FROM roleta_corretores rc
        INNER JOIN usuarios ON usuarios.id = rc.usuario_id
        WHERE rc.roleta_id = :roleta_id
        ORDER BY rc.ordem
    ');
    $stmt->execute(['roleta_id' => $selecionadaId]);
    $corretoresHabilitados = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT empreendimentos.id, empreendimentos.nome FROM roleta_empreendimentos INNER JOIN empreendimentos ON empreendimentos.id = roleta_empreendimentos.empreendimento_id WHERE roleta_empreendimentos.roleta_id = :roleta_id ORDER BY empreendimentos.nome');
    $stmt->execute(['roleta_id' => $selecionadaId]);
    $empreendimentosVinculados = $stmt->fetchAll();

    if ($podeGerenciar) {
        $stmt = db()->prepare("SELECT usuarios.id, usuarios.nome FROM usuarios INNER JOIN funcoes ON funcoes.id = usuarios.funcao_id WHERE usuarios.empresa_id = :empresa_id AND funcoes.escopo = 'propria_carteira' AND usuarios.ativo = 1 AND usuarios.id NOT IN (SELECT usuario_id FROM roleta_corretores WHERE roleta_id = :roleta_id) ORDER BY usuarios.nome");
        $stmt->execute(['empresa_id' => $empresaId, 'roleta_id' => $selecionadaId]);
        $corretoresDisponiveis = $stmt->fetchAll();

        $stmt = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :empresa_id AND ativo = 1 AND id NOT IN (SELECT empreendimento_id FROM roleta_empreendimentos WHERE roleta_id = :roleta_id) ORDER BY nome');
        $stmt->execute(['empresa_id' => $empresaId, 'roleta_id' => $selecionadaId]);
        $empreendimentosDisponiveis = $stmt->fetchAll();
    }
}

/** Disponível = presença recente (até 6h) e dentro do raio; o resto conta como bloqueado. */
function roletaPresencaDisponivel(?string $confirmadoEm, $dentroDoRaio): bool
{
    if (!$confirmadoEm || !$dentroDoRaio) {
        return false;
    }
    return strtotime($confirmadoEm) > strtotime('-6 hours');
}

$pageTitle = 'Roletas — ' . APP_NAME;
$crumb = 'Roletas';
$activeNav = 'roletas';
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Operação de vendas</div><h1>Roletas de distribuição</h1><p>Configure quem pode receber leads e em qual plantão.</p></div>
    <?php if ($podeGerenciar): ?>
        <div class="actions"><button type="button" class="btn btn-primary" data-open-modal="modal-nova-roleta"><?= icon('plus') ?>Nova roleta</button></div>
    <?php endif; ?>
</div>

<div class="roleta-layout">
    <div class="card">
        <div class="card-head">
            <div><h2>Roletas cadastradas</h2><p><?= $podeGerenciar ? 'Selecione uma roleta para ver e editar os detalhes.' : 'Dados desta empresa — edição disponível só para cargos de gestão.' ?></p></div>
            <span class="pill pill-muted"><?= count($roletas) ?> roleta(s)</span>
        </div>
        <?php foreach ($roletas as $r): ?>
            <a href="/pages/roletas.php?id=<?= $r['id'] ?>" class="roleta-row <?= (int) $r['id'] === $selecionadaId ? 'is-active' : '' ?>">
                <div class="plantao-icon"><?= icon('pin') ?></div>
                <div class="who-info">
                    <strong><?= htmlspecialchars($r['nome']) ?></strong>
                    <span><?= $r['plantao_nome'] && $r['plantao_nome'] !== $r['nome'] ? 'Plantão: ' . htmlspecialchars($r['plantao_nome']) . ' · ' : '' ?><?= !$r['plantao_nome'] ? 'Sem plantão · ' : '' ?><?= (int) $r['total_empreendimentos'] ?> empreendimento(s) · <?= (int) $r['total_corretores'] ?> corretor(es)</span>
                </div>
                <span class="pill <?= $r['ativa'] ? 'pill-green' : 'pill-muted' ?>"><?= $r['ativa'] ? 'Ativa' : 'Pausada' ?></span>
            </a>
        <?php endforeach; ?>
        <?php if (!$roletas): ?><p class="empty-note">Nenhuma roleta cadastrada ainda.</p><?php endif; ?>
    </div>

    <?php if ($roleta): ?>
        <div class="card">
            <div class="card-head">
                <div><h2><?= htmlspecialchars($roleta['nome']) ?></h2><p>Regras de presença e rodízio</p></div>
                <?php if ($podeGerenciar): ?><button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-editar-roleta"><?= icon('pin') ?>Editar regras</button><?php endif; ?>
            </div>

            <div class="radius-vis">
                <div class="radius-circle"></div>
            </div>

            <?php
            $temPlantao = !empty($roleta['plantao_nome']);
            $temCoordenadas = $temPlantao && $roleta['plantao_latitude'] !== null;
            $enderecoPlantao = implode(' · ', array_filter([$roleta['plantao_endereco'] ?? null, $roleta['plantao_bairro'] ?? null, trim(($roleta['plantao_cidade'] ?? '') . ($roleta['plantao_estado'] ? '/' . $roleta['plantao_estado'] : ''), '/')]));
            ?>
            <div class="roleta-detail-row"><span class="label">Plantão</span>
                <?php if ($temPlantao): ?>
                    <span style="text-align:right;"><strong><?= htmlspecialchars($roleta['plantao_nome']) ?></strong><?php if (!$roleta['plantao_ativo']): ?> <span class="pill pill-muted">Inativo</span><?php endif; ?>
                    <?php if ($enderecoPlantao): ?><br><small class="muted"><?= htmlspecialchars($enderecoPlantao) ?></small><?php endif; ?></span>
                <?php else: ?>
                    <span class="pill pill-amber">Sem plantão vinculado</span>
                <?php endif; ?>
            </div>
            <div class="roleta-detail-row"><span class="label">Presença por geolocalização</span><span class="pill <?= $temCoordenadas ? 'pill-green' : 'pill-muted' ?>"><?= $temCoordenadas ? 'Ativada' : ($temPlantao ? 'Plantão sem coordenadas' : 'Desativada') ?></span></div>
            <div class="roleta-detail-row"><span class="label">Raio de presença</span><strong><?= $temPlantao ? (int) $roleta['plantao_raio'] . ' metros' : '—' ?></strong></div>
            <div class="roleta-detail-row"><span class="label">Horário permitido</span><strong><?= $roleta['horario_inicio'] ? substr($roleta['horario_inicio'], 0, 5) . ' às ' . substr($roleta['horario_fim'], 0, 5) : '—' ?></strong></div>
            <div class="roleta-detail-row"><span class="label">Empreendimentos</span>
                <span>
                <?php foreach ($empreendimentosVinculados as $e): ?>
                    <span class="pill pill-muted" style="margin-left:4px;"><?= htmlspecialchars($e['nome']) ?><?php if ($podeGerenciar): ?> <button type="button" class="remove-link" data-remover-empreendimento="<?= $e['id'] ?>" title="Remover">&times;</button><?php endif; ?></span>
                <?php endforeach; ?>
                <?php if (!$empreendimentosVinculados): ?>—<?php endif; ?>
                </span>
            </div>
            <?php if ($podeGerenciar && $empreendimentosDisponiveis): ?>
                <form class="add-row" data-add-empreendimento>
                    <select name="empreendimento_id" required>
                        <option value="">Adicionar empreendimento...</option>
                        <?php foreach ($empreendimentosDisponiveis as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nome']) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm">Adicionar</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card" style="grid-column: 1 / -1;">
            <div class="card-head"><div><h2>Corretores habilitados na roleta</h2><p>Quem pode receber o próximo lead agora.</p></div></div>
            <?php foreach ($corretoresHabilitados as $c):
                $disponivel = roletaPresencaDisponivel($c['confirmado_em'], $c['dentro_do_raio']);
            ?>
                <div class="corretor-row">
                    <div class="avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($c['nome'], 0, 1) . mb_substr(strrchr($c['nome'], ' ') ?: '', 1, 1))) ?></div>
                    <div class="who-info">
                        <strong><?= htmlspecialchars($c['nome']) ?></strong>
                        <span><?= $c['confirmado_em'] ? ($disponivel ? 'Online — no plantão' : 'Última presença há mais tempo') : 'Nunca confirmou presença' ?></span>
                    </div>
                    <span class="pill <?= $disponivel ? 'pill-green' : 'pill-red' ?>"><?= $disponivel ? 'Disponível' : 'Bloqueado' ?></span>
                    <?php if ($podeGerenciar): ?><button type="button" class="remove-link" data-remover-corretor="<?= $c['usuario_id'] ?>" title="Remover da roleta">Remover</button><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$corretoresHabilitados): ?><p class="empty-note">Nenhum corretor habilitado nesta roleta ainda.</p><?php endif; ?>
            <?php if ($podeGerenciar && $corretoresDisponiveis): ?>
                <form class="add-row" data-add-corretor>
                    <select name="usuario_id" required>
                        <option value="">Habilitar corretor...</option>
                        <?php foreach ($corretoresDisponiveis as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm">Adicionar</button>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card stub-card">
            <div class="stub-icon"><?= icon('pin') ?></div>
            <h2>Nenhuma roleta selecionada</h2>
            <p><?= $podeGerenciar ? 'Crie a primeira roleta para começar a distribuir leads.' : 'Nenhuma roleta foi cadastrada ainda nesta empresa.' ?></p>
        </div>
    <?php endif; ?>
</div>

<?php if ($podeGerenciar): ?>
<div class="modal-overlay" id="modal-nova-roleta" hidden>
    <div class="modal-box">
        <button type="button" class="overlay-close" data-close-modal="modal-nova-roleta">&times;</button>
        <h2>Nova roleta</h2>
        <p style="color:#8893a4;font-size:13.5px;margin:0 0 16px;">Depois de criar, vincule empreendimentos e corretores na tela.</p>
        <form data-form-roleta>
            <?= csrf_field() ?>
            <div class="field"><label>Nome</label><input type="text" name="nome" required placeholder="Ex.: Casa HM — Zona Leste"></div>
            <div class="field"><label>Plantão</label>
                <select name="plantao_id">
                    <option value="">Sem plantão (sem geolocalização)</option>
                    <?php foreach ($plantoesOpcoes as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nome']) ?><?= $p['ativo'] ? '' : ' (inativo)' ?></option><?php endforeach; ?>
                </select>
                <small class="field-hint">Endereço e raio de presença vêm do plantão — cadastre em <a href="/pages/plantoes.php">Plantões</a>.</small>
            </div>
            <div class="field"><label>Horário permitido</label>
                <div style="display:flex;gap:10px;">
                    <input type="time" name="horario_inicio" style="flex:1;">
                    <input type="time" name="horario_fim" style="flex:1;">
                </div>
            </div>
            <label class="checkbox-row"><input type="checkbox" name="ativa" value="1" checked> Roleta ativa</label>
            <button type="submit" class="btn btn-primary">Criar roleta</button>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-editar-roleta" hidden>
    <div class="modal-box">
        <button type="button" class="overlay-close" data-close-modal="modal-editar-roleta">&times;</button>
        <h2>Editar regras</h2>
        <form data-form-roleta>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $roleta['id'] ?? '' ?>">
            <div class="field"><label>Nome</label><input type="text" name="nome" required value="<?= htmlspecialchars($roleta['nome'] ?? '') ?>"></div>
            <div class="field"><label>Plantão</label>
                <select name="plantao_id">
                    <option value="">Sem plantão (sem geolocalização)</option>
                    <?php foreach ($plantoesOpcoes as $p): ?><option value="<?= $p['id'] ?>" <?= (int) ($roleta['plantao_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nome']) ?><?= $p['ativo'] ? '' : ' (inativo)' ?></option><?php endforeach; ?>
                </select>
                <small class="field-hint">Endereço e raio de presença vêm do plantão — cadastre em <a href="/pages/plantoes.php">Plantões</a>.</small>
            </div>
            <div class="field"><label>Horário permitido</label>
                <div style="display:flex;gap:10px;">
                    <input type="time" name="horario_inicio" style="flex:1;" value="<?= substr((string) ($roleta['horario_inicio'] ?? ''), 0, 5) ?>">
                    <input type="time" name="horario_fim" style="flex:1;" value="<?= substr((string) ($roleta['horario_fim'] ?? ''), 0, 5) ?>">
                </div>
            </div>
            <label class="checkbox-row"><input type="checkbox" name="ativa" value="1" <?= !empty($roleta['ativa']) ? 'checked' : '' ?>> Roleta ativa</label>
            <button type="submit" class="btn btn-primary">Salvar alterações</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
