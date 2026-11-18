<?php
/**
 * Inteligência de Vendas → Equivalências: como os nomes/códigos do sistema
 * comercial correspondem aos cadastros do WeMart. Toda equivalência é
 * confirmada por uma pessoa; sugestões nunca são aplicadas sozinhas.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/vendas/cruzamento.php';

$usuario = auth_require();
$crumb = 'Equivalências';
$activeNav = 'relatorios';
if (!empty($usuario['eh_superadmin']) || !usuario_tem_permissao($usuario, 'revisar_correspondencias')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Revisar correspondências de vendas e equivalências');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$instalado = vendas_cruzamento_instalado();
$abas = ['empreendimentos' => 'Empreendimentos', 'corretores' => 'Corretores', 'imobiliarias' => 'Imobiliárias parceiras'];
$aba = isset($abas[$_GET['aba'] ?? '']) ? $_GET['aba'] : 'empreendimentos';
$q = function (string $sql, array $p = []) {
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
};
$emps = $q('SELECT id, nome FROM empreendimentos WHERE empresa_id = :e ORDER BY nome', ['e' => $empresaId]);
$usuarios = $q('SELECT id, nome FROM usuarios WHERE empresa_id = :e AND ativo = 1 ORDER BY nome', ['e' => $empresaId]);
$imobs = $instalado ? $q('SELECT * FROM imobiliarias_parceiras WHERE empresa_id = :e ORDER BY ativa DESC, nome', ['e' => $empresaId]) : [];
$equiv = [];
if ($instalado) {
    foreach ($q('SELECT * FROM vendas_equivalencias WHERE empresa_id = :e', ['e' => $empresaId]) as $x) {
        $equiv[$x['tipo']][$x['valor_origem']] = $x;
    }
}
$itens = [];
if ($instalado && $aba === 'empreendimentos') {
    foreach ($q('SELECT empreendimento_texto t, COUNT(*) n FROM vendas_externas WHERE empresa_id = :e AND empreendimento_texto IS NOT NULL GROUP BY empreendimento_texto ORDER BY n DESC', ['e' => $empresaId]) as $r) {
        $k = vendas_nome_comparavel($r['t']);
        $itens[$k] ??= ['texto' => $r['t'], 'n' => 0, 'eq' => $equiv['empreendimento'][$k] ?? null, 'sug' => vendas_sugestao_empreendimento($empresaId, $r['t'])];
        $itens[$k]['n'] += (int) $r['n'];
    }
} elseif ($instalado && $aba === 'corretores') {
    foreach ($q('SELECT corretor_id_origem i, corretor_texto t, COUNT(*) n FROM vendas_externas WHERE empresa_id = :e AND (corretor_id_origem IS NOT NULL OR corretor_texto IS NOT NULL)
                 GROUP BY corretor_id_origem, corretor_texto ORDER BY n DESC', ['e' => $empresaId]) as $r) {
        $k = (string) ($r['i'] ?? ('nome:' . vendas_nome_comparavel((string) $r['t'])));
        $itens[$k] ??= ['texto' => (string) $r['t'], 'id' => $r['i'], 'n' => 0, 'eq' => $equiv['corretor'][$k] ?? null, 'sug' => vendas_sugestao_corretor($empresaId, (string) $r['t'])];
        $itens[$k]['n'] += (int) $r['n'];
    }
}
$pend = count(array_filter($itens, fn ($i) => !$i['eq']));
$nomeEmp = array_column($emps, 'nome', 'id');
$nomeUsr = array_column($usuarios, 'nome', 'id');

$pageTitle = 'Equivalências — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <a class="back-link" href="/pages/vendas_conciliacao.php"><?= icon('arrow-r') ?>Voltar para a conciliação</a>
        <div class="eyebrow">Inteligência de Vendas</div>
        <h1>Equivalências</h1>
        <p>Diga a que cadastro do WeMart cada nome do sistema comercial corresponde. Nada é ligado por semelhança de texto: sugestões aparecem só quando o nome é idêntico (sem acento e caixa) e precisam ser confirmadas.</p>
    </div>
</div>
<?php if (!$instalado): ?>
    <div class="card stub-card"><div class="stub-icon"><?= icon('alert') ?></div><h2>Em atualização</h2><p>Rode a migração 018.</p></div>
<?php else: ?>
<nav class="segmented-inline vendas-abas" aria-label="Tipo">
    <?php foreach ($abas as $k => $r): ?><a class="seg-link<?= $aba === $k ? ' is-active' : '' ?>" href="?aba=<?= $k ?>"><?= e($r) ?></a><?php endforeach; ?>
</nav>

<?php if ($aba !== 'imobiliarias'): ?>
    <div class="card">
        <div class="card-head"><div>
            <h2><?= $aba === 'empreendimentos' ? 'Empreendimentos da planilha' : 'Corretores da planilha' ?></h2>
            <p><?= count($itens) ?> encontrado(s) nas vendas importadas · <b><?= $pend ?></b> sem equivalência<?= $aba === 'corretores' ? '. A classificação (do CRM, externo ou não identificado) é usada na análise de "venda por outro canal"; o prefixo do nome (CIA_, CORRETOR_…) não decide nada.' : '.' ?></p>
        </div></div>
        <?php if (!$itens): ?>
            <p class="empty-note">Nenhuma venda importada ainda.</p>
        <?php else: ?>
            <div class="table-wrap"><table class="report-table vendas-equiv">
                <thead><tr><th scope="col">Na planilha</th><th scope="col" class="num">Vendas</th><th scope="col">Situação</th><th scope="col">Corresponde a</th></tr></thead>
                <tbody>
                <?php foreach ($itens as $k => $it): $eq = $it['eq']; ?>
                    <tr>
                        <td data-rotulo="Na planilha"><b><?= e($it['texto'] ?: '—') ?></b><?= $aba === 'corretores' && $it['id'] ? '<br><small class="muted">id ' . e($it['id']) . '</small>' : '' ?></td>
                        <td class="num" data-rotulo="Vendas"><?= (int) $it['n'] ?></td>
                        <td data-rotulo="Situação">
                            <?php if ($eq): ?>
                                <span class="pill pill-green">Confirmada</span><br><small class="muted"><?= e(match ($eq['classificacao']) {
                                    'crm' => $aba === 'empreendimentos' ? ($nomeEmp[$eq['empreendimento_id']] ?? '—') : 'Usuário: ' . ($nomeUsr[$eq['usuario_id']] ?? '—'),
                                    'nao_existe' => 'Não existe no CRM', 'externo' => 'Externo' . ($eq['imobiliaria_id'] ? ' · ' . (array_column($imobs, 'nome', 'id')[$eq['imobiliaria_id']] ?? '') : ''),
                                    default => 'Não identificado' }) ?></small>
                            <?php elseif ($it['sug']): ?><span class="pill pill-blue">Sugestão</span><br><small class="muted">nome idêntico: confirme</small>
                            <?php else: ?><span class="pill pill-amber">Pendente</span><?php endif; ?>
                        </td>
                        <td data-rotulo="Corresponde a">
                            <form data-api-form action="/api/vendas_conciliacao.php" class="vendas-equiv-form">
                                <input type="hidden" name="acao" value="equivalencia"><input type="hidden" name="tipo" value="<?= $aba === 'empreendimentos' ? 'empreendimento' : 'corretor' ?>">
                                <input type="hidden" name="valor_texto" value="<?= e($it['texto']) ?>"><input type="hidden" name="valor_origem" value="<?= e((string) $k) ?>">
                                <?php $atual = $eq ? ($eq['classificacao'] === 'crm' ? 'crm:' . ($eq['empreendimento_id'] ?? $eq['usuario_id']) : ($eq['classificacao'] === 'externo' ? 'externo:' . ($eq['imobiliaria_id'] ?? '') : $eq['classificacao'])) : ($it['sug'] ? 'crm:' . $it['sug'] : ''); ?>
                                <select name="alvo" required aria-label="Corresponde a">
                                    <option value="">Escolha…</option>
                                    <?php if ($aba === 'empreendimentos'): ?>
                                        <optgroup label="Empreendimento do CRM"><?php foreach ($emps as $e): ?><option value="crm:<?= (int) $e['id'] ?>" <?= $atual === 'crm:' . $e['id'] ? 'selected' : '' ?>><?= e($e['nome']) ?></option><?php endforeach; ?></optgroup>
                                        <option value="nao_existe" <?= $atual === 'nao_existe' ? 'selected' : '' ?>>Não existe no CRM</option>
                                    <?php else: ?>
                                        <optgroup label="Usuário do CRM"><?php foreach ($usuarios as $u): ?><option value="crm:<?= (int) $u['id'] ?>" <?= $atual === 'crm:' . $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option><?php endforeach; ?></optgroup>
                                        <optgroup label="Externo">
                                            <option value="externo:" <?= $atual === 'externo:' ? 'selected' : '' ?>>Externo (imobiliária não identificada)</option>
                                            <?php foreach ($imobs as $i): if (!$i['ativa']) continue; ?><option value="externo:<?= (int) $i['id'] ?>" <?= $atual === 'externo:' . $i['id'] ? 'selected' : '' ?>>Externo · <?= e($i['nome']) ?></option><?php endforeach; ?>
                                        </optgroup>
                                        <option value="nao_identificado" <?= $atual === 'nao_identificado' ? 'selected' : '' ?>>Não identificado</option>
                                    <?php endif; ?>
                                </select>
                                <button type="submit" class="btn btn-outline btn-sm"><?= $eq ? 'Salvar' : 'Confirmar' ?></button>
                                <div class="form-alert" data-form-alerta hidden></div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="grid-2">
        <div class="card">
            <div class="card-head"><div><h2>Imobiliárias parceiras</h2><p>Canais externos que vendem os empreendimentos. Usadas para classificar corretores externos.</p></div></div>
            <?php if (!$imobs): ?><p class="empty-note">Nenhuma cadastrada.</p><?php else: ?>
                <ul class="vendas-imobs">
                    <?php foreach ($imobs as $i): ?>
                        <li class="<?= $i['ativa'] ? '' : 'is-inativa' ?>"><span><?= e($i['nome']) ?><?= $i['ativa'] ? '' : ' <small class="muted">(inativa)</small>' ?></span>
                            <button type="button" class="link-btn" data-api-acao="/api/vendas_conciliacao.php" data-acao="imobiliaria_ativa" data-id="<?= (int) $i['id'] ?>" data-ativa="<?= $i['ativa'] ? '' : '1' ?>"><?= $i['ativa'] ? 'Desativar' : 'Reativar' ?></button></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <form class="card" data-api-form action="/api/vendas_conciliacao.php">
            <input type="hidden" name="acao" value="imobiliaria">
            <div class="card-head"><div><h2>Nova imobiliária</h2></div></div>
            <div class="field"><label for="imob-nome">Nome</label><input type="text" id="imob-nome" name="nome" required maxlength="190"></div>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-primary"><?= icon('plus') ?>Cadastrar</button>
        </form>
    </div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
