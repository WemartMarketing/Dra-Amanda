<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/meta.php';

$usuario = auth_require();
$crumb = 'Integrações';
$activeNav = 'integracoes';
if (!usuario_tem_permissao($usuario, 'gerenciar_integracoes')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Gerenciar integrações (Meta Ads)');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$plataformaOk = meta_configurada();
$chaveOk = cripto_disponivel();

$stmt = db()->prepare('SELECT id, page_id, nome, token_validado_em, ativa, criado_em, (access_token_cifrado IS NOT NULL) AS tem_token FROM meta_paginas WHERE empresa_id = :e ORDER BY nome, id');
$stmt->execute(['e' => $empresaId]);
$paginas = $stmt->fetchAll();

$stmt = db()->prepare('SELECT f.*, emp.nome AS empreendimento_nome FROM meta_formularios f LEFT JOIN empreendimentos emp ON emp.id = f.empreendimento_id WHERE f.empresa_id = :e ORDER BY f.nome, f.id');
$stmt->execute(['e' => $empresaId]);
$formsPorPagina = [];
foreach ($stmt->fetchAll() as $f) {
    $formsPorPagina[(int) $f['meta_pagina_id']][] = $f;
}
$stmt = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :e AND ativo = 1 ORDER BY nome');
$stmt->execute(['e' => $empresaId]);
$empreendimentos = $stmt->fetchAll();

// Registro de eventos (filtros validados).
$statusEventos = meta_status_eventos();
$fStatus = array_key_exists($_GET['status'] ?? '', $statusEventos) ? $_GET['status'] : null;
$fPagina = preg_replace('/\D/', '', (string) ($_GET['pagina'] ?? '')) ?: null;
$sql = 'SELECT ev.*, f.nome AS form_nome, p.nome AS pagina_nome, l.nome AS lead_nome
        FROM meta_eventos ev
        LEFT JOIN meta_formularios f ON f.form_id = ev.form_id AND f.empresa_id = ev.empresa_id
        LEFT JOIN meta_paginas p ON p.page_id = ev.page_id AND p.empresa_id = ev.empresa_id
        LEFT JOIN leads l ON l.id = ev.lead_id AND l.empresa_id = ev.empresa_id
        WHERE ev.empresa_id = :e';
$params = ['e' => $empresaId];
if ($fStatus) { $sql .= ' AND ev.status = :s'; $params['s'] = $fStatus; }
if ($fPagina) { $sql .= ' AND ev.page_id = :p'; $params['p'] = $fPagina; }
$stmt = db()->prepare($sql . ' ORDER BY ev.id DESC LIMIT 50');
$stmt->execute($params);
$eventos = $stmt->fetchAll();
$stmt = db()->prepare('SELECT status, COUNT(*) AS n FROM meta_eventos WHERE empresa_id = :e AND recebido_em >= :d GROUP BY status');
$stmt->execute(['e' => $empresaId, 'd' => date('Y-m-d', strtotime('-30 days'))]);
$contagem = array_map('intval', array_column($stmt->fetchAll(), 'n', 'status'));

$pageTitle = 'Integrações — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>

<div class="header">
    <div>
        <div class="eyebrow">Configurações</div>
        <h1>Integrações</h1>
        <p>Leads dos formulários do <b>Meta Lead Ads</b> (Facebook e Instagram) entram sozinhos no sistema e vão para a roleta.</p>
    </div>
    <div class="actions">
        <button type="button" class="btn btn-primary" data-open-modal="modal-pagina" <?= $chaveOk ? '' : 'disabled' ?>><?= icon('plus') ?>Conectar página</button>
    </div>
</div>

<div class="card int-status">
    <div class="card-head"><div><h2>Meta Lead Ads</h2><p>Situação real da integração neste servidor.</p></div>
        <span class="pill <?= $plataformaOk && $paginas ? 'pill-green' : 'pill-muted' ?>"><?= $plataformaOk && $paginas ? 'Configurada' : 'Não configurada' ?></span></div>
    <div class="int-checks">
        <div class="int-check <?= $plataformaOk ? 'is-ok' : 'is-no' ?>"><?= icon($plataformaOk ? 'check' : 'x') ?><div><b>App da Meta no servidor</b><span><?= $plataformaOk ? 'App secret e token de verificação definidos no config.php.' : 'Falta META_APP_SECRET e/ou META_VERIFY_TOKEN no includes/config.php do servidor.' ?></span></div></div>
        <div class="int-check <?= $chaveOk ? 'is-ok' : 'is-no' ?>"><?= icon($chaveOk ? 'check' : 'x') ?><div><b>Chave de cifragem dos tokens</b><span><?= $chaveOk ? 'APP_KEY definida — tokens ficam cifrados no banco.' : 'Falta APP_KEY no config.php. Sem ela nenhum token é guardado.' ?></span></div></div>
        <div class="int-check <?= $paginas ? 'is-ok' : 'is-no' ?>"><?= icon($paginas ? 'check' : 'x') ?><div><b>Páginas conectadas</b><span><?= $paginas ? count($paginas) . ' página(s) cadastrada(s).' : 'Nenhuma página cadastrada.' ?></span></div></div>
    </div>
    <div class="field" style="margin-top:16px;">
        <label for="webhook-url">URL do webhook (cole no painel do app da Meta → Webhooks → Page → leadgen)</label>
        <input type="text" id="webhook-url" readonly value="<?= e(meta_webhook_url()) ?>" onclick="this.select()">
        <small class="field-hint">O token de verificação é o valor de META_VERIFY_TOKEN do config.php (não aparece aqui por segurança). Passo a passo completo: <b>docs/INTEGRACAO_META.md</b>.</small>
    </div>
    <?php if ($contagem): ?>
        <div class="chip-row int-contagem">
            <?php foreach ($statusEventos as $s => [$rot, $pill]): if (empty($contagem[$s])) continue; ?>
                <span class="pill <?= $pill ?>"><?= e($rot) ?>: <?= $contagem[$s] ?></span>
            <?php endforeach; ?>
            <span class="muted">últimos 30 dias</span>
        </div>
    <?php endif; ?>
</div>

<?php if (!$paginas): ?>
    <div class="card stub-card">
        <div class="stub-icon"><?= icon('zap') ?></div>
        <h2>Integração ainda não configurada</h2>
        <p>Integração ainda não configurada — nenhum lead do Meta é recebido até as credenciais serem cadastradas.</p>
        <?php if ($chaveOk): ?><button type="button" class="btn btn-primary" data-open-modal="modal-pagina"><?= icon('plus') ?>Conectar a primeira página</button><?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($paginas as $pg): $pid = (int) $pg['id']; $forms = $formsPorPagina[$pid] ?? []; ?>
    <div class="card int-pagina<?= (int) $pg['ativa'] ? '' : ' is-inactive' ?>">
        <div class="card-head">
            <div>
                <h2><?= e($pg['nome'] ?: 'Página ' . $pg['page_id']) ?></h2>
                <p>ID <?= e($pg['page_id']) ?> · <?= $pg['token_validado_em'] ? 'conexão testada ' . e(mb_strtolower(tempo_relativo($pg['token_validado_em']))) : 'conexão ainda não testada' ?></p>
            </div>
            <span class="pill <?= !(int) $pg['ativa'] ? 'pill-muted' : ($pg['token_validado_em'] ? 'pill-green' : 'pill-amber') ?>"><?= !(int) $pg['ativa'] ? 'Desativada' : ($pg['token_validado_em'] ? 'Conectada' : 'Testar conexão') ?></span>
        </div>
        <div class="row-actions int-acoes">
            <button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/integracoes.php" data-acao="pagina_testar" data-id="<?= $pid ?>"><?= icon('check') ?>Testar conexão</button>
            <button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/integracoes.php" data-acao="pagina_assinar" data-id="<?= $pid ?>"><?= icon('zap') ?>Assinar página no app</button>
            <button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/integracoes.php" data-acao="pagina_formularios" data-id="<?= $pid ?>"><?= icon('search') ?>Buscar formulários</button>
            <button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-token-<?= $pid ?>"><?= icon('edit') ?>Trocar token</button>
            <button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/integracoes.php" data-acao="pagina_ativar" data-id="<?= $pid ?>" data-ativa="<?= (int) $pg['ativa'] ? '0' : '1' ?>"><?= (int) $pg['ativa'] ? 'Desativar' : 'Ativar' ?></button>
            <button type="button" class="btn btn-outline btn-sm btn-danger-outline" data-api-acao="/api/integracoes.php" data-acao="pagina_excluir" data-id="<?= $pid ?>" data-confirmar="Remover a página e apagar o token? Novos leads dela deixam de chegar.">Remover</button>
        </div>

        <div class="subhead">Formulários → empreendimento</div>
        <?php if ($forms): ?>
            <div class="table-wrap"><table class="report-table int-forms">
                <thead><tr><th>Formulário</th><th>Empreendimento (roleta)</th><th>Ativo</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($forms as $f): $fid = (int) $f['id']; ?>
                    <tr>
                        <td><b><?= e($f['nome'] ?: 'Sem nome') ?></b><br><span class="muted">ID <?= e($f['form_id']) ?></span></td>
                        <td>
                            <form data-api-form action="/api/integracoes.php" class="inline-pair" id="form-map-<?= $fid ?>">
                                <input type="hidden" name="acao" value="form_mapear"><input type="hidden" name="id" value="<?= $fid ?>">
                                <label class="sr-only" for="map-emp-<?= $fid ?>">Empreendimento</label>
                                <select class="select-filter" id="map-emp-<?= $fid ?>" name="empreendimento_id">
                                    <option value="">Sem empreendimento (fila geral)</option>
                                    <?php foreach ($empreendimentos as $emp): ?><option value="<?= (int) $emp['id'] ?>" <?= (int) $f['empreendimento_id'] === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['nome']) ?></option><?php endforeach; ?>
                                </select>
                                <input type="hidden" name="ativo" value="<?= (int) $f['ativo'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm">Salvar</button>
                            </form>
                        </td>
                        <td><span class="pill <?= (int) $f['ativo'] ? 'pill-green' : 'pill-muted' ?>"><?= (int) $f['ativo'] ? 'Sim' : 'Não' ?></span></td>
                        <td class="col-acoes">
                            <button type="button" class="link-btn" data-api-acao="/api/integracoes.php" data-acao="form_mapear" data-id="<?= $fid ?>" data-empreendimento_id="<?= (int) $f['empreendimento_id'] ?: '' ?>" data-ativo="<?= (int) $f['ativo'] ? '0' : '1' ?>"><?= (int) $f['ativo'] ? 'Desativar' : 'Ativar' ?></button>
                            <button type="button" class="link-btn remove-link" data-api-acao="/api/integracoes.php" data-acao="form_excluir" data-id="<?= $fid ?>" data-confirmar="Remover este formulário?">Remover</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <p class="empty-note">Nenhum formulário ainda. Use “Buscar formulários” ou cadastre pelo ID abaixo.</p>
        <?php endif; ?>
        <form data-api-form action="/api/integracoes.php" class="int-form-manual">
            <input type="hidden" name="acao" value="form_salvar"><input type="hidden" name="pagina_id" value="<?= $pid ?>">
            <div class="field"><label for="fm-id-<?= $pid ?>">ID do formulário</label><input type="text" inputmode="numeric" id="fm-id-<?= $pid ?>" name="form_id" required maxlength="40" placeholder="Ex.: 1234567890"></div>
            <div class="field"><label for="fm-nome-<?= $pid ?>">Nome (opcional)</label><input type="text" id="fm-nome-<?= $pid ?>" name="nome" maxlength="190"></div>
            <div class="field"><label for="fm-emp-<?= $pid ?>">Empreendimento</label>
                <select id="fm-emp-<?= $pid ?>" name="empreendimento_id"><option value="">Sem empreendimento</option><?php foreach ($empreendimentos as $emp): ?><option value="<?= (int) $emp['id'] ?>"><?= e($emp['nome']) ?></option><?php endforeach; ?></select></div>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-outline"><?= icon('plus') ?>Adicionar formulário</button>
        </form>
    </div>

    <div class="modal-overlay" id="modal-token-<?= $pid ?>" hidden>
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="titulo-token-<?= $pid ?>">
            <button type="button" class="overlay-close" data-close-modal="modal-token-<?= $pid ?>" aria-label="Fechar">&times;</button>
            <h2 id="titulo-token-<?= $pid ?>">Trocar token da página</h2>
            <form data-api-form action="/api/integracoes.php">
                <input type="hidden" name="acao" value="pagina_salvar"><input type="hidden" name="id" value="<?= $pid ?>">
                <div class="field"><label for="tk-<?= $pid ?>">Novo token de acesso da página</label><input type="password" id="tk-<?= $pid ?>" name="access_token" required autocomplete="off" minlength="20" maxlength="1000"><small class="field-hint">Use um token de página de longa duração. Ele é cifrado e nunca é mostrado de novo.</small></div>
                <div class="form-alert" data-form-alerta hidden></div>
                <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar token</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<div class="card" id="eventos">
    <div class="card-head"><div><h2>Registro de eventos</h2><p>Cada aviso recebido da Meta, do mais recente para o mais antigo.</p></div></div>
    <form class="filters-row" method="get" action="#eventos">
        <label class="sr-only" for="ev-status">Status</label>
        <select class="select-filter" id="ev-status" name="status" onchange="this.form.submit()">
            <option value="">Todos os status</option>
            <?php foreach ($statusEventos as $s => [$rot]): ?><option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?>
        </select>
        <?php if ($paginas): ?>
            <label class="sr-only" for="ev-pagina">Página</label>
            <select class="select-filter" id="ev-pagina" name="pagina" onchange="this.form.submit()">
                <option value="">Todas as páginas</option>
                <?php foreach ($paginas as $pg): ?><option value="<?= e($pg['page_id']) ?>" <?= $fPagina === $pg['page_id'] ? 'selected' : '' ?>><?= e($pg['nome'] ?: $pg['page_id']) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
        <?php if ($fStatus || $fPagina): ?><a class="link-btn" href="/pages/integracoes.php#eventos">Limpar</a><?php endif; ?>
    </form>
    <?php if ($eventos): ?>
        <div class="table-wrap"><table class="report-table int-eventos">
            <thead><tr><th>Recebido</th><th>Página / formulário</th><th>Status</th><th>Detalhe</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($eventos as $ev): [$rot, $pill] = $statusEventos[$ev['status']]; ?>
                <tr>
                    <td><?= e(date('d/m H:i', strtotime($ev['recebido_em']))) ?></td>
                    <td><?= e($ev['pagina_nome'] ?: $ev['page_id']) ?><br><span class="muted"><?= e($ev['form_nome'] ?: 'Form ' . $ev['form_id']) ?> · cadastro <?= e($ev['leadgen_id']) ?></span></td>
                    <td><span class="pill <?= $pill ?>"><?= e($rot) ?></span><?= (int) $ev['tentativas'] > 1 ? '<br><span class="muted">' . (int) $ev['tentativas'] . ' tentativas</span>' : '' ?></td>
                    <td>
                        <?php if ($ev['lead_id'] && $ev['lead_nome'] !== null && rbac_pode_ver_lead($usuario, (int) $ev['lead_id'])): ?><a class="lead-link" href="/pages/lead.php?id=<?= (int) $ev['lead_id'] ?>"><?= e($ev['lead_nome']) ?></a><br><?php endif; ?>
                        <?php if ($ev['erro']): ?><span class="<?= $ev['status'] === 'erro' ? 'txt-late' : 'muted' ?>"><?= e($ev['erro']) ?></span><?php endif; ?>
                        <?php if ($ev['status'] === 'erro' && $ev['proxima_tentativa_em']): ?><br><span class="muted">Nova tentativa <?= e(date('d/m H:i', strtotime($ev['proxima_tentativa_em']))) ?></span><?php elseif ($ev['status'] === 'erro'): ?><br><span class="muted">Tentativas automáticas esgotadas</span><?php endif; ?>
                    </td>
                    <td class="col-acoes"><?php if (in_array($ev['status'], ['erro', 'pendente'], true)): ?><button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/integracoes.php" data-acao="evento_reprocessar" data-id="<?= (int) $ev['id'] ?>">Reprocessar</button><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php else: ?>
        <p class="empty-note"><?= $fStatus || $fPagina ? 'Nenhum evento com esses filtros.' : 'Nenhum aviso recebido da Meta até agora.' ?></p>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="modal-pagina" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="titulo-pagina">
        <button type="button" class="overlay-close" data-close-modal="modal-pagina" aria-label="Fechar">&times;</button>
        <h2 id="titulo-pagina">Conectar página do Facebook</h2>
        <form data-api-form action="/api/integracoes.php">
            <input type="hidden" name="acao" value="pagina_salvar">
            <div class="field"><label for="pg-id">ID da página</label><input type="text" inputmode="numeric" id="pg-id" name="page_id" required maxlength="40" placeholder="Ex.: 102030405060708"><small class="field-hint">Página → Sobre → Transparência da página, ou no Gerenciador de Negócios.</small></div>
            <div class="field"><label for="pg-nome">Nome (opcional)</label><input type="text" id="pg-nome" name="nome" maxlength="190" placeholder="Preenchido sozinho ao testar a conexão"></div>
            <div class="field"><label for="pg-token">Token de acesso da página</label><input type="password" id="pg-token" name="access_token" required autocomplete="off" minlength="20" maxlength="1000"><small class="field-hint">Token de página de longa duração com leads_retrieval e pages_manage_metadata. Fica cifrado no banco e nunca é exibido.</small></div>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar página</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
