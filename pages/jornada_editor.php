<?php
/**
 * Editor visual das Jornadas Inteligentes (Fase 7). Blocos conectados
 * (Gatilho → Mensagem → Espera → Condição → Ação → Fim), sem programar.
 * Toda regra (validação, simulação) mora em includes/jornadas_editor.php;
 * esta página só monta a tela — o JS (assets/js/jornada_editor.js) desenha
 * os blocos e manda a definição para api/jornadas.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/jornadas_editor.php';
require_once __DIR__ . '/../includes/lead_componentes.php';

$usuario = auth_require();
$crumb = 'Editor de jornada';
$activeNav = 'whatsapp';
if (!usuario_tem_permissao($usuario, 'gerenciar_jornadas')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Criar e ativar Jornadas Inteligentes');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$id = (int) ($_GET['id'] ?? 0);
if ($id) {
    $def = jornada_definicao($empresaId, $id);
    if (!$def) {
        http_response_code(404);
        pagina_acesso_restrito($crumb, $activeNav, 'Jornada não encontrada');
    }
} else {
    // Ponto de partida de uma jornada nova: mensagem → espera → fim.
    $def = ['id' => null, 'nome' => '', 'descricao' => '', 'status' => 'rascunho', 'gatilho' => 'novo_lead',
            'gatilho_config' => ['origens' => ['meta_ads'], 'parar_quando_responder' => true], 'empreendimento_id' => null, 'max_mensagens' => 2,
            'passos' => [
                ['chave' => 'mensagem_1', 'tipo' => 'enviar_template', 'titulo' => 'Mensagem', 'config' => ['template' => 'fixo', 'template_id' => null, 'ignorar_horario' => false, 'se_bloqueado' => 'seguir'], 'proximo_chave' => null, 'senao_chave' => null],
                ['chave' => 'espera_1', 'tipo' => 'esperar', 'titulo' => 'Esperar 1 dia', 'config' => ['horas' => 24], 'proximo_chave' => null, 'senao_chave' => null],
                ['chave' => 'fim', 'tipo' => 'encerrar', 'titulo' => 'Fim', 'config' => new stdClass(), 'proximo_chave' => null, 'senao_chave' => null],
            ]];
}

$stmt = db()->prepare("SELECT id, nome, categoria, corpo FROM whatsapp_templates WHERE empresa_id = :e AND status = 'aprovado' ORDER BY nome");
$stmt->execute(['e' => $empresaId]);
$templates = $stmt->fetchAll();
$stmt = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :e AND ativo = 1 ORDER BY nome');
$stmt->execute(['e' => $empresaId]);
$empreendimentos = $stmt->fetchAll();
$etapas = array_map(fn ($e) => ['id' => (int) $e['id'], 'nome' => $e['nome'], 'categoria' => $e['categoria'],
                                'encerrada' => in_array($e['categoria'], funil_categorias_encerradas(), true)], funil_etapas($empresaId));
$origens = lead_origens();
unset($origens['importacao']);
$dados = [
    'def' => $def, 'templates' => $templates, 'empreendimentos' => $empreendimentos, 'etapas' => $etapas,
    'origens' => $origens, 'tipos' => jornada_tipos_bloco(), 'gatilhos' => jornada_gatilhos(), 'regras' => jornada_regras_condicao(),
    'categorias' => array_diff_key(funil_categorias(), array_flip(funil_categorias_encerradas())),
    'padrao' => in_array($def['nome'], [JORNADA_BOAS_VINDAS, JORNADA_FOLLOW_UP], true),
];
$statusJ = ['ativa' => ['Ligada', 'pill-green'], 'pausada' => ['Pausada', 'pill-amber'], 'rascunho' => ['Desligada', 'pill-muted'], 'desativada' => ['Desativada', 'pill-muted']];
[$stRot, $stPill] = $statusJ[$def['status']] ?? ['—', 'pill-muted'];

$pageTitle = ($def['nome'] ?: 'Nova jornada') . ' — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<a class="back-link" href="/pages/whatsapp.php?aba=jornadas">← Voltar para as jornadas</a>
<div class="header">
    <div>
        <div class="eyebrow">Jornadas Inteligentes</div>
        <h1><?= e($def['nome'] ?: 'Nova jornada') ?> <span class="pill <?= $stPill ?>" style="vertical-align:middle;"><?= $stRot ?></span></h1>
        <p>Monte o caminho do lead com blocos. Antes de ligar, teste com dados fictícios ao lado — nada é enviado no teste.</p>
    </div>
</div>

<?php if ($def['status'] === 'ativa'): ?>
    <div class="alert-strip je-ligada">
        <?= icon('alert') ?><span>Esta jornada está <b>ligada</b>. Para mudar os blocos, pause primeiro — os leads que estão nela ficam parados e continuam depois.</span>
        <button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/jornadas.php" data-acao="status" data-id="<?= (int) $def['id'] ?>" data-status="pausada">Pausar para editar</button>
    </div>
<?php endif; ?>

<div class="je-layout" id="jornada-editor" data-somente-leitura="<?= $def['status'] === 'ativa' ? '1' : '0' ?>">
    <div class="je-principal">
        <div class="card">
            <div class="card-head"><div><h2>1. Nome e quando começa</h2></div></div>
            <div class="form-grid">
                <div class="field"><label for="je-nome">Nome da jornada</label><input type="text" id="je-nome" maxlength="120" <?= $dados['padrao'] ? 'readonly' : '' ?>></div>
                <div class="field"><label for="je-gatilho">Começa quando</label><select id="je-gatilho"></select></div>
            </div>
            <div class="field"><label for="je-descricao">Para que serve <small class="muted">(opcional)</small></label><input type="text" id="je-descricao" maxlength="500"></div>
            <div class="field" id="je-origens-box"><span class="label-like">Quais leads entram (origem)</span><div id="je-origens"></div>
                <small class="field-hint">Listas importadas não entram em jornadas: use campanhas, que exigem autorização de cada contato.</small></div>
            <div class="field" id="je-etapa-box" hidden><label for="je-etapa-gatilho">Etapa do Kanban</label><select id="je-etapa-gatilho"></select></div>
            <div class="form-grid">
                <div class="field"><label for="je-emp">Empreendimento</label><select id="je-emp"></select></div>
                <div class="field"><label for="je-max">Máximo de mensagens por lead</label><input type="number" id="je-max" min="1" max="5"></div>
            </div>
            <label class="checkbox-row"><input type="checkbox" id="je-parar"> Parar quando o cliente responder (o atendimento humano assume)</label>
            <div class="field" style="margin-top:10px;"><span class="label-like">Só enquanto o lead estiver em <small class="muted">(vazio = qualquer etapa aberta)</small></span><div id="je-categorias"></div></div>
        </div>

        <div class="card">
            <div class="card-head"><div><h2>2. Blocos</h2><p>De cima para baixo. Cada bloco acontece no máximo uma vez por lead.</p></div></div>
            <div class="je-fluxo" id="je-fluxo" aria-live="polite"></div>
        </div>

        <div class="je-salvar">
            <div class="form-alert" id="je-erros" hidden></div>
            <div class="je-avisos" id="je-avisos" hidden></div>
            <button type="button" class="btn btn-primary btn-lg" id="je-btn-salvar"><?= icon('check') ?>Salvar jornada</button>
        </div>
    </div>

    <aside class="je-lateral">
        <div class="card je-sim">
            <div class="card-head"><div><h2>Testar com dados fictícios</h2><p>Veja o que aconteceria com um lead. Nada é salvo ou enviado.</p></div></div>
            <div class="field"><label for="sim-consent">O cliente autorizou</label>
                <select id="sim-consent"><option value="atendimento">Contato sobre o atendimento</option><option value="marketing">Atendimento e ofertas (marketing)</option><option value="nenhum">Nada (sem autorização)</option></select></div>
            <div class="field"><label for="sim-responde">O cliente</label>
                <select id="sim-responde"><option value="nunca">Não responde</option><option value="apos_primeira_mensagem">Responde depois da primeira mensagem</option></select></div>
            <label class="checkbox-row"><input type="checkbox" id="sim-corretor" checked> O lead já tem corretor</label>
            <label class="checkbox-row"><input type="checkbox" id="sim-tenta" checked> O corretor registrou tentativa de contato</label>
            <label class="checkbox-row"><input type="checkbox" id="sim-confirma"> O corretor responde “Sim, o cliente respondeu”</label>
            <label class="checkbox-row"><input type="checkbox" id="sim-emp-tpl" checked> O empreendimento tem mensagem de boas-vindas</label>
            <label class="checkbox-row"><input type="checkbox" id="sim-book" checked> O empreendimento tem book</label>
            <button type="button" class="btn btn-secondary" id="je-btn-simular" style="margin-top:12px;"><?= icon('zap') ?>Simular</button>
            <div id="je-sim-resultado" class="je-sim-resultado" hidden></div>
        </div>
    </aside>
</div>

<script type="application/json" id="je-dados"><?= json_encode($dados, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= asset('/assets/js/jornada_editor.js') ?>" defer></script>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
