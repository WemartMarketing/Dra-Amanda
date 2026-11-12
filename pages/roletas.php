<?php
/**
 * Roletas — gestão (Etapa 4). Regras de presença e rodízio, empreendimentos
 * com prioridade, corretores com status ao vivo, fila de espera e histórico
 * de distribuição. Toda regra mora em includes/roleta.php; esta página só
 * mostra e chama as APIs.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/roleta.php';
require_once __DIR__ . '/../includes/lead_componentes.php';

$usuario = auth_require();
$empresaId = (int) $usuario['empresa_ativa_id'];
$podeGerenciar = usuario_tem_permissao($usuario, 'gerenciar_roletas');
$podeDistribuir = $podeGerenciar || usuario_tem_permissao($usuario, 'redistribuir_leads');

// Corretor não gerencia roletas: a tela dele é "Minha roleta".
if (!$podeGerenciar && $usuario['escopo'] === 'propria_carteira' && usuario_tem_permissao($usuario, 'participar_roleta')) {
    header('Location: /pages/minha_roleta.php');
    exit;
}

// Expiração preguiçosa: quem venceu a presença, saiu do horário etc. sai agora.
roleta_fechar_expiradas($empresaId);

$stmt = db()->prepare('
    SELECT roletas.*, plantoes.nome AS plantao_nome,
        (SELECT COUNT(*) FROM roleta_empreendimentos WHERE roleta_empreendimentos.roleta_id = roletas.id) AS total_empreendimentos,
        (SELECT COUNT(*) FROM roleta_corretores WHERE roleta_corretores.roleta_id = roletas.id) AS total_corretores,
        (SELECT COUNT(*) FROM roleta_sessoes s WHERE s.roleta_id = roletas.id AND s.saiu_em IS NULL) AS total_na_roleta
    FROM roletas
    LEFT JOIN plantoes ON plantoes.id = roletas.plantao_id AND plantoes.empresa_id = roletas.empresa_id
    WHERE roletas.empresa_id = :empresa_id ORDER BY roletas.ativa DESC, roletas.nome');
$stmt->execute(['empresa_id' => $empresaId]);
$roletas = $stmt->fetchAll();

$plantoesOpcoes = [];
if ($podeGerenciar) {
    $stmt = db()->prepare('SELECT id, nome, ativo FROM plantoes WHERE empresa_id = :empresa_id ORDER BY ativo DESC, nome');
    $stmt->execute(['empresa_id' => $empresaId]);
    $plantoesOpcoes = $stmt->fetchAll();
}

$selecionadaId = (int) ($_GET['id'] ?? 0);
if (!$selecionadaId || !in_array($selecionadaId, array_map('intval', array_column($roletas, 'id')), true)) {
    $selecionadaId = (int) ($roletas[0]['id'] ?? 0);
}
$roleta = $selecionadaId ? roleta_carregar($empresaId, $selecionadaId) : null;

$corretores = $empreendimentosVinculados = $corretoresDisponiveis = $empreendimentosDisponiveis = $fila = $historico = [];
$noHorario = [false, ''];
$historicoUsuarios = [];
$periodos = ['hoje' => 'Hoje', '7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias'];
$periodo = array_key_exists($_GET['periodo'] ?? '', $periodos) ? $_GET['periodo'] : '7d';
$filtroCorretor = (int) ($_GET['corretor'] ?? 0);

$proximosDias = $excecoes = $grade = [];
if ($roleta) {
    $noHorario = roleta_no_horario($roleta);
    $proximosDias = roleta_proximos_dias($roleta, 7);
    $excecoes = roleta_excecoes_futuras($empresaId, $selecionadaId);
    $grade = roleta_grade_para_tela($roleta);
    $corretores = roleta_status_corretores($roleta);

    $stmt = db()->prepare('SELECT e.id, e.nome, re.prioridade FROM roleta_empreendimentos re INNER JOIN empreendimentos e ON e.id = re.empreendimento_id WHERE re.roleta_id = :r ORDER BY re.prioridade, e.nome');
    $stmt->execute(['r' => $selecionadaId]);
    $empreendimentosVinculados = $stmt->fetchAll();

    if ($podeGerenciar) {
        $stmt = db()->prepare("SELECT DISTINCT u.id, u.nome FROM usuarios u
            INNER JOIN funcao_permissoes fp ON fp.funcao_id = u.funcao_id AND fp.permissao_chave = 'participar_roleta'
            WHERE u.empresa_id = :empresa_id AND u.ativo = 1
              AND u.id NOT IN (SELECT usuario_id FROM roleta_corretores WHERE roleta_id = :r) ORDER BY u.nome");
        $stmt->execute(['empresa_id' => $empresaId, 'r' => $selecionadaId]);
        $corretoresDisponiveis = $stmt->fetchAll();

        $stmt = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :empresa_id AND ativo = 1 AND id NOT IN (SELECT empreendimento_id FROM roleta_empreendimentos WHERE roleta_id = :r) ORDER BY nome');
        $stmt->execute(['empresa_id' => $empresaId, 'r' => $selecionadaId]);
        $empreendimentosDisponiveis = $stmt->fetchAll();
    }

    if ($podeDistribuir) {
        $fila = roleta_fila_listar($usuario, $selecionadaId);
    }

    // Histórico de distribuição — o nome do lead só aparece para quem pode vê-lo.
    $desde = ['hoje' => 'CURDATE()', '7d' => 'CURDATE() - INTERVAL 6 DAY', '30d' => 'CURDATE() - INTERVAL 29 DAY'][$periodo];
    $sql = "SELECT d.id, d.lead_id, d.usuario_id, d.origem, d.distribuido_em, u.nome AS corretor_nome, ex.nome AS executor_nome
            FROM roleta_distribuicoes d
            INNER JOIN usuarios u ON u.id = d.usuario_id
            LEFT JOIN usuarios ex ON ex.id = d.executado_por
            WHERE d.empresa_id = :empresa_id AND d.roleta_id = :r AND d.distribuido_em >= {$desde}";
    $params = ['empresa_id' => $empresaId, 'r' => $selecionadaId];
    if ($filtroCorretor) {
        $sql .= ' AND d.usuario_id = :u';
        $params['u'] = $filtroCorretor;
    }
    $stmt = db()->prepare($sql . ' ORDER BY d.distribuido_em DESC, d.id DESC LIMIT 100');
    $stmt->execute($params);
    $historico = $stmt->fetchAll();

    $visiveis = [];
    if ($historico && empty($usuario['eh_superadmin'])) {
        $ids = array_map('intval', array_column($historico, 'lead_id'));
        $scope = rbac_leads_scope($usuario);
        $marcadores = [];
        foreach ($ids as $i => $leadId) {
            $marcadores[] = ':hl' . $i;
            $scope['params']['hl' . $i] = $leadId;
        }
        $stmt = db()->prepare('SELECT leads.id, leads.nome FROM leads WHERE leads.id IN (' . implode(',', $marcadores) . ') AND ' . $scope['sql']);
        $stmt->execute($scope['params']);
        $visiveis = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    foreach ($historico as &$h) {
        $h['lead_nome'] = $visiveis[(int) $h['lead_id']] ?? null;
    }
    unset($h);
    $historicoUsuarios = array_map(fn ($c) => ['id' => (int) $c['usuario_id'], 'nome' => $c['nome']], $corretores);
}

/** Texto do status ao vivo de um corretor na roleta. */
function roleta_status_texto(array $c, array $roleta): array
{
    if ($c['pausado']) {
        return ['Pausado pelo gestor', 'pill-amber', 'Pausado'];
    }
    if ($c['sessao_valida']) {
        $min = max(0, (int) floor((time() - strtotime($c['ultima_verificacao_em'])) / 60));
        $texto = 'Na roleta desde ' . date('H:i', strtotime($c['entrou_em'])) . ' · última verificação ' . ($min === 0 ? 'agora' : 'há ' . $min . ' min');
        if ($c['ultima_distancia'] !== null && $roleta['exigir_presenca']) {
            $texto .= ' · a ' . roleta_formatar_distancia((float) $c['ultima_distancia']);
        }
        return [$texto, 'pill-green', 'Na roleta'];
    }
    if ($c['ultima_saida']) {
        $dia = date('Y-m-d', strtotime($c['ultima_saida'])) === date('Y-m-d') ? '' : ' em ' . date('d/m', strtotime($c['ultima_saida']));
        return ['Fora — ' . roleta_motivo_saida_rotulo($c['ultimo_motivo']) . ' às ' . date('H:i', strtotime($c['ultima_saida'])) . $dia, 'pill-muted', 'Fora'];
    }
    return ['Fora — ainda não entrou na roleta', 'pill-muted', 'Fora'];
}

