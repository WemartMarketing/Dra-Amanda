<?php
/**
 * Inteligência de Vendas → Importar Vendas.
 * Passo 1: enviar a planilha. Passo 2: conferir colunas e a prévia
 * (válidas, repetidas, já importadas, inconsistentes, recusadas). Passo 3:
 * confirmar → página do lote. Embaixo, o histórico de importações.
 * A prévia NÃO mostra dados pessoais (nome, telefone, e-mail, CPF).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/vendas/importacao.php';

$usuario = auth_require();
$crumb = 'Importar vendas';
$activeNav = 'relatorios';
if (!empty($usuario['eh_superadmin']) || !usuario_tem_permissao($usuario, 'importar_vendas')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Importar planilhas de vendas');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$st = db()->prepare('SELECT nome FROM empresas WHERE id = :e');
$st->execute(['e' => $empresaId]);
$empresaNome = (string) $st->fetchColumn();
$instalado = vendas_instalado();

$passo = 1;
$erroPasso = null;
$token = (string) ($_GET['t'] ?? '');
if ($token !== '' && $instalado) {
    $s = $_SESSION['vendas_importacoes'][$token] ?? null;
    $caminho = $s ? importacao_caminho($token, $s['ext']) : null;
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || !$s || (int) $s['empresa_id'] !== $empresaId || (int) $s['usuario_id'] !== (int) $usuario['id']) {
        $erroPasso = 'Importação não encontrada ou expirada. Envie a planilha de novo.';
    } elseif (!$caminho || !is_file($caminho)) {
        $erroPasso = 'O arquivo temporário expirou (1 hora). Envie a planilha de novo.';
    } else {
        $leitura = vendas_ler_arquivo($caminho, $s['ext']);
        if (!$leitura['ok']) {
            $erroPasso = $leitura['erro'];
        } else {
            $passo = 2;
            $cab = $leitura['cabecalho'];
            $mapa = $s['mapa'];
            [, $erroMapa] = vendas_mapa_validar(array_map('strval', $mapa), count($cab));
            $situacoesPadrao = ['vendido'];
            $an = $erroMapa ? null : vendas_analisar($empresaId, $s['sistema'], $leitura, $mapa, $situacoesPadrao);
            $mesmo = vendas_lote_mesmo_arquivo($empresaId, hash_file('sha256', $caminho));
            $mCab = vendas_mapear_cabecalho($cab);
        }
    }
}
$lotes = $instalado ? vendas_lotes_listar($empresaId, 30) : [];
$rotRes = ['incluida' => ['pill-green', 'Nova'], 'atualizada' => ['pill-blue', 'Alterada'], 'sem_alteracao' => ['pill-muted', 'Já importada'],
           'ignorada' => ['pill-amber', 'Ignorada'], 'erro' => ['pill-red', 'Recusada']];
$brl = fn ($v) => $v === null ? '—' : 'R$ ' . number_format((float) $v, 2, ',', '.');

$pageTitle = 'Importar vendas — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <a class="back-link" href="/pages/relatorios_central.php"><?= icon('arrow-r') ?>Voltar para os relatórios</a>
        <div class="eyebrow">Relatórios · Inteligência de Vendas</div>
        <h1>Importar vendas</h1>
        <p>Traga a planilha de vendas do sistema comercial para conciliar com os leads do CRM. A importação <b>não</b> cria leads, não muda o Kanban, não troca responsáveis e não dispara mensagens.</p>
    </div>
</div>

<?php if (!$instalado): ?>
    <div class="card stub-card"><div class="stub-icon"><?= icon('alert') ?></div><h2>Em atualização</h2><p>Rode a migração 017 para importar vendas.</p></div>
<?php else: ?>

<ol class="passos" aria-label="Etapas da importação">
    <li class="<?= $passo === 1 ? 'is-atual' : 'is-feito' ?>"><span>1</span>Enviar arquivo</li>
    <li class="<?= $passo === 2 ? 'is-atual' : '' ?>"><span>2</span>Conferir e confirmar</li>
    <li><span>3</span>Resultado do lote</li>
</ol>

<?php if ($erroPasso): ?>
    <div class="alert-strip" role="alert"><?= icon('alert') ?><span><?= e($erroPasso) ?></span><a class="link-btn" href="/pages/vendas_importar.php">Recomeçar</a></div>
<?php endif; ?>

<?php if ($passo === 1): ?>
    <div class="grid-2">
        <div class="card form-card">
            <div class="card-head"><div><h2>1. Escolha a planilha</h2><p>XLSX ou CSV, até 5 MB e 5.000 linhas. A primeira linha deve ter os nomes das colunas.</p></div></div>
            <form data-api-form action="/api/vendas_importar.php" data-redirecionar="/pages/vendas_importar.php?t={id}" enctype="multipart/form-data">
                <input type="hidden" name="acao" value="enviar">
                <div class="form-grid">
                    <div class="field"><label>Empresa</label><input type="text" value="<?= e($empresaNome) ?>" readonly aria-readonly="true"><small class="field-hint">Sempre a empresa em que você está trabalhando agora.</small></div>
                    <div class="field"><label for="v-sistema">Sistema de origem</label><select id="v-sistema" name="sistema"><?php foreach (vendas_sistemas() as $k => $r): ?><option value="<?= e($k) ?>"><?= e($r) ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="upload-box">
                    <label class="file-btn btn btn-outline" for="arquivo"><?= icon('upload') ?>Escolher arquivo</label>
                    <input type="file" id="arquivo" name="arquivo" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required data-limite="<?= IMPORTACAO_MAX_BYTES ?>" class="sr-only" onchange="this.form.querySelector('.file-name').textContent = this.files[0] ? this.files[0].name : 'Nenhum arquivo escolhido'">
                    <span class="file-name">Nenhum arquivo escolhido</span>
                </div>
                <div class="form-alert" data-form-alerta hidden></div>
                <button type="submit" class="btn btn-primary btn-lg"><?= icon('arrow-r') ?>Enviar e ver a prévia</button>
            </form>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Como funciona</h2></div></div>
            <ul class="regra-lista">
                <li class="is-ok"><?= icon('check') ?>As colunas da planilha da HM são reconhecidas sozinhas; você pode ajustar.</li>
                <li class="is-ok"><?= icon('check') ?>Antes de gravar, você vê quantas linhas são novas, alteradas, já importadas, repetidas, inconsistentes e recusadas.</li>
                <li class="is-ok"><?= icon('check') ?>Enviar o mesmo arquivo de novo não duplica nada: a chave é o "id" da venda (ou a "Venda RM").</li>
                <li class="is-ok"><?= icon('check') ?>Venda que mudou na origem é atualizada só com a sua autorização, guardando o antes e o depois.</li>
                <li class="is-ok"><?= icon('check') ?>A "data de cadastro" do sistema externo é guardada, mas nunca substitui a entrada do lead no CRM.</li>
            </ul>
            <div class="rule-box lgpd-box"><?= icon('shield') ?><span><b>LGPD:</b> só guardamos o necessário para conciliar. Endereço, nascimento, renda, profissão, 2º comprador e fiador <b>não</b> são importados; o CPF é guardado apenas codificado (com os 2 últimos dígitos). O arquivo original fica protegido por <?= VENDAS_RETENCAO_DIAS ?> dias, para reprocessar, e depois é apagado. Esses contatos <b>não</b> recebem campanhas.</span></div>
        </div>
    </div>

<?php elseif ($passo === 2): ?>
    <?php if ($mesmo): ?>
        <div class="rel-nota" role="note"><?= icon('info') ?><span>Este mesmo arquivo já foi importado em <?= e(date('d/m/Y H:i', strtotime($mesmo['criado_em']))) ?> (<a href="/pages/vendas_lote.php?id=<?= (int) $mesmo['id'] ?>">lote #<?= (int) $mesmo['id'] ?></a>). Importar de novo não duplica: as vendas aparecem como "já importadas".</span></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-head">
            <div><h2>2. Conferir</h2><p><b><?= e($s['nome']) ?></b> · <?= count($leitura['linhas']) ?> linha(s) · <?= count($cab) ?> coluna(s) · <?= e(vendas_sistemas()[$s['sistema']] ?? $s['sistema']) ?> · empresa <b><?= e($empresaNome) ?></b></p></div>
            <a class="btn btn-outline btn-sm" href="/pages/vendas_importar.php">Trocar arquivo</a>
        </div>
        <?php if ($an): $c = $an['contagens']; ?>
            <div class="kpi-grid vendas-kpis">
                <div class="kpi"><div class="kpi-top"><span class="label">Novas</span><div class="kpi-icon"><?= icon('plus') ?></div></div><div class="kpi-value txt-ok"><?= $c['incluida'] ?></div><div class="kpi-sub">serão incluídas</div></div>
                <div class="kpi"><div class="kpi-top"><span class="label">Alteradas na origem</span><div class="kpi-icon"><?= icon('edit') ?></div></div><div class="kpi-value"><?= $c['atualizada'] ?></div><div class="kpi-sub">já importadas, com mudanças</div></div>
                <div class="kpi"><div class="kpi-top"><span class="label">Já importadas</span><div class="kpi-icon"><?= icon('check') ?></div></div><div class="kpi-value"><?= $c['sem_alteracao'] ?></div><div class="kpi-sub">iguais: nada muda</div></div>
                <div class="kpi"><div class="kpi-top"><span class="label">Repetidas no arquivo</span><div class="kpi-icon"><?= icon('users') ?></div></div><div class="kpi-value"><?= $c['repetidas_no_arquivo'] ?></div><div class="kpi-sub">mesmo id; só a 1ª vale</div></div>
                <div class="kpi"><div class="kpi-top"><span class="label">Inconsistentes</span><div class="kpi-icon"><?= icon('info') ?></div></div><div class="kpi-value txt-warn"><?= $c['com_alerta'] ?></div><div class="kpi-sub">importadas com alerta</div></div>
                <div class="kpi"><div class="kpi-top"><span class="label">Recusadas</span><div class="kpi-icon"><?= icon('alert') ?></div></div><div class="kpi-value<?= $c['erro'] ? ' txt-bad' : '' ?>"><?= $c['erro'] ?></div><div class="kpi-sub">não serão gravadas</div></div>
            </div>
            <?php if ($an['motivos_erro'] || $an['motivos_alerta']): ?>
                <div class="grid-2 vendas-motivos">
                    <?php if ($an['motivos_erro']): ?><div><div class="subhead">Por que linhas serão recusadas</div><ul class="regra-lista"><?php foreach ($an['motivos_erro'] as $mtv => $q): ?><li class="is-no"><?= icon('x') ?><span><?= e($mtv) ?> <b><?= (int) $q ?></b></span></li><?php endforeach; ?></ul></div><?php endif; ?>
                    <?php if ($an['motivos_alerta']): ?><div><div class="subhead">Inconsistências (a linha entra, com alerta)</div><ul class="regra-lista"><?php foreach ($an['motivos_alerta'] as $mtv => $q): ?><li class="is-warn"><?= icon('info') ?><span><?= e($mtv) ?> <b><?= (int) $q ?></b></span></li><?php endforeach; ?></ul></div><?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert-strip" role="alert"><?= icon('alert') ?><span><?= e($erroMapa) ?> Ajuste as colunas abaixo.</span></div>
        <?php endif; ?>
    </div>

    <details class="card vendas-colunas"<?= $an ? '' : ' open' ?>>
        <summary><span><b>Colunas</b> · <?= count($mapa) ?> reconhecida(s), <?= count($mCab['minimizadas']) ?> não importada(s) por LGPD, <?= count($mCab['extras']) ?> guardada(s) como informação extra</span><span class="link-btn">Ajustar</span></summary>
        <form data-api-form action="/api/vendas_importar.php">
            <input type="hidden" name="acao" value="mapear"><input type="hidden" name="token" value="<?= e($token) ?>">
            <?php $grupos = ['chave' => 'Identificação da venda', 'venda' => 'Venda', 'pessoal' => 'Cliente (usado só para conciliar)', 'comercial' => 'Comercial', 'financeiro' => 'Financeiro']; ?>
            <?php foreach ($grupos as $g => $tituloG): ?>
                <div class="subhead"><?= e($tituloG) ?></div>
                <div class="map-grid">
                    <?php foreach (vendas_campos() as $campo => [, $cat, $rot]): if ($cat !== $g) continue; $obrig = in_array($campo, ['data_venda', 'empreendimento'], true); ?>
                        <div class="field"><label for="vc-<?= $campo ?>"><?= e($rot) ?><?= $obrig ? ' *' : '' ?></label>
                            <select id="vc-<?= $campo ?>" name="col_<?= $campo ?>">
                                <option value="">Não importar</option>
                                <?php foreach ($cab as $i => $rotC): ?><option value="<?= $i ?>" <?= ($mapa[$campo] ?? null) === $i ? 'selected' : '' ?>><?= e($rotC !== '' ? $rotC : 'Coluna ' . ($i + 1)) ?></option><?php endforeach; ?>
                            </select></div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($mCab['minimizadas']): ?><p class="muted"><b>Não importadas (LGPD, sem uso na conciliação):</b> <?= e(implode(', ', $mCab['minimizadas'])) ?>.</p><?php endif; ?>
            <?php if ($mCab['extras']): ?><p class="muted"><b>Guardadas como informação extra da venda:</b> <?= e(implode(', ', $mCab['extras'])) ?>.</p><?php endif; ?>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-outline"><?= icon('check') ?>Aplicar e recalcular a prévia</button>
        </form>
    </details>

    <?php if ($an): ?>
        <div class="card">
            <div class="card-head"><div><h2>Prévia por linha</h2><p>Primeiro as recusadas, ignoradas e com alerta. Sem dados pessoais: só se o contato é utilizável no cruzamento.</p></div></div>
            <?php
            $ordem = ['erro' => 0, 'ignorada' => 1, 'atualizada' => 2, 'incluida' => 3, 'sem_alteracao' => 4];
            $linhasPrev = $an['linhas'];
            uksort($linhasPrev, function ($a, $b) use ($linhasPrev, $ordem) {
                $ka = [$ordem[$linhasPrev[$a]['resultado']], $linhasPrev[$a]['alertas'] ? 0 : 1, $a];
                $kb = [$ordem[$linhasPrev[$b]['resultado']], $linhasPrev[$b]['alertas'] ? 0 : 1, $b];
                return $ka <=> $kb;
            });
            $linhasPrev = array_slice($linhasPrev, 0, 100, true);
            ?>
            <div class="table-wrap"><table class="report-table vendas-previa">
                <thead><tr><th scope="col" class="num">Linha</th><th scope="col">Id / Venda RM</th><th scope="col">Data da venda</th><th scope="col">Empreendimento</th><th scope="col" class="num">Valor</th><th scope="col">Situação</th><th scope="col">Contato p/ cruzar</th><th scope="col">Resultado</th><th scope="col">Observação</th></tr></thead>
                <tbody>
                <?php foreach ($linhasPrev as $n => $r): $reg = $r['registro']; [$pc, $pr] = $rotRes[$r['resultado']]; ?>
                    <tr>
                        <td class="num" data-rotulo="Linha"><?= (int) $n ?></td>
                        <td data-rotulo="Id / Venda RM"><?= e($r['chave'] ?? '—') ?><?= $reg && $reg['venda_rm'] ? ' <small class="muted">/ ' . e($reg['venda_rm']) . '</small>' : '' ?></td>
                        <td data-rotulo="Data"><?= $reg && $reg['data_venda'] ? date('d/m/Y', strtotime($reg['data_venda'])) : '—' ?></td>
                        <td data-rotulo="Empreendimento"><?= e($reg['empreendimento_texto'] ?? '—') ?></td>
                        <td class="num" data-rotulo="Valor"><?= $reg ? e($brl($reg['valor'])) : '—' ?></td>
                        <td data-rotulo="Situação"><?= e($reg['situacao'] ?? '—') ?></td>
                        <td data-rotulo="Contato"><?php if ($reg): ?><span class="vendas-contato<?= $reg['telefone1'] ? ' is-ok' : '' ?>">Telefone</span> <span class="vendas-contato<?= $reg['email'] ? ' is-ok' : '' ?>">E-mail</span><?php else: ?>—<?php endif; ?></td>
                        <td data-rotulo="Resultado"><span class="pill <?= $pc ?>"><?= e($pr) ?></span></td>
                        <td data-rotulo="Observação"><small><?= e(trim(($r['motivo'] ?? '') . ' ' . implode(' ', $r['alertas']))) ?: '—' ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if (count($an['linhas']) > 100): ?><p class="muted">Mostrando 100 de <?= count($an['linhas']) ?> linhas. Todas aparecem na página do lote depois de importar.</p><?php endif; ?>
        </div>

        <form class="card" data-api-form action="/api/vendas_importar.php" data-redirecionar="/pages/vendas_lote.php?id={id}">
            <input type="hidden" name="acao" value="confirmar"><input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="card-head"><div><h2>3. Confirmar</h2><p>Nada foi gravado até aqui.</p></div></div>
            <fieldset class="rel-exp-opcoes"><legend>Quais situações representam venda concluída?</legend>
                <?php foreach ($an['situacoes'] as $sit => $q): ?>
                    <label class="radio-row"><input type="checkbox" name="situacoes[]" value="<?= e($sit) ?>" <?= in_array($sit, $situacoesPadrao, true) ? 'checked' : '' ?>> <?= e($sit === '(vazia)' ? 'Sem situação informada' : mb_convert_case($sit, MB_CASE_TITLE)) ?> <span class="muted">(<?= (int) $q ?>)</span></label>
                <?php endforeach; ?>
                <small class="field-hint">As outras situações são guardadas, mas não entram nos indicadores de venda.</small>
            </fieldset>
            <?php if ($an['contagens']['atualizada']): ?>
                <label class="radio-row"><input type="checkbox" name="atualizar_alteradas" value="1" checked> Atualizar as <?= (int) $an['contagens']['atualizada'] ?> venda(s) que mudaram na origem (o antes e o depois ficam no histórico)</label>
            <?php endif; ?>
            <div class="form-alert" data-form-alerta hidden></div>
            <div class="modal-actions">
                <a href="/pages/vendas_importar.php" class="btn btn-outline">Cancelar</a>
                <button type="submit" class="btn btn-primary btn-lg"<?= $an['contagens']['incluida'] + $an['contagens']['atualizada'] ? '' : ' disabled' ?>><?= icon('check') ?>Importar <?= (int) ($an['contagens']['incluida'] + $an['contagens']['atualizada']) ?> venda(s)</button>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>

<div class="card dash-section">
    <div class="card-head"><div><h2>Importações anteriores</h2><p>Cada envio vira um lote, com quem enviou, quando e o resultado de cada linha.</p></div></div>
    <?php if (!$lotes): ?>
        <p class="empty-note">Nenhuma importação ainda.</p>
    <?php else: ?>
        <div class="table-wrap"><table class="report-table">
            <thead><tr><th scope="col">Lote</th><th scope="col">Quando</th><th scope="col">Arquivo</th><th scope="col">Por</th><th scope="col" class="num">Linhas</th><th scope="col" class="num">Novas</th><th scope="col" class="num">Alteradas</th><th scope="col" class="num">Já importadas</th><th scope="col" class="num">Ignoradas</th><th scope="col" class="num">Recusadas</th><th scope="col">Situação</th></tr></thead>
            <tbody>
            <?php foreach ($lotes as $l): ?>
                <tr>
                    <td data-rotulo="Lote"><a href="/pages/vendas_lote.php?id=<?= (int) $l['id'] ?>">#<?= (int) $l['id'] ?></a><?= $l['reprocessa_lote_id'] ? ' <small class="muted">reprocessa #' . (int) $l['reprocessa_lote_id'] . '</small>' : '' ?></td>
                    <td data-rotulo="Quando"><?= date('d/m/Y H:i', strtotime($l['criado_em'])) ?></td>
                    <td data-rotulo="Arquivo"><?= e($l['arquivo_nome']) ?></td>
                    <td data-rotulo="Por"><?= e($l['usuario_nome'] ?? '—') ?></td>
                    <td class="num" data-rotulo="Linhas"><?= (int) $l['linhas_lidas'] ?></td>
                    <td class="num" data-rotulo="Novas"><?= (int) $l['incluidas'] ?></td>
                    <td class="num" data-rotulo="Alteradas"><?= (int) $l['atualizadas'] ?></td>
                    <td class="num" data-rotulo="Já importadas"><?= (int) $l['sem_alteracao'] ?></td>
                    <td class="num" data-rotulo="Ignoradas"><?= (int) $l['ignoradas'] ?></td>
                    <td class="num" data-rotulo="Recusadas"><?= (int) $l['com_erro'] ?></td>
                    <td data-rotulo="Situação"><?= $l['status'] === 'concluido' ? '<span class="pill pill-green">Concluído</span>' : ($l['status'] === 'falhou' ? '<span class="pill pill-red">Falhou</span>' : '<span class="pill pill-amber">Processando</span>') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
