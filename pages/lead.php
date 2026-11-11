<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/arquivos.php';

$usuario = auth_require();
$empresaId = (int) $usuario['empresa_ativa_id'];
$leadId = (int) ($_GET['id'] ?? 0);
$crumb = $leadId ? 'Ficha do lead' : 'Novo lead';
$activeNav = 'ficha';
$ehCorretor = $usuario['escopo'] === 'propria_carteira';

$stmt = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :empresa_id AND ativo = 1 ORDER BY nome');
$stmt->execute(['empresa_id' => $empresaId]);
$empreendimentos = $stmt->fetchAll();

// ===================================================================== Cadastro
if (!$leadId) {
    if (!usuario_tem_permissao($usuario, 'criar_leads')) {
        pagina_acesso_restrito($crumb, $activeNav, 'Criar leads');
    }
    $podeEscolherResponsavel = usuario_tem_permissao($usuario, 'redistribuir_leads') && !$ehCorretor;
    $responsaveis = $podeEscolherResponsavel ? rbac_usuarios_atribuiveis($usuario) : [];
    // Empreendimentos com roleta ativa: para eles o gestor pode deixar o rodízio escolher o corretor.
    $podeRoleta = !$ehCorretor && (usuario_tem_permissao($usuario, 'gerenciar_roletas') || usuario_tem_permissao($usuario, 'redistribuir_leads'));
    $empComRoleta = [];
    if ($podeRoleta) {
        $stmt = db()->prepare('SELECT DISTINCT re.empreendimento_id FROM roleta_empreendimentos re
            INNER JOIN roletas r ON r.id = re.roleta_id AND r.ativa = 1 WHERE r.empresa_id = :empresa_id');
        $stmt->execute(['empresa_id' => $empresaId]);
        $empComRoleta = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $pageTitle = 'Novo lead — ' . APP_NAME;
    require __DIR__ . '/../includes/layout_start.php';
    ?>
    <a href="/pages/leads.php" class="back-link"><?= icon('arrow-r') ?>Voltar para os leads</a>
    <div class="header">
        <div><div class="eyebrow">Novo atendimento</div><h1>Cadastrar lead</h1><p>Só o nome é obrigatório. Com o telefone, o sistema avisa se o cliente já está cadastrado.</p></div>
    </div>
    <div class="card form-card">
        <form data-api-form action="/api/lead_salvar.php" data-redirecionar="/pages/lead.php?id={id}&novo=1">
            <div class="form-grid">
                <div class="field"><label for="n-nome">Nome do cliente *</label><input type="text" id="n-nome" name="nome" required maxlength="190" autocomplete="off"></div>
                <div class="field"><label for="n-tel">Telefone / WhatsApp</label><input type="tel" id="n-tel" name="telefone" placeholder="(11) 90000-0000" inputmode="tel" autocomplete="off"></div>
                <div class="field"><label for="n-email">E-mail</label><input type="email" id="n-email" name="email" maxlength="190" autocomplete="off"></div>
                <div class="field"><label for="n-emp">Empreendimento de interesse</label>
                    <select id="n-emp" name="empreendimento_id">
                        <option value="">Não informado</option>
                        <?php foreach ($empreendimentos as $emp): ?><option value="<?= (int) $emp['id'] ?>" <?= in_array((int) $emp['id'], $empComRoleta, true) ? 'data-tem-roleta="1"' : '' ?>><?= e($emp['nome']) ?><?= in_array((int) $emp['id'], $empComRoleta, true) ? ' · roleta ativa' : '' ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="n-origem">Origem</label>
                    <select id="n-origem" name="origem">
                        <?php foreach (lead_origens() as $valor => $rotulo): ?><option value="<?= $valor ?>"><?= $rotulo ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="n-prio">Prioridade</label>
                    <select id="n-prio" name="prioridade">
                        <?php foreach (lead_prioridades() as $valor => $rotulo): ?><option value="<?= $valor ?>" <?= $valor === 'normal' ? 'selected' : '' ?>><?= $rotulo ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="n-int">Interesse</label><input type="text" id="n-int" name="interesse" placeholder="Ex.: 2 dormitórios" maxlength="190"></div>
                <div class="field"><label for="n-renda">Renda familiar informada</label><input type="text" id="n-renda" name="renda_informada" placeholder="Ex.: 5.600" inputmode="decimal"></div>
                <?php if ($responsaveis): ?>
                    <div class="field" data-campo-responsavel><label for="n-resp">Responsável</label>
                        <select id="n-resp" name="responsavel_id">
                            <option value="">Eu mesmo (<?= e($usuario['nome']) ?>)</option>
                            <?php foreach ($responsaveis as $r): if ((int) $r['id'] === (int) $usuario['id']) continue; ?><option value="<?= (int) $r['id'] ?>"><?= e($r['nome']) ?><?= $r['equipe_nome'] ? ' — ' . e($r['equipe_nome']) : '' ?></option><?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <div class="field"><label for="n-obs">Observação</label><textarea id="n-obs" name="observacao" maxlength="500" placeholder="Ex.: Cliente prefere contato à tarde."></textarea></div>
            <?php if ($podeRoleta && $empComRoleta): ?>
                <label class="check-roleta" data-opcao-roleta hidden>
                    <input type="checkbox" name="distribuir_roleta" value="1" data-distribuir-roleta>
                    <span><strong>Distribuir pela roleta</strong><small>O rodízio escolhe o próximo corretor que está no plantão. Se ninguém estiver disponível, o lead fica na fila de espera e é entregue assim que alguém entrar.</small></span>
                </label>
            <?php endif; ?>
            <div class="form-alert" data-form-alerta hidden></div>
            <div data-form-resultado hidden></div>
            <div class="modal-actions"><button type="submit" class="btn btn-primary"><?= icon('plus') ?>Cadastrar lead</button><a href="/pages/leads.php" class="btn btn-outline">Cancelar</a></div>
        </form>
    </div>
    <?php
    require __DIR__ . '/../includes/layout_end.php';
    exit;
}

// ===================================================================== Ficha
$lead = lead_carregar($usuario, $leadId);
if (!$lead) {
    http_response_code(404);
    $pageTitle = 'Lead não encontrado — ' . APP_NAME;
    require __DIR__ . '/../includes/layout_start.php';
    echo '<div class="card stub-card"><div class="stub-icon">' . icon('alert') . '</div><h2>Lead não encontrado</h2><p>Ele não existe, foi excluído ou pertence a outra carteira ou equipe.</p><p style="margin-top:16px"><a class="btn btn-primary" href="/pages/leads.php">Voltar para os leads</a></p></div>';
    require __DIR__ . '/../includes/layout_end.php';
    exit;
}

$podeEditar = usuario_tem_permissao($usuario, 'editar_leads');
$podeExcluir = usuario_tem_permissao($usuario, 'excluir_leads');
$podeTransferir = usuario_tem_permissao($usuario, 'redistribuir_leads');
$podeArquivos = usuario_tem_permissao($usuario, 'acessar_arquivos_leads');

$etapasKanban = funil_etapas($empresaId, 'kanban');
$etapasAtendimento = $etapasKanban;
if (!in_array((int) $lead['etapa_id'], array_map(fn ($e) => (int) $e['id'], $etapasKanban), true) && ($etapaAtual = funil_etapa($empresaId, (int) $lead['etapa_id']))) {
    $etapasAtendimento[] = $etapaAtual;
}

$stmt = db()->prepare('SELECT lead_eventos.*, usuarios.nome AS usuario_nome FROM lead_eventos LEFT JOIN usuarios ON usuarios.id = lead_eventos.usuario_id WHERE lead_id = :id ORDER BY lead_eventos.criado_em DESC, lead_eventos.id DESC');
$stmt->execute(['id' => $leadId]);
$historico = $stmt->fetchAll();

$compromissos = agenda_listar($usuario, ['lead_id' => $leadId, 'desc' => true]);
$pendentes = array_values(array_filter($compromissos, fn ($c) => $c['status'] === 'agendado'));
usort($pendentes, fn ($a, $b) => strcmp($a['inicio'], $b['inicio']));

$stmt = db()->prepare('SELECT * FROM lead_tarefas WHERE lead_id = :id ORDER BY concluida ASC, criado_em ASC');
$stmt->execute(['id' => $leadId]);
$tarefas = $stmt->fetchAll();

$arquivos = [];
if ($podeArquivos) {
    $stmt = db()->prepare('SELECT lead_arquivos.*, usuarios.nome AS enviado_por_nome FROM lead_arquivos LEFT JOIN usuarios ON usuarios.id = lead_arquivos.enviado_por WHERE lead_arquivos.lead_id = :id AND lead_arquivos.empresa_id = :e AND lead_arquivos.excluido_em IS NULL ORDER BY lead_arquivos.criado_em DESC');
    $stmt->execute(['id' => $leadId, 'e' => $empresaId]);
    $arquivos = $stmt->fetchAll();
}

$responsaveisAgenda = !$ehCorretor ? rbac_usuarios_atribuiveis($usuario) : [];
$destinos = $podeTransferir ? array_values(array_filter(rbac_usuarios_atribuiveis($usuario), fn ($d) => (int) $d['id'] !== (int) $lead['responsavel_id'])) : [];

$whats = lead_link_whatsapp($lead['telefone']);
$tel = lead_telefone_normalizado($lead['telefone']);
$atrasado = lead_esta_atrasado($lead + ['compromisso_vencido' => (bool) array_filter($pendentes, fn ($c) => strtotime($c['inicio']) < time())]);
$abas = ['resumo' => 'Resumo', 'atendimento' => 'Atendimento', 'historico' => 'Histórico', 'agenda' => 'Agenda', 'arquivos' => 'Arquivos'];
$abaAtiva = array_key_exists($_GET['aba'] ?? '', $abas) ? $_GET['aba'] : 'resumo';
$passos = ['Ligar novamente', 'Enviar proposta', 'Enviar simulação', 'Confirmar visita', 'Aguardar documentos', 'Aguardar retorno do cliente'];
$tiposEvento = lead_tipos_evento();

$pageTitle = $lead['nome'] . ' — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>

<a href="/pages/leads.php" class="back-link"><?= icon('arrow-r') ?>Voltar para os leads</a>

<div class="lead-header card">
    <div class="lead-header-main">
        <div class="avatar lead-avatar"><?= e(lead_iniciais($lead['nome'])) ?></div>
        <div>
            <h1><?= e($lead['nome']) ?></h1>
            <div class="lead-header-sub">
                <span class="etapa-chip" style="--etapa-cor: <?= e($lead['etapa_cor'] ?? '#99a3b5') ?>"><?= e($lead['etapa_nome'] ?? lead_etapa_rotulo($lead['etapa'])) ?></span>
                <?php if ($lead['prioridade'] === 'alta'): ?><span class="pill pill-red">Prioridade alta</span><?php endif; ?>
                <?php if ($atrasado): ?><span class="pill pill-red"><?= icon('alert') ?>Atendimento atrasado</span><?php endif; ?>
                <span class="muted">Lead #<?= 10000 + (int) $lead['id'] ?> · <?= e(lead_origens()[$lead['origem']] ?? $lead['origem']) ?> · <?= e($lead['empreendimento_nome'] ?? 'Sem empreendimento') ?> · Responsável: <?= e($lead['responsavel_nome'] ?? '—') ?></span>
            </div>
        </div>
    </div>
    <div class="lead-actions" aria-label="Ações do lead">
        <?php if ($tel): ?>
            <a class="btn btn-primary" href="tel:<?= e($tel) ?>" data-registrar="ligacao" data-lead="<?= (int) $lead['id'] ?>"><?= icon('phone') ?>Ligar</a>
            <?php if ($whats): ?><a class="btn btn-success" href="<?= e($whats) ?>" target="_blank" rel="noopener" data-registrar="whatsapp" data-lead="<?= (int) $lead['id'] ?>"><?= icon('whatsapp') ?>WhatsApp</a><?php endif; ?>
        <?php endif; ?>
        <?php if ($podeEditar): ?>
            <button type="button" class="btn btn-outline btn-wide" data-open-modal="modal-registro" data-registro-tipo="contato"><?= icon('user') ?>Registrar contato</button>
            <button type="button" class="btn btn-outline" data-open-modal="modal-agendar" data-agendar-tipo="visita"><?= icon('calendar') ?>Agendar visita</button>
            <button type="button" class="btn btn-outline" data-mover-lead="<?= (int) $lead['id'] ?>" data-etapa-atual="<?= (int) $lead['etapa_id'] ?>" data-lead-nome="<?= e($lead['nome']) ?>"><?= icon('move') ?>Atualizar etapa</button>
        <?php endif; ?>
        <details class="more-menu">
            <summary class="btn btn-outline" aria-label="Mais ações"><?= icon('more') ?>Mais</summary>
            <div class="more-menu-list">
                <?php if ($podeEditar): ?>
                    <button type="button" data-open-modal="modal-registro" data-registro-tipo="nota"><?= icon('note') ?>Adicionar observação</button>
                    <a href="?id=<?= (int) $lead['id'] ?>&aba=atendimento#proximo-contato"><?= icon('clock') ?>Definir próximo contato</a>
                    <button type="button" data-open-modal="modal-editar-lead"><?= icon('edit') ?>Editar dados</button>
                <?php endif; ?>
                <?php if ($podeTransferir): ?>
                    <button type="button" data-transferir-lead="<?= (int) $lead['id'] ?>"><?= icon('users') ?>Transferir lead</button>
                <?php endif; ?>
                <?php if ($podeExcluir): ?>
                    <button type="button" data-open-modal="modal-titular"><?= icon('file') ?>Exportar dados do titular (LGPD)</button>
                    <button type="button" class="danger" data-open-modal="modal-anonimizar"><?= icon('shield') ?>Anonimizar lead (LGPD)</button>
                    <button type="button" class="danger" data-api-acao="/api/lead_excluir.php" data-lead_id="<?= (int) $lead['id'] ?>" data-confirmar="Excluir o lead <?= e($lead['nome']) ?>? Ele some de todas as telas e relatórios." data-ir-para="/pages/leads.php"><?= icon('trash') ?>Excluir lead</button>
                <?php endif; ?>
            </div>
        </details>
    </div>
</div>

<div class="tabs" role="tablist" aria-label="Seções da ficha">
    <?php foreach ($abas as $chave => $rotulo): ?>
        <a role="tab" href="?id=<?= (int) $lead['id'] ?>&aba=<?= $chave ?>" class="tab<?= $abaAtiva === $chave ? ' is-current' : '' ?>" aria-selected="<?= $abaAtiva === $chave ? 'true' : 'false' ?>" data-aba="<?= $chave ?>">
            <?= $rotulo ?><?php if ($chave === 'agenda' && $pendentes): ?> <b class="tab-count"><?= count($pendentes) ?></b><?php elseif ($chave === 'arquivos' && $arquivos): ?> <b class="tab-count"><?= count($arquivos) ?></b><?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- ============================================================ Resumo -->
<section class="tab-panel" data-painel="resumo" <?= $abaAtiva === 'resumo' ? '' : 'hidden' ?>>
    <div class="grid-2">
        <div class="card">
            <div class="card-head"><div><h2>Dados do lead</h2></div><?php if ($podeEditar): ?><button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-editar-lead"><?= icon('edit') ?>Editar</button><?php endif; ?></div>
            <dl class="data-grid">
                <div><dt>Telefone</dt><dd><?= e(lead_formatar_telefone($lead['telefone'])) ?></dd></div>
                <div><dt>E-mail</dt><dd><?= e($lead['email'] ?: '—') ?></dd></div>
                <div><dt>Empreendimento</dt><dd><?= e($lead['empreendimento_nome'] ?? '—') ?></dd></div>
                <div><dt>Origem</dt><dd><?= e(lead_origens()[$lead['origem']] ?? $lead['origem']) ?></dd></div>
                <div><dt>Interesse</dt><dd><?= e($lead['interesse'] ?: '—') ?></dd></div>
                <div><dt>Campanha</dt><dd><?= e($lead['campanha'] ?: '—') ?></dd></div>
                <div><dt>Renda familiar</dt><dd><?= $lead['renda_informada'] ? 'R$ ' . number_format((float) $lead['renda_informada'], 0, ',', '.') : '—' ?></dd></div>
                <div><dt>Responsável</dt><dd><?= e($lead['responsavel_nome'] ?? '—') ?><?= $lead['equipe_nome'] ? ' · ' . e($lead['equipe_nome']) : '' ?></dd></div>
                <div><dt>Prioridade</dt><dd><?= e(lead_prioridades()[$lead['prioridade']] ?? '—') ?></dd></div>
                <div><dt>Data de entrada</dt><dd><?= date('d/m/Y \à\s H\hi', strtotime($lead['criado_em'])) ?></dd></div>
                <div><dt>Última atividade</dt><dd><?= e(tempo_relativo($lead['ultima_atividade_em'] ?? $lead['criado_em'])) ?></dd></div>
                <div><dt>Próximo contato</dt><dd><?= $lead['data_retorno'] ? date('d/m/Y', strtotime($lead['data_retorno'])) : '—' ?></dd></div>
                <div><dt>Próximo passo</dt><dd><?= e($lead['proximo_passo'] ?: '—') ?></dd></div>
            </dl>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Próximas ações</h2><p>Compromissos agendados para este cliente.</p></div></div>
            <?php foreach (array_slice($pendentes, 0, 4) as $c) render_compromisso($c, !$ehCorretor, false); ?>
            <?php if (!$pendentes): ?><p class="empty-note">Nada agendado. Defina o próximo contato para não perder o cliente.</p><?php endif; ?>
            <?php if ($podeEditar): ?>
                <div class="modal-actions">
                    <a class="btn btn-primary" href="?id=<?= (int) $lead['id'] ?>&aba=atendimento" data-ir-aba="atendimento"><?= icon('check') ?>Registrar atendimento</a>
                    <button type="button" class="btn btn-outline" data-open-modal="modal-agendar" data-agendar-tipo="retorno"><?= icon('calendar') ?>Agendar</button>
                </div>
            <?php endif; ?>
            <div class="rule-box"><?= icon('alert') ?><span><b>Regra de atenção:</b> leads sem feedback válido por 4 dias podem ser redistribuídos.</span></div>
        </div>
    </div>
</section>

<!-- ============================================================ Atendimento -->
<section class="tab-panel" data-painel="atendimento" <?= $abaAtiva === 'atendimento' ? '' : 'hidden' ?>>
    <div class="grid-2">
        <div class="card">
            <div class="card-head"><div><h2>Registrar atendimento</h2><p>Em um só passo: o que aconteceu, a etapa e o próximo contato.</p></div></div>
            <?php if ($podeEditar): ?>
                <form data-api-form action="/api/lead_atendimento.php" data-recarregar>
                    <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                    <div class="field">
                        <label for="at-nota">O que aconteceu no contato?</label>
                        <textarea id="at-nota" name="nota" maxlength="500" placeholder="Ex.: Cliente gostou da planta de 2 dormitórios e quer visitar no sábado."></textarea>
                    </div>
                    <div class="form-grid">
                        <div class="field">
                            <label for="at-etapa">Etapa</label>
                            <select id="at-etapa" name="etapa_id">
                                <?php foreach ($etapasAtendimento as $etapa): ?>
                                    <option value="<?= (int) $etapa['id'] ?>" <?= (int) $etapa['id'] === (int) $lead['etapa_id'] ? 'selected' : '' ?>><?= e($etapa['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="at-passo">Próximo passo</label>
                            <select id="at-passo" name="proximo_passo">
                                <option value="">—</option>
                                <?php foreach ($passos as $passo): ?><option <?= $lead['proximo_passo'] === $passo ? 'selected' : '' ?>><?= e($passo) ?></option><?php endforeach; ?>
                                <?php if ($lead['proximo_passo'] && !in_array($lead['proximo_passo'], $passos, true)): ?><option selected><?= e($lead['proximo_passo']) ?></option><?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="field" id="proximo-contato">
                        <label for="at-quando">Próximo contato (vira um retorno na agenda)</label>
                        <input type="datetime-local" id="at-quando" name="proximo_contato">
                        <div class="chip-row quick-dates">
                            <button type="button" class="chip-btn" data-data-rapida="hoje18">Hoje 18h</button>
                            <button type="button" class="chip-btn" data-data-rapida="amanha10">Amanhã 10h</button>
                            <button type="button" class="chip-btn" data-data-rapida="2dias10">Em 2 dias</button>
                            <button type="button" class="chip-btn" data-data-rapida="semana10">Próxima semana</button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg"><?= icon('check') ?>Salvar atendimento</button>
                </form>
            <?php else: ?>
                <p class="empty-note">Sua função pode consultar este lead, mas não registrar atendimentos.</p>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Últimas atividades</h2></div><a class="link-btn" href="?id=<?= (int) $lead['id'] ?>&aba=historico" data-ir-aba="historico">Ver histórico completo</a></div>
            <div class="timeline">
                <?php foreach (array_slice($historico, 0, 6) as $ev): $tipo = $tiposEvento[$ev['tipo']] ?? ['rotulo' => $ev['tipo'], 'icon' => 'zap']; ?>
                    <div class="timeline-item"><div class="timeline-icon"><?= icon($tipo['icon']) ?></div><div><strong><?= e($ev['descricao']) ?></strong><span><?= e($ev['usuario_nome'] ?? 'Sistema') ?> · <?= date('d/m/Y, H\hi', strtotime($ev['criado_em'])) ?></span></div></div>
                <?php endforeach; ?>
                <?php if (!$historico): ?><p class="empty-note">Sem atividades ainda.</p><?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================ Histórico -->
<section class="tab-panel" data-painel="historico" <?= $abaAtiva === 'historico' ? '' : 'hidden' ?>>
    <div class="card">
        <div class="card-head"><div><h2>Histórico completo</h2><p>Tudo o que foi feito com este cliente, com data, horário e responsável.</p></div></div>
        <div class="chip-row hist-filtros" role="group" aria-label="Filtrar por tipo">
            <button type="button" class="chip-btn is-active" data-filtro-hist="">Tudo (<?= count($historico) ?>)</button>
            <?php foreach (array_count_values(array_column($historico, 'tipo')) as $tipo => $qtd): ?>
                <button type="button" class="chip-btn" data-filtro-hist="<?= e($tipo) ?>"><?= e($tiposEvento[$tipo]['rotulo'] ?? $tipo) ?> (<?= $qtd ?>)</button>
            <?php endforeach; ?>
        </div>
        <div class="timeline">
            <?php foreach ($historico as $ev): $tipo = $tiposEvento[$ev['tipo']] ?? ['rotulo' => $ev['tipo'], 'icon' => 'zap']; ?>
                <div class="timeline-item" data-tipo-hist="<?= e($ev['tipo']) ?>">
                    <div class="timeline-icon timeline-icon-<?= e($ev['tipo']) ?>"><?= icon($tipo['icon']) ?></div>
                    <div><small class="timeline-tipo"><?= e($tipo['rotulo']) ?></small><strong><?= e($ev['descricao']) ?></strong><span><?= e($ev['usuario_nome'] ?? 'Sistema') ?> · <?= date('d/m/Y \à\s H\hi', strtotime($ev['criado_em'])) ?></span></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$historico): ?><p class="empty-note">Sem eventos registrados ainda.</p><?php endif; ?>
        </div>
    </div>
</section>

<!-- ============================================================ Agenda -->
<section class="tab-panel" data-painel="agenda" <?= $abaAtiva === 'agenda' ? '' : 'hidden' ?>>
    <div class="grid-2">
        <div class="card">
            <div class="card-head"><div><h2>Compromissos</h2><p>Visitas, retornos e tarefas deste cliente.</p></div>
                <?php if ($podeEditar): ?><button type="button" class="btn btn-primary btn-sm" data-open-modal="modal-agendar" data-agendar-tipo="visita"><?= icon('plus') ?>Novo</button><?php endif; ?>
            </div>
            <?php foreach ($pendentes as $c) render_compromisso($c, !$ehCorretor, false); ?>
            <?php $concluidos = array_values(array_filter($compromissos, fn ($c) => $c['status'] !== 'agendado')); ?>
            <?php if ($concluidos): ?>
                <h3 class="subhead">Concluídos</h3>
                <?php foreach ($concluidos as $c) render_compromisso($c, !$ehCorretor, false); ?>
            <?php endif; ?>
            <?php if (!$compromissos): ?><p class="empty-note">Nenhum compromisso para este cliente.</p><?php endif; ?>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Lista de pendências</h2><p>Lembretes rápidos do atendimento.</p></div></div>
            <div id="lista-tarefas">
                <?php foreach ($tarefas as $t): ?>
                    <label class="next-task <?= $t['concluida'] ? 'done' : '' ?>">
                        <input type="checkbox" <?= $t['concluida'] ? 'checked' : '' ?> data-toggle-tarefa="<?= (int) $t['id'] ?>" <?= $podeEditar ? '' : 'disabled' ?>>
                        <span><strong><?= e($t['titulo']) ?></strong><?php if ($t['descricao']): ?><small><?= e($t['descricao']) ?></small><?php endif; ?></span>
                    </label>
                <?php endforeach; ?>
                <?php if (!$tarefas): ?><p class="empty-note">Nenhuma pendência.</p><?php endif; ?>
            </div>
            <?php if ($podeEditar): ?>
                <form id="form-nova-tarefa" class="add-row">
                    <?= csrf_field() ?>
                    <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                    <label class="sr-only" for="nova-tarefa">Nova pendência</label>
                    <input type="text" id="nova-tarefa" name="titulo" placeholder="Ex.: Pedir holerite" maxlength="190" required>
                    <button type="submit" class="btn btn-outline"><?= icon('plus') ?>Adicionar</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ============================================================ Arquivos -->
<section class="tab-panel" data-painel="arquivos" <?= $abaAtiva === 'arquivos' ? '' : 'hidden' ?>>
    <div class="card">
        <div class="card-head"><div><h2>Arquivos e documentos</h2><p>PDF, imagens, Word ou Excel — até <?= e(tamanho_legivel(lead_arquivo_limite_bytes())) ?> cada. Visíveis só para quem atende este lead.</p></div></div>
        <?php if (!$podeArquivos): ?>
            <p class="empty-note">Sua função não tem acesso a arquivos de leads.</p>
        <?php else: ?>
            <form data-api-form action="/api/lead_arquivo_enviar.php" data-recarregar class="upload-box" enctype="multipart/form-data">
                <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                <label class="btn btn-outline file-btn" for="arquivo-input"><?= icon('upload') ?>Escolher arquivo</label>
                <input type="file" id="arquivo-input" name="arquivo" class="sr-only" required accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx,application/pdf,image/jpeg,image/png,image/webp" data-limite="<?= lead_arquivo_limite_bytes() ?>" data-mostrar-nome>
                <span class="file-name" data-file-name>Nenhum arquivo escolhido</span>
                <button type="submit" class="btn btn-primary"><?= icon('upload') ?>Enviar</button>
            </form>
            <div class="file-list">
                <?php foreach ($arquivos as $a): ?>
                    <div class="file-row">
                        <div class="file-icon"><?= icon('file') ?></div>
                        <div class="who-info"><strong><?= e($a['nome_original']) ?></strong><span><?= e(lead_arquivo_tipos()[$a['mime']] ?? 'Arquivo') ?> · <?= e(tamanho_legivel((int) $a['tamanho'])) ?> · <?= e($a['enviado_por_nome'] ?? '—') ?> · <?= date('d/m/Y H\hi', strtotime($a['criado_em'])) ?></span></div>
                        <a class="btn btn-outline btn-sm" href="/api/lead_arquivo_baixar.php?id=<?= (int) $a['id'] ?>"><?= icon('download') ?>Baixar</a>
                        <button type="button" class="link-btn danger" data-api-acao="/api/lead_arquivo_excluir.php" data-id="<?= (int) $a['id'] ?>" data-confirmar="Remover o arquivo <?= e($a['nome_original']) ?>?">Remover</button>
                    </div>
                <?php endforeach; ?>
                <?php if (!$arquivos): ?><p class="empty-note">Nenhum arquivo enviado.</p><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($podeEditar): ?>
    <?php render_modal_mover_etapa($etapasKanban); ?>
    <?php render_modal_agendar($lead, $responsaveisAgenda, true); ?>
    <div class="modal-overlay" id="modal-registro" hidden>
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-registro-titulo">
            <button type="button" class="overlay-close" data-close-modal="modal-registro" aria-label="Fechar">&times;</button>
            <h2 id="modal-registro-titulo" data-registro-titulo>Registrar contato</h2>
            <form data-api-form action="/api/lead_registrar.php" data-recarregar>
                <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                <input type="hidden" name="tipo" value="contato">
                <div class="field"><label for="registro-texto" data-registro-label>O que aconteceu?</label><textarea id="registro-texto" name="texto" required maxlength="500"></textarea></div>
                <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar</button>
            </form>
        </div>
    </div>
    <div class="modal-overlay" id="modal-editar-lead" hidden>
        <div class="modal-box modal-wide" role="dialog" aria-modal="true" aria-labelledby="modal-editar-titulo">
            <button type="button" class="overlay-close" data-close-modal="modal-editar-lead" aria-label="Fechar">&times;</button>
            <h2 id="modal-editar-titulo">Editar dados do lead</h2>
            <form data-api-form action="/api/lead_salvar.php" data-recarregar>
                <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                <div class="form-grid">
                    <div class="field"><label for="ed-nome">Nome *</label><input type="text" id="ed-nome" name="nome" required maxlength="190" value="<?= e($lead['nome']) ?>"></div>
                    <div class="field"><label for="ed-tel">Telefone</label><input type="tel" id="ed-tel" name="telefone" value="<?= e($lead['telefone']) ?>"></div>
                    <div class="field"><label for="ed-email">E-mail</label><input type="email" id="ed-email" name="email" value="<?= e($lead['email']) ?>"></div>
                    <div class="field"><label for="ed-emp">Empreendimento</label>
                        <select id="ed-emp" name="empreendimento_id"><option value="">Não informado</option>
                            <?php foreach ($empreendimentos as $emp): ?><option value="<?= (int) $emp['id'] ?>" <?= (int) $lead['empreendimento_id'] === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['nome']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label for="ed-origem">Origem</label>
                        <select id="ed-origem" name="origem"><?php foreach (lead_origens() as $v => $r): ?><option value="<?= $v ?>" <?= $lead['origem'] === $v ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="field"><label for="ed-prio">Prioridade</label>
                        <select id="ed-prio" name="prioridade"><?php foreach (lead_prioridades() as $v => $r): ?><option value="<?= $v ?>" <?= $lead['prioridade'] === $v ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="field"><label for="ed-int">Interesse</label><input type="text" id="ed-int" name="interesse" value="<?= e($lead['interesse']) ?>"></div>
                    <div class="field"><label for="ed-camp">Campanha</label><input type="text" id="ed-camp" name="campanha" maxlength="120" placeholder="Ex.: Lançamento março" value="<?= e($lead['campanha'] ?? '') ?>"></div>
                    <div class="field"><label for="ed-renda">Renda familiar</label><input type="text" id="ed-renda" name="renda_informada" inputmode="decimal" value="<?= $lead['renda_informada'] ? e(number_format((float) $lead['renda_informada'], 2, ',', '.')) : '' ?>"></div>
                </div>
                <div class="form-alert" data-form-alerta hidden></div>
                <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar alterações</button>
            </form>
        </div>
    </div>
<?php endif; ?>
<?php render_modal_compromisso(); ?>
<?php if ($podeTransferir) render_modal_transferir($destinos); ?>

<?php if ($podeExcluir): ?>
<div class="modal-overlay" id="modal-titular" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-titular-titulo">
        <button type="button" class="overlay-close" data-close-modal="modal-titular" aria-label="Fechar">&times;</button>
        <h2 id="modal-titular-titulo">Exportar dados do titular</h2>
        <p class="muted">Para atender um pedido de acesso pela LGPD: baixa um arquivo JSON com tudo o que o sistema guarda sobre <b><?= e($lead['nome']) ?></b> — cadastro, histórico, agenda, tarefas, lista de arquivos e transferências. Só deste lead.</p>
        <form action="/api/lead_titular_exportar.php" method="post" data-exportar-form>
            <?= csrf_field() ?><input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
            <div class="lgpd-aviso" role="note"><?= icon('shield') ?><div>Entregue o arquivo só ao próprio titular, por um canal seguro. A exportação fica registrada na auditoria.</div></div>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-primary"><?= icon('file') ?>Baixar dados (JSON)</button>
        </form>
    </div>
</div>
<div class="modal-overlay" id="modal-anonimizar" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-anonimizar-titulo">
        <button type="button" class="overlay-close" data-close-modal="modal-anonimizar" aria-label="Fechar">&times;</button>
        <h2 id="modal-anonimizar-titulo">Anonimizar este lead?</h2>
        <div class="danger-box" role="alert"><b>Esta ação não pode ser desfeita.</b> Use quando o cliente pedir a eliminação dos dados dele (LGPD).
            <ul>
                <li>Nome, telefone, e-mail, interesse, campanha e renda são apagados.</li>
                <li>Os arquivos anexados são excluídos.</li>
                <li>Observações e textos de atendimento do histórico, da agenda e das tarefas são apagados.</li>
                <li>Ficam só etapa, datas, origem, empreendimento e responsável — os relatórios não mudam.</li>
            </ul>
        </div>
        <form action="/api/lead_anonimizar.php" method="post" data-api-form>
            <?= csrf_field() ?><input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
            <div class="field"><label for="anon-conf">Para confirmar, digite <b>ANONIMIZAR</b></label>
                <input type="text" id="anon-conf" name="confirmacao" required autocomplete="off" pattern="[Aa][Nn][Oo][Nn][Ii][Mm][Ii][Zz][Aa][Rr]" title="Digite ANONIMIZAR"></div>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-danger"><?= icon('shield') ?>Anonimizar definitivamente</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
document.getElementById('form-nova-tarefa')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const r = await fetch('/api/tarefa_criar.php', { method: 'POST', body: new FormData(this) });
    if (r.ok) location.reload();
});
document.querySelectorAll('[data-toggle-tarefa]').forEach(function (cb) {
    cb.addEventListener('change', async function () {
        await fetch('/api/tarefa_toggle.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tarefa_id: this.dataset.toggleTarefa, concluida: this.checked ? 1 : 0, csrf_token: csrfToken() }),
        });
        this.closest('.next-task').classList.toggle('done', this.checked);
    });
});
</script>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