/** Formulário de regras (criar e editar usam o mesmo). */
function roleta_form_regras(?array $r, array $plantoes): void
{
    $dias = roleta_dias_lista($r['dias_semana'] ?? '1,2,3,4,5,6');
    ?>
    <form data-form-roleta>
        <?= csrf_field() ?>
        <?php if ($r): ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><?php endif; ?>
        <div class="field"><label>Nome</label><input type="text" name="nome" required maxlength="190" value="<?= e($r['nome'] ?? '') ?>" placeholder="Ex.: Casa HM — Zona Leste"></div>
        <div class="field"><label>Plantão</label>
            <select name="plantao_id">
                <option value="">Sem plantão (sem verificação de presença)</option>
                <?php foreach ($plantoes as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($r['plantao_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nome']) ?><?= $p['ativo'] ? '' : ' (inativo)' ?></option><?php endforeach; ?>
            </select>
            <small class="field-hint">Endereço, coordenadas e raio vêm do plantão — cadastre em <a href="/pages/plantoes.php">Plantões</a>.</small>
        </div>
        <?php if ($r && roleta_horarios_personalizados((int) $r['id'])): ?>
            <?php foreach ($dias as $n): ?><input type="hidden" name="dias_semana[]" value="<?= $n ?>"><?php endforeach; ?>
            <input type="hidden" name="horario_inicio" value="<?= substr((string) ($r['horario_inicio'] ?? ''), 0, 5) ?>">
            <input type="hidden" name="horario_fim" value="<?= substr((string) ($r['horario_fim'] ?? ''), 0, 5) ?>">
            <div class="rule-box"><?= icon('clock') ?><span>Esta roleta tem <b>horário diferente por dia</b> (ou pausa). Ajuste em <b>Horários e feriados</b>, na página da roleta.</span></div>
        <?php else: ?>
        <fieldset class="field dias-semana"><legend>Dias de funcionamento</legend>
            <?php foreach (roleta_dias_rotulos() as $n => $rotulo): ?>
                <label class="dia-chip"><input type="checkbox" name="dias_semana[]" value="<?= $n ?>" <?= in_array($n, $dias, true) ? 'checked' : '' ?>><span><?= e($rotulo) ?></span></label>
            <?php endforeach; ?>
        </fieldset>
        <div class="field"><label>Horário permitido <small class="muted">(vazio = o dia todo)</small></label>
            <div class="inline-pair">
                <input type="time" name="horario_inicio" value="<?= substr((string) ($r['horario_inicio'] ?? ''), 0, 5) ?>" aria-label="Início">
                <input type="time" name="horario_fim" value="<?= substr((string) ($r['horario_fim'] ?? ''), 0, 5) ?>" aria-label="Fim">
            </div>
        </div>
        <?php if ($r): ?><small class="field-hint">Horário diferente em cada dia, pausa de almoço ou feriados: use <b>Horários e feriados</b>.</small><?php endif; ?>
        <?php endif; ?>
        <label class="checkbox-row"><input type="checkbox" name="exigir_presenca" value="1" <?= !$r || !empty($r['exigir_presenca']) ? 'checked' : '' ?>> Exigir presença no plantão (GPS) para entrar e receber leads</label>
        <div class="form-grid">
            <div class="field"><label>Precisão máxima do GPS (m)</label><input type="number" name="precisao_maxima_metros" min="10" max="2000" value="<?= (int) ($r['precisao_maxima_metros'] ?? 150) ?>"></div>
            <div class="field"><label>Limite de leads por corretor/dia</label><input type="number" name="max_leads_dia_corretor" min="1" max="500" value="<?= e((string) ($r['max_leads_dia_corretor'] ?? '')) ?>" placeholder="Sem limite"></div>
            <div class="field"><label>Presença vale por (min)</label><input type="number" name="validade_presenca_min" min="5" max="240" value="<?= (int) ($r['validade_presenca_min'] ?? 20) ?>"></div>
            <div class="field"><label>Reverificar a cada (min)</label><input type="number" name="intervalo_verificacao_min" min="1" max="60" value="<?= (int) ($r['intervalo_verificacao_min'] ?? 5) ?>"></div>
        </div>
        <small class="field-hint">Distribuição: rodízio — recebe quem está há mais tempo sem receber lead, entre os corretores na roleta.</small>
        <label class="checkbox-row"><input type="checkbox" name="ativa" value="1" <?= !$r || !empty($r['ativa']) ? 'checked' : '' ?>> Roleta ativa <small class="muted">(pausar tira todos da roleta)</small></label>
        <div class="form-alert" data-form-alerta hidden></div>
        <button type="submit" class="btn btn-primary"><?= $r ? 'Salvar regras' : 'Criar roleta' ?></button>
    </form>
    <?php
}

$pageTitle = 'Roletas — ' . APP_NAME;
$crumb = 'Roletas';
$activeNav = 'roletas';
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Operação de vendas</div><h1>Roletas de distribuição</h1><p>Quem está no plantão agora, quem recebe o próximo lead e o que ficou esperando.</p></div>
    <?php if ($podeGerenciar): ?>
        <div class="actions"><button type="button" class="btn btn-primary" data-open-modal="modal-nova-roleta"><?= icon('plus') ?>Nova roleta</button></div>
    <?php endif; ?>
</div>

<div class="roleta-layout">
    <div class="card">
        <div class="card-head">
            <div><h2>Roletas cadastradas</h2><p><?= $podeGerenciar ? 'Selecione uma roleta para ver e editar.' : 'Visualização — edição só para cargos de gestão.' ?></p></div>
            <span class="pill pill-muted"><?= count($roletas) ?> roleta(s)</span>
        </div>
        <?php foreach ($roletas as $r): ?>
            <a href="/pages/roletas.php?id=<?= (int) $r['id'] ?>" class="roleta-row <?= (int) $r['id'] === $selecionadaId ? 'is-active' : '' ?>">
                <div class="plantao-icon"><?= icon('pin') ?></div>
                <div class="who-info">
                    <strong><?= e($r['nome']) ?></strong>
                    <span><?= $r['plantao_nome'] ? 'Plantão ' . e($r['plantao_nome']) : 'Sem plantão' ?> · <?= (int) $r['total_empreendimentos'] ?> empreend. · <?= (int) $r['total_corretores'] ?> corretor(es)</span>
                    <?php if ($r['ativa']): ?><span class="roleta-agora"><?= (int) $r['total_na_roleta'] ?> na roleta agora</span><?php endif; ?>
                </div>
                <span class="pill <?= $r['ativa'] ? 'pill-green' : 'pill-muted' ?>"><?= $r['ativa'] ? 'Ativa' : 'Pausada' ?></span>
            </a>
        <?php endforeach; ?>
        <?php if (!$roletas): ?><p class="empty-note">Nenhuma roleta cadastrada ainda.</p><?php endif; ?>
    </div>

    <?php if ($roleta):
        $temPlantao = !empty($roleta['plantao_nome']);
        $temCoordenadas = $temPlantao && $roleta['plantao_latitude'] !== null && $roleta['plantao_longitude'] !== null;
        $enderecoPlantao = implode(' · ', array_filter([$roleta['plantao_endereco'] ?? null, $roleta['plantao_bairro'] ?? null, trim(($roleta['plantao_cidade'] ?? '') . (!empty($roleta['plantao_estado']) ? '/' . $roleta['plantao_estado'] : ''), '/')]));
    ?>
        <div class="card" data-roleta-id="<?= (int) $roleta['id'] ?>">
            <div class="card-head">
                <div><h2><?= e($roleta['nome']) ?></h2><p>Regras de presença e rodízio</p></div>
                <?php if ($podeGerenciar): ?><button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-editar-roleta"><?= icon('pin') ?>Editar regras</button><?php endif; ?>
            </div>
            <?php if ($temCoordenadas): ?>
                <div class="radius-vis"><div class="radius-circle"></div><span class="radius-label">Raio: <?= (int) $roleta['plantao_raio'] ?> m</span></div>
            <?php endif; ?>
            <div class="roleta-status-banner <?= !$roleta['ativa'] ? 'is-off' : ($noHorario[0] ? 'is-on' : 'is-wait') ?>">
                <?= !$roleta['ativa'] ? 'Roleta pausada — ninguém entra e nenhum lead é distribuído.' : ($noHorario[0] ? 'Funcionando agora · ' . e(roleta_horario_texto($roleta)) : 'Fora do horário agora — ' . e($noHorario[1])) ?>
            </div>
            <div class="roleta-detail-row"><span class="label">Plantão</span>
                <?php if ($temPlantao): ?>
                    <span style="text-align:right;"><strong><?= e($roleta['plantao_nome']) ?></strong><?php if (!$roleta['plantao_ativo']): ?> <span class="pill pill-muted">Inativo</span><?php endif; ?>
                    <?php if ($enderecoPlantao): ?><br><small class="muted"><?= e($enderecoPlantao) ?></small><?php endif; ?></span>
                <?php else: ?><span class="pill pill-amber">Sem plantão vinculado</span><?php endif; ?>
            </div>
            <div class="roleta-detail-row"><span class="label">Presença no plantão</span>
                <span class="pill <?= !$roleta['exigir_presenca'] ? 'pill-muted' : ($temCoordenadas ? 'pill-green' : 'pill-red') ?>"><?= !$roleta['exigir_presenca'] ? 'Não exigida' : ($temCoordenadas ? 'Exigida · raio ' . (int) $roleta['plantao_raio'] . ' m' : 'Exigida, mas o plantão não tem coordenadas') ?></span></div>
            <div class="roleta-detail-row"><span class="label">Dias</span><strong><?= e(roleta_dias_texto($roleta['dias_semana'])) ?></strong></div>
            <div class="roleta-detail-row"><span class="label">Horário</span><strong><?= e(roleta_horario_texto($roleta)) ?></strong></div>
            <div class="roleta-detail-row"><span class="label">GPS aceito até</span><strong><?= (int) $roleta['precisao_maxima_metros'] ?> m de precisão</strong></div>
            <div class="roleta-detail-row"><span class="label">Presença</span><strong>vale <?= (int) $roleta['validade_presenca_min'] ?> min · reverifica a cada <?= (int) $roleta['intervalo_verificacao_min'] ?> min</strong></div>
            <div class="roleta-detail-row"><span class="label">Limite por corretor</span><strong><?= $roleta['max_leads_dia_corretor'] ? (int) $roleta['max_leads_dia_corretor'] . ' lead(s) por dia' : 'Sem limite' ?></strong></div>

            <h3 class="sub-title">Empreendimentos atendidos</h3>
            <p class="muted small">Se um empreendimento estiver em mais de uma roleta, a de menor número de prioridade tenta primeiro.</p>
            <?php foreach ($empreendimentosVinculados as $emp): ?>
                <div class="emp-prio-row">
                    <strong><?= e($emp['nome']) ?></strong>
                    <?php if ($podeGerenciar): ?>
                        <form class="prio-form" data-prioridade-empreendimento="<?= (int) $emp['id'] ?>">
                            <label>Prioridade <input type="number" name="prioridade" min="1" max="999" value="<?= (int) $emp['prioridade'] ?>"></label>
                            <button type="submit" class="btn btn-outline btn-sm">Salvar</button>
                        </form>
                        <button type="button" class="remove-link" data-remover-empreendimento="<?= (int) $emp['id'] ?>" title="Remover da roleta">Remover</button>
                    <?php else: ?>
                        <span class="pill pill-muted">Prioridade <?= (int) $emp['prioridade'] ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$empreendimentosVinculados): ?><p class="empty-note">Nenhum empreendimento — leads não chegam por esta roleta.</p><?php endif; ?>
            <?php if ($podeGerenciar && $empreendimentosDisponiveis): ?>
                <form class="add-row" data-add-empreendimento>
                    <select name="empreendimento_id" required aria-label="Empreendimento">
                        <option value="">Adicionar empreendimento...</option>
                        <?php foreach ($empreendimentosDisponiveis as $emp): ?><option value="<?= (int) $emp['id'] ?>"><?= e($emp['nome']) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm">Adicionar</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card" id="horarios">
            <div class="card-head">
                <div><h2>Horários e feriados</h2><p>Quando esta roleta distribui leads. Fora do horário, os leads esperam na fila e são entregues quando ela abrir.</p></div>
            </div>
            <div class="dias-proximos">
                <?php foreach ($proximosDias as $d): ?>
                    <div class="dia-proximo <?= $d['texto'] === 'Fechado' ? 'is-fechado' : '' ?>">
                        <span class="dia-rotulo"><?= e($d['rotulo']) ?></span>
                        <strong><?= e($d['texto']) ?></strong>
                        <?php if ($d['exc']): ?><span class="pill pill-amber"><?= e($d['exc']) ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!modulo_whatsapp_instalado()): ?>
                <div class="rule-box"><?= icon('alert') ?><span>Horário por dia, pausas e feriados ficam disponíveis depois que o administrador rodar as atualizações do banco (migrações 008, 009 e 010).</span></div>
            <?php elseif ($podeGerenciar): ?>
            <details class="ajuste-box" <?= isset($_GET['horarios']) ? 'open' : '' ?>>
                <summary><?= icon('clock') ?>Horário de cada dia da semana</summary>
                <form data-api-form action="/api/roleta_horarios.php" class="grade-form">
                    <input type="hidden" name="acao" value="grade">
                    <input type="hidden" name="roleta_id" value="<?= (int) $roleta['id'] ?>">
                    <div class="grade-cab" aria-hidden="true"><span>Dia</span><span>Abre</span><span>Fecha</span><span>Pausa (opcional)</span></div>
                    <?php foreach (roleta_dias_rotulos() as $n => $rotulo): $g = $grade[$n]; ?>
                        <div class="grade-dia">
                            <label class="grade-dia-nome"><input type="checkbox" name="dias[<?= $n ?>][aberto]" value="1" <?= $g['aberto'] ? 'checked' : '' ?>> <?= e($rotulo) ?></label>
                            <input type="time" name="dias[<?= $n ?>][inicio]" value="<?= e($g['inicio']) ?>" aria-label="<?= e($rotulo) ?>: abre">
                            <input type="time" name="dias[<?= $n ?>][fim]" value="<?= e($g['fim']) ?>" aria-label="<?= e($rotulo) ?>: fecha">
                            <span class="grade-pausa">
                                <input type="time" name="dias[<?= $n ?>][pausa_inicio]" value="<?= e($g['pausa_inicio']) ?>" aria-label="<?= e($rotulo) ?>: pausa começa">
                                <span class="muted">até</span>
                                <input type="time" name="dias[<?= $n ?>][pausa_fim]" value="<?= e($g['pausa_fim']) ?>" aria-label="<?= e($rotulo) ?>: pausa termina">
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <small class="field-hint">Desmarque os dias em que a roleta não funciona. Fechamento antes da abertura (ex.: 18:00 às 02:00) passa da meia-noite.</small>
                    <div class="form-alert" data-form-alerta hidden></div>
                    <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar horários</button>
                </form>
            </details>
            <?php endif; ?>

            <h3 class="sub-title">Feriados e datas especiais</h3>
            <?php foreach ($excecoes as $x): ?>
                <div class="excecao-row">
                    <div>
                        <strong><?= e(date('d/m/Y', strtotime($x['data']))) ?></strong> · <?= $x['tipo'] === 'fechado' ? 'Fechado' : 'Das ' . e(substr($x['inicio'], 0, 5)) . ' às ' . e(substr($x['fim'], 0, 5)) ?>
                        <?php if ($x['descricao']): ?><span class="muted">— <?= e($x['descricao']) ?></span><?php endif; ?>
                        <br><small class="muted"><?= $x['roleta_id'] ? 'Só nesta roleta' : 'Todas as roletas da empresa' ?></small>
                    </div>
                    <?php if ($podeGerenciar): ?>
                        <form data-api-form action="/api/roleta_horarios.php"><input type="hidden" name="acao" value="excecao_remover"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>"><button type="submit" class="remove-link">Remover</button></form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$excecoes): ?><p class="empty-note">Nenhum feriado ou data especial cadastrado.</p><?php endif; ?>
            <?php if ($podeGerenciar && modulo_whatsapp_instalado()): ?>
                <details class="ajuste-box">
                    <summary><?= icon('plus') ?>Adicionar feriado ou data especial</summary>
                    <form data-api-form action="/api/roleta_horarios.php">
                        <input type="hidden" name="acao" value="excecao_salvar">
                        <input type="hidden" name="roleta_id" value="<?= (int) $roleta['id'] ?>">
                        <div class="form-grid">
                            <div class="field"><label>Data</label><input type="date" name="data" required min="<?= date('Y-m-d') ?>"></div>
                            <div class="field"><label>Nesse dia</label>
                                <select name="tipo"><option value="fechado">Fechado o dia todo</option><option value="horario_especial">Horário especial</option></select></div>
                            <div class="field"><label>Abre <small class="muted">(horário especial)</small></label><input type="time" name="inicio"></div>
                            <div class="field"><label>Fecha <small class="muted">(horário especial)</small></label><input type="time" name="fim"></div>
                        </div>
                        <div class="field"><label>Descrição</label><input type="text" name="descricao" maxlength="190" placeholder="Ex.: Natal, Feriado municipal"></div>
                        <fieldset class="field"><legend>Vale para</legend>
                            <label class="checkbox-row"><input type="radio" name="alcance" value="roleta" checked> Só esta roleta</label>
                            <label class="checkbox-row"><input type="radio" name="alcance" value="empresa"> Todas as roletas da empresa</label>
                        </fieldset>
                        <div class="form-alert" data-form-alerta hidden></div>
                        <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar data</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>

        <div class="card" style="grid-column: 1 / -1;">
            <div class="card-head">
                <div><h2>Corretores da roleta — agora</h2><p>Ordem do rodízio: quem está há mais tempo sem receber lead recebe primeiro.</p></div>
                <span class="pill pill-green"><?= count(array_filter($corretores, fn ($c) => $c['sessao_valida'] && !$c['pausado'])) ?> na roleta</span>
            </div>
            <?php foreach ($corretores as $c):
                [$texto, $classe, $rotulo] = roleta_status_texto($c, $roleta);
                $limiteAtingido = $roleta['max_leads_dia_corretor'] && $c['recebidos_hoje'] >= (int) $roleta['max_leads_dia_corretor'];
            ?>
                <div class="corretor-row corretor-roleta">
                    <div class="avatar"><?= e(mb_strtoupper(mb_substr($c['nome'], 0, 1) . mb_substr(strrchr($c['nome'], ' ') ?: '', 1, 1))) ?></div>
                    <div class="who-info">
                        <strong><?= e($c['nome']) ?></strong>
                        <span><?= e($texto) ?></span>
                        <span class="muted">Hoje: <?= (int) $c['recebidos_hoje'] ?> lead(s)<?= $limiteAtingido ? ' · limite do dia atingido' : '' ?> · total <?= (int) $c['total_recebidos'] ?></span>
                    </div>
                    <?php if ($c['posicao']): ?><span class="posicao-badge" title="Posição no rodízio"><?= (int) $c['posicao'] ?>º</span><?php endif; ?>
                    <span class="pill <?= $classe ?>"><?= $rotulo ?></span>
                    <?php if ($podeGerenciar): ?>
                        <div class="row-actions">
                            <?php if ($c['pausado']): ?>
                                <button type="button" class="btn btn-outline btn-sm" data-corretor-acao="retomar" data-usuario="<?= (int) $c['usuario_id'] ?>">Retomar</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline btn-sm" data-corretor-acao="pausar" data-usuario="<?= (int) $c['usuario_id'] ?>" data-confirmar="Pausar <?= e($c['nome']) ?>? Ele sai da roleta e não recebe leads até ser retomado.">Pausar</button>
                            <?php endif; ?>
                            <?php if ($c['sessao_id']): ?>
                                <button type="button" class="btn btn-outline btn-sm" data-corretor-acao="retirar" data-usuario="<?= (int) $c['usuario_id'] ?>" data-confirmar="Tirar <?= e($c['nome']) ?> da roleta agora? Ele pode entrar de novo pelo celular.">Tirar agora</button>
                            <?php endif; ?>
                            <button type="button" class="remove-link" data-corretor-acao="remover" data-usuario="<?= (int) $c['usuario_id'] ?>" data-confirmar="Remover <?= e($c['nome']) ?> desta roleta?">Remover</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$corretores): ?><p class="empty-note">Nenhum corretor vinculado a esta roleta ainda.</p><?php endif; ?>
            <?php if ($podeGerenciar && $corretoresDisponiveis): ?>
                <form class="add-row" data-add-corretor>
                    <select name="usuario_id" required aria-label="Corretor">
                        <option value="">Vincular corretor...</option>
                        <?php foreach ($corretoresDisponiveis as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm">Vincular</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($podeDistribuir): ?>
        <div class="card" style="grid-column: 1 / -1;" id="fila">
            <div class="card-head">
                <div><h2>Fila de espera</h2><p>Leads que chegaram quando ninguém podia receber. Entram automaticamente quando um corretor entra na roleta.</p></div>
                <?php if ($fila): ?><button type="button" class="btn btn-primary btn-sm" data-fila-processar="<?= (int) $roleta['id'] ?>">Distribuir fila agora</button><?php endif; ?>
            </div>
            <?php foreach ($fila as $f): ?>
                <div class="fila-row">
                    <div class="who-info">
                        <strong><?= e($f['nome']) ?></strong>
                        <span><?= e($f['empreendimento_nome'] ?? 'Sem empreendimento') ?> · <?= e(roleta_origem_rotulo($f['origem'])) ?> · esperando desde <?= date('d/m H:i', strtotime($f['entrou_em'])) ?></span>
                        <span class="muted"><?= e($f['motivo'] ?? '') ?><?= (int) $f['tentativas'] > 1 ? ' · ' . (int) $f['tentativas'] . ' tentativas' : '' ?></span>
                    </div>
                    <div class="row-actions">
                        <button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/roleta_fila.php" data-acao="distribuir" data-lead_id="<?= (int) $f['lead_id'] ?>">Distribuir agora</button>
                        <?php if (usuario_tem_permissao($usuario, 'redistribuir_leads')): ?><a class="btn btn-outline btn-sm" href="/pages/lead.php?id=<?= (int) $f['lead_id'] ?>&transferir=1">Atribuir manualmente</a><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$fila): ?><p class="empty-note">Nenhum lead esperando nesta roleta.</p><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card" style="grid-column: 1 / -1;" id="historico">
            <div class="card-head">
                <div><h2>Histórico de distribuição</h2><p>Quem recebeu cada lead desta roleta (últimos 100 no período).</p></div>
            </div>
            <form class="filtros-inline" method="get" action="/pages/roletas.php#historico">
                <input type="hidden" name="id" value="<?= (int) $roleta['id'] ?>">
                <select name="periodo" aria-label="Período"><?php foreach ($periodos as $k => $v): ?><option value="<?= $k ?>" <?= $k === $periodo ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
                <select name="corretor" aria-label="Corretor"><option value="">Todos os corretores</option><?php foreach ($historicoUsuarios as $hu): ?><option value="<?= $hu['id'] ?>" <?= $hu['id'] === $filtroCorretor ? 'selected' : '' ?>><?= e($hu['nome']) ?></option><?php endforeach; ?></select>
                <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
            </form>
            <?php if ($historico): ?>
                <div class="table-wrap"><table class="report-table hist-table">
                    <thead><tr><th>Quando</th><th>Lead</th><th>Recebido por</th><th>Origem</th></tr></thead>
                    <tbody>
                    <?php foreach ($historico as $h): ?>
                        <tr>
                            <td><?= date('d/m H:i', strtotime($h['distribuido_em'])) ?></td>
                            <td><?php if ($h['lead_nome'] !== null): ?><a href="/pages/lead.php?id=<?= (int) $h['lead_id'] ?>"><?= e($h['lead_nome']) ?></a><?php else: ?><span class="muted">Lead #<?= (int) $h['lead_id'] ?></span><?php endif; ?></td>
                            <td><?= e($h['corretor_nome']) ?></td>
                            <td><?= e(roleta_origem_rotulo($h['origem'])) ?><?= $h['executor_nome'] ? ' · por ' . e($h['executor_nome']) : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php else: ?><p class="empty-note">Nenhuma distribuição no período.</p><?php endif; ?>
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
    <div class="modal-box modal-wide">
        <button type="button" class="overlay-close" data-close-modal="modal-nova-roleta">&times;</button>
        <h2>Nova roleta</h2>
        <p class="muted small">Depois de criar, vincule empreendimentos e corretores.</p>
        <?php roleta_form_regras(null, $plantoesOpcoes); ?>
    </div>
</div>
<?php if ($roleta): ?>
<div class="modal-overlay" id="modal-editar-roleta" hidden>
    <div class="modal-box modal-wide">
        <button type="button" class="overlay-close" data-close-modal="modal-editar-roleta">&times;</button>
        <h2>Regras da roleta</h2>
        <?php roleta_form_regras($roleta, $plantoesOpcoes); ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
