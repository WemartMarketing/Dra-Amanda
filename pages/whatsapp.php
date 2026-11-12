<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/whatsapp_conversas.php';

$usuario = auth_require();
$crumb = 'WhatsApp';
$activeNav = 'whatsapp';
if (!usuario_tem_permissao($usuario, 'whatsapp_configurar')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Configurar WhatsApp, templates e books');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$abas = ['conexao' => 'Conexão', 'templates' => 'Templates', 'atividade' => 'Atividade'];
$aba = array_key_exists($_GET['aba'] ?? '', $abas) ? $_GET['aba'] : 'conexao';

$conta = wa_conta_da_empresa($empresaId);
$cfg = wa_config($empresaId);
$plataformaOk = wa_plataforma_configurada();
$chaveOk = cripto_disponivel();
$empresaNome = wa_dados_lead($empresaId, null)['empresa.nome'] ?: 'Sua empresa';

$stmt = db()->prepare('SELECT * FROM whatsapp_templates WHERE empresa_id = :e ORDER BY status = \'aprovado\' DESC, nome');
$stmt->execute(['e' => $empresaId]);
$templates = $stmt->fetchAll();
$templatesTeste = array_filter($templates, fn ($t) => $t['status'] === 'aprovado' && $t['categoria'] !== 'marketing' && in_array($t['cabecalho_tipo'], ['nenhum', 'texto'], true));

$statusTpl = ['aprovado' => ['Aprovado', 'pill-green'], 'pendente' => ['Em análise na Meta', 'pill-amber'], 'rejeitado' => ['Rejeitado', 'pill-red'],
              'pausado' => ['Pausado pela Meta', 'pill-red'], 'desativado' => ['Desativado', 'pill-muted'], 'rascunho' => ['Rascunho', 'pill-muted']];
$categoriaTpl = ['marketing' => 'Marketing', 'utility' => 'Utilidade', 'authentication' => 'Autenticação'];
$finalidades = ['boas_vindas' => 'Boas-vindas a novo lead', 'follow_up' => 'Lembrete / follow-up', 'book' => 'Envio do book', 'reengajamento' => 'Retomar contato',
                'remarketing' => 'Remarketing (marketing)', 'outro' => 'Outro uso'];

if ($aba === 'atividade') {
    $stmt = db()->prepare('SELECT m.*, ct.telefone, ct.nome_perfil, cv.lead_id, l.nome AS lead_nome, u.nome AS usuario_nome
                           FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id
                           JOIN whatsapp_contatos ct ON ct.id = cv.contato_id
                           LEFT JOIN leads l ON l.id = cv.lead_id AND l.empresa_id = m.empresa_id
                           LEFT JOIN usuarios u ON u.id = m.enviada_por
                           WHERE m.empresa_id = :e ORDER BY m.id DESC LIMIT 40');
    $stmt->execute(['e' => $empresaId]);
    $mensagens = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM whatsapp_webhook_eventos WHERE empresa_id = :e ORDER BY id DESC LIMIT 40');
    $stmt->execute(['e' => $empresaId]);
    $eventos = $stmt->fetchAll();
    $fila = fila_resumo($empresaId);
}

function wa_tel_exibir(string $t): string
{
    if (preg_match('/^55(\d{2})(\d{4,5})(\d{4})$/', $t, $m)) {
        return '(' . $m[1] . ') ' . $m[2] . '-' . $m[3];
    }
    return '+' . $t;
}

$pageTitle = 'WhatsApp — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>

<div class="header">
    <div>
        <div class="eyebrow">Configurações</div>
        <h1>WhatsApp Business</h1>
        <p>Número oficial da empresa (Cloud API da Meta): mensagens automáticas, respostas dos clientes e confirmação de entrega.</p>
    </div>
</div>

<?php wa_render_abas($usuario, $aba); ?>

<?php if ($conta): $teste = (int) $conta['modo_teste'] === 1; ?>
    <div class="wa-modo <?= $teste ? 'is-teste' : ($conta['status'] === 'ativa' ? 'is-real' : 'is-parado') ?>" role="status">
        <?= icon($teste ? 'alert' : ($conta['status'] === 'ativa' ? 'check' : 'clock')) ?>
        <div>
            <?php if ($teste): ?>
                <b>Modo de teste ligado</b><span>Nenhuma mensagem é enviada de verdade: tudo fica registrado como “simulado”.</span>
            <?php elseif ($conta['status'] === 'ativa'): ?>
                <b>Envio real ligado</b><span>As mensagens saem pelo WhatsApp oficial de <?= e($conta['nome_verificado'] ?: $conta['numero_exibicao'] ?: 'sua empresa') ?>.</span>
            <?php else: ?>
                <b>WhatsApp <?= $conta['status'] === 'pausada' ? 'pausado' : 'com problema' ?></b><span><?= $conta['status'] === 'pausada' ? 'Nenhuma mensagem sai até ser retomado.' : e($conta['ultimo_erro'] ?: 'Teste a conexão novamente.') ?></span>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($aba === 'conexao'): ?>

    <?php if (!$conta): ?>
        <div class="card stub-card">
            <div class="stub-icon"><?= icon('whatsapp') ?></div>
            <h2>WhatsApp ainda não conectado</h2>
            <p>Conecte o número oficial da empresa (Cloud API da Meta) ou comece em <b>modo de teste</b>: as telas e automações funcionam, mas nada é enviado.</p>
            <div class="actions" style="justify-content:center;margin-top:18px;">
                <button type="button" class="btn btn-primary" data-open-modal="modal-conta" <?= $chaveOk ? '' : 'disabled' ?>><?= icon('plus') ?>Conectar número oficial</button>
                <button type="button" class="btn btn-outline" data-api-acao="/api/whatsapp.php" data-acao="conta_modo_teste"><?= icon('check') ?>Usar em modo de teste</button>
            </div>
        </div>
    <?php else:
        $temCred = $conta['phone_number_id'] && $conta['access_token_cifrado'];
        $qualidade = ['verde' => ['Alta', 'pill-green'], 'amarela' => ['Média', 'pill-amber'], 'vermelha' => ['Baixa', 'pill-red'], 'desconhecida' => ['Sem informação', 'pill-muted']][$conta['qualidade']];
    ?>
        <div class="card">
            <div class="card-head">
                <div>
                    <h2><?= e($conta['nome_verificado'] ?: $conta['nome']) ?></h2>
                    <p><?= $conta['numero_exibicao'] ? e($conta['numero_exibicao']) . ' · ' : '' ?><?= $temCred ? 'número ' . e($conta['phone_number_id']) : 'sem número oficial cadastrado' ?></p>
                </div>
                <span class="pill <?= $qualidade[1] ?>">Qualidade: <?= $qualidade[0] ?></span>
            </div>
            <div class="int-checks">
                <div class="int-check <?= $plataformaOk ? 'is-ok' : 'is-no' ?>"><?= icon($plataformaOk ? 'check' : 'x') ?><div><b>App da Meta no servidor</b><span><?= $plataformaOk ? 'Segredo do app e token de verificação definidos.' : 'Falta META_APP_SECRET e/ou o token de verificação no config.php.' ?></span></div></div>
                <div class="int-check <?= $conta['token_validado_em'] ? 'is-ok' : 'is-no' ?>"><?= icon($conta['token_validado_em'] ? 'check' : 'x') ?><div><b>Número e token</b><span><?= $conta['token_validado_em'] ? 'Conexão testada ' . e(mb_strtolower(tempo_relativo($conta['token_validado_em']))) . '.' : ($temCred ? 'Ainda não testado.' : 'Não cadastrados.') ?></span></div></div>
                <div class="int-check <?= $conta['webhook_assinado_em'] ? 'is-ok' : 'is-no' ?>"><?= icon($conta['webhook_assinado_em'] ? 'check' : 'x') ?><div><b>Recebimento de mensagens</b><span><?= $conta['webhook_assinado_em'] ? 'Ativado' . ($conta['ultimo_webhook_em'] ? '; último aviso ' . e(mb_strtolower(tempo_relativo($conta['ultimo_webhook_em']))) : '; nenhum aviso recebido ainda') . '.' : 'Não ativado: respostas e entregas não chegam.' ?></span></div></div>
            </div>
            <div class="int-acoes" style="margin-top:16px;">
                <button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-conta" <?= $chaveOk ? '' : 'disabled' ?>><?= icon('edit') ?><?= $temCred ? 'Trocar número ou token' : 'Conectar número oficial' ?></button>
                <?php if ($temCred): ?>
                    <button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/whatsapp.php" data-acao="conta_testar"><?= icon('check') ?>Testar conexão</button>
                    <button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/whatsapp.php" data-acao="conta_assinar"><?= icon('zap') ?>Ativar recebimento</button>
                <?php endif; ?>
                <?php if ($conta['status'] === 'pausada'): ?>
                    <button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/whatsapp.php" data-acao="conta_status" data-status="ativa">Retomar</button>
                <?php else: ?>
                    <button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/whatsapp.php" data-acao="conta_status" data-status="pausada" data-confirmar="Pausar o WhatsApp? Nenhuma mensagem sai até ser retomado.">Pausar</button>
                <?php endif; ?>
                <?php if ((int) $conta['modo_teste']): ?>
                    <?php if ($temCred): ?><button type="button" class="btn btn-primary btn-sm" data-open-modal="modal-real"><?= icon('zap') ?>Ligar envio real</button><?php endif; ?>
                <?php else: ?>
                    <button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/whatsapp.php" data-acao="conta_envio_real" data-ligar="0" data-confirmar="Voltar ao modo de teste? Nada mais será enviado de verdade.">Voltar ao modo de teste</button>
                <?php endif; ?>
            </div>
            <div class="field" style="margin-top:16px;">
                <label for="wa-webhook-url">URL do webhook (painel do app da Meta → WhatsApp → Configuração → Webhook)</label>
                <input type="text" id="wa-webhook-url" readonly value="<?= e(wa_webhook_url()) ?>" onclick="this.select()">
                <small class="field-hint">Assine os campos <b>messages</b> e <b>message_template_status_update</b>. O token de verificação é o WA_VERIFY_TOKEN (ou META_VERIFY_TOKEN) do config.php — não aparece aqui por segurança. Passo a passo: <b>docs/WHATSAPP_JORNADAS.md</b>.</small>
            </div>
        </div>

        <div class="grid-2" style="margin-top:20px;">
            <div class="card">
                <div class="card-head"><div><h2>Regras de envio</h2><p>Valem para todas as automações. Respostas do corretor no chat não têm limite.</p></div></div>
                <form data-api-form action="/api/whatsapp.php">
                    <input type="hidden" name="acao" value="config_salvar">
                    <div class="form-grid">
                        <div class="field"><label for="cfg-ini">Enviar follow-ups a partir de</label><input type="time" id="cfg-ini" name="envio_inicio" required value="<?= e(substr($cfg['envio_inicio'], 0, 5)) ?>"></div>
                        <div class="field"><label for="cfg-fim">até</label><input type="time" id="cfg-fim" name="envio_fim" required value="<?= e(substr($cfg['envio_fim'], 0, 5)) ?>"></div>
                    </div>
                    <div class="field"><span class="label-like">Dias de envio</span><div>
                        <?php $diasSel = array_map('intval', explode(',', $cfg['envio_dias'])); foreach ([1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'] as $d => $rot): ?>
                            <label class="dia-chip"><input type="checkbox" name="envio_dias[]" value="<?= $d ?>" <?= in_array($d, $diasSel, true) ? 'checked' : '' ?>><span><?= $rot ?></span></label>
                        <?php endforeach; ?>
                    </div><small class="field-hint">As boas-vindas a um lead que acabou de chegar podem sair a qualquer hora; lembretes e marketing só neste horário.</small></div>
                    <div class="form-grid">
                        <div class="field"><label for="cfg-24">Máx. de mensagens automáticas por cliente em 24 h</label><input type="number" id="cfg-24" name="max_automaticas_24h" min="1" max="5" value="<?= (int) $cfg['max_automaticas_24h'] ?>"></div>
                        <div class="field"><label for="cfg-7">Máx. de mensagens de marketing por cliente em 7 dias</label><input type="number" id="cfg-7" name="max_marketing_7d" min="0" max="7" value="<?= (int) $cfg['max_marketing_7d'] ?>"></div>
                    </div>
                    <div class="field"><label for="cfg-pal">Palavras que descadastram (separadas por vírgula)</label><input type="text" id="cfg-pal" name="palavras_descadastro" maxlength="255" value="<?= e($cfg['palavras_descadastro']) ?>"><small class="field-hint">Só quando a mensagem do cliente for exatamente a palavra (ex.: “PARAR”).</small></div>
                    <div class="field"><label for="cfg-resp">Resposta quando nenhum consultor puder atender agora</label><textarea id="cfg-resp" name="resposta_sem_corretor" maxlength="1000" placeholder="Ex.: Recebemos sua mensagem! Um consultor vai te responder assim que o plantão abrir."><?= e((string) $cfg['resposta_sem_corretor']) ?></textarea><small class="field-hint">Não informe preços nem condições: quem responde isso é o consultor.</small></div>
                    <div class="form-alert" data-form-alerta hidden></div>
                    <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar regras</button>
                </form>
            </div>

            <div class="card">
                <div class="card-head"><div><h2>Enviar mensagem de teste</h2><p><?= (int) $conta['modo_teste'] ? 'Em modo de teste a mensagem é só registrada (simulada).' : 'Atenção: o envio real está ligado — a mensagem sai de verdade.' ?></p></div></div>
                <form data-api-form action="/api/whatsapp.php">
                    <input type="hidden" name="acao" value="teste_enviar">
                    <div class="field"><label for="t-tel">Seu número de WhatsApp</label><input type="tel" id="t-tel" name="telefone" required placeholder="(11) 98888-7777" autocomplete="tel"></div>
                    <div class="field"><label for="t-tpl">Template aprovado</label>
                        <select id="t-tpl" name="template_id">
                            <option value="">Texto livre (só se você escreveu para o número da empresa nas últimas 24 h)</option>
                            <?php foreach ($templatesTeste as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['nome']) ?> (<?= e($t['idioma']) ?>)</option><?php endforeach; ?>
                        </select>
                        <small class="field-hint">As variáveis são preenchidas com dados fictícios (ex.: “Maria”).</small></div>
                    <div class="field"><label for="t-txt">Texto livre</label><textarea id="t-txt" name="texto" maxlength="1000" placeholder="Usado só quando nenhum template é escolhido."></textarea></div>
                    <label class="check-line"><input type="checkbox" name="autorizo" value="1" required> Este número é meu (ou de quem autorizou) e aceito receber esta mensagem de teste. A autorização fica registrada.</label>
                    <div class="form-alert" data-form-alerta hidden></div>
                    <button type="submit" class="btn btn-primary"><?= icon('whatsapp') ?>Enviar teste</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($aba === 'templates'): ?>

    <div class="card">
        <div class="card-head">
            <div><h2>Templates aprovados pela Meta</h2><p>Mensagens que podem iniciar uma conversa. São criadas e aprovadas no WhatsApp Manager; aqui você liga cada variável a um dado do lead.</p></div>
            <?php if ($conta && $conta['waba_id']): ?><button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/whatsapp.php" data-acao="templates_sincronizar"><?= icon('zap') ?>Sincronizar com a Meta</button><?php endif; ?>
        </div>
        <?php if (!$templates): ?>
            <p class="empty-note"><?= $conta && $conta['waba_id'] ? 'Nenhum template ainda. Clique em “Sincronizar com a Meta”.' : 'Conecte o número oficial na aba Conexão para buscar os templates.' ?></p>
        <?php endif; ?>
    </div>

    <?php foreach ($templates as $t):
        $tid = (int) $t['id'];
        $mapa = wa_template_variaveis($t);
        $vars = wa_variaveis_do_texto($t['corpo']);
        $previa = wa_regra_renderizar($t['corpo'], $mapa, wa_dados_ficticios($empresaNome));
        $botoes = wa_template_botoes($t);
        [$stRot, $stPill] = $statusTpl[$t['status']] ?? [$t['status'], 'pill-muted'];
    ?>
        <div class="card wa-template">
            <div class="card-head">
                <div>
                    <h2><?= e($t['nome']) ?> <span class="muted">· <?= e($t['idioma']) ?></span></h2>
                    <p><?= e($categoriaTpl[$t['categoria']] ?? $t['categoria']) ?><?= $t['cabecalho_tipo'] !== 'nenhum' ? ' · cabeçalho: ' . e($t['cabecalho_tipo']) : '' ?><?= $t['sincronizado_em'] ? ' · sincronizado ' . e(mb_strtolower(tempo_relativo($t['sincronizado_em']))) : '' ?></p>
                </div>
                <span class="pill <?= $stPill ?>"><?= e($stRot) ?></span>
            </div>
            <?php if ($t['motivo_rejeicao']): ?><div class="alert-strip"><?= icon('alert') ?><span>Motivo informado pela Meta: <?= e($t['motivo_rejeicao']) ?></span></div><?php endif; ?>
            <div class="wa-bolha" aria-label="Prévia com dados fictícios">
                <?= nl2br(e($previa['texto'])) ?>
                <?php if ($t['rodape']): ?><small><?= e($t['rodape']) ?></small><?php endif; ?>
                <?php if ($botoes): ?><div class="wa-bolha-botoes"><?php foreach ($botoes as $b): ?><span><?= e($b['texto']) ?></span><?php endforeach; ?></div><?php endif; ?>
            </div>
            <p class="muted" style="margin:6px 0 14px;">Prévia com dados fictícios.<?= $previa['ok'] ? '' : ' <b class="txt-late">Falta ligar variáveis — o template não pode ser usado ainda.</b>' ?></p>
            <form data-api-form action="/api/whatsapp.php">
                <input type="hidden" name="acao" value="template_salvar"><input type="hidden" name="id" value="<?= $tid ?>">
                <div class="form-grid">
                    <div class="field"><label for="fin-<?= $tid ?>">Para que serve</label>
                        <select id="fin-<?= $tid ?>" name="finalidade"><?php foreach ($finalidades as $k => $rot): ?><option value="<?= $k ?>" <?= $t['finalidade'] === $k ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?></select></div>
                    <?php foreach ($vars as $v): ?>
                        <div class="field"><label for="var-<?= $tid ?>-<?= e($v) ?>">Variável {{<?= e($v) ?>}}</label>
                            <select id="var-<?= $tid ?>-<?= e($v) ?>" name="var[<?= e($v) ?>]">
                                <option value="">— escolha o dado —</option>
                                <?php foreach (wa_campos_template() as $campo => $rot): ?><option value="<?= e($campo) ?>" <?= ($mapa[$v] ?? '') === $campo ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?>
                            </select></div>
                    <?php endforeach; ?>
                    <?php foreach ($botoes as $i => $b): if (($b['tipo'] ?? '') !== 'resposta') continue; ?>
                        <div class="field"><label for="bt-<?= $tid ?>-<?= $i ?>">Quando o cliente tocar “<?= e($b['texto']) ?>”</label>
                            <select id="bt-<?= $tid ?>-<?= $i ?>" name="acao_botao[<?= $i ?>]"><?php foreach (wa_acoes_botao() as $k => $rot): ?><option value="<?= e($k) ?>" <?= ($b['acao'] ?? '') === $k ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?></select></div>
                    <?php endforeach; ?>
                </div>
                <div class="form-alert" data-form-alerta hidden></div>
                <button type="submit" class="btn btn-outline btn-sm"><?= icon('check') ?>Salvar configuração</button>
            </form>
        </div>
    <?php endforeach; ?>

<?php else: /* atividade */ ?>

    <div class="card">
        <div class="card-head"><div><h2>Fila de envio</h2><p>Mensagens agendadas e processadas pelas rotinas automáticas.</p></div></div>
        <div class="chip-row">
            <span class="pill pill-muted">Aguardando: <?= $fila['pendente'] ?></span>
            <span class="pill pill-muted">Processando: <?= $fila['processando'] ?></span>
            <span class="pill pill-green">Concluídas: <?= $fila['concluida'] ?></span>
            <span class="pill <?= $fila['falhou'] ? 'pill-red' : 'pill-muted' ?>">Com falha: <?= $fila['falhou'] ?></span>
        </div>
    </div>

    <div class="card" style="margin-top:20px;">
        <div class="card-head"><div><h2>Mensagens recentes</h2><p>As 40 últimas, enviadas e recebidas.</p></div></div>
        <?php if ($mensagens): ?>
            <div class="table-wrap"><table class="report-table wa-msgs">
                <thead><tr><th>Quando</th><th>Cliente</th><th>Mensagem</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($mensagens as $m): [$rot, $pill] = wa_status_mensagem()[$m['status']] ?? [$m['status'], 'pill-muted']; ?>
                    <tr>
                        <td data-rotulo="Quando"><?= e(date('d/m H:i', strtotime($m['criado_em']))) ?><br><span class="muted"><?= $m['direcao'] === 'entrada' ? '↙ recebida' : '↗ ' . ($m['usuario_nome'] ? e($m['usuario_nome']) : 'automação') ?></span></td>
                        <td data-rotulo="Cliente"><span class="wa-tel"><?= e(wa_tel_exibir($m['telefone'])) ?></span><?= $m['nome_perfil'] ? '<br><span class="muted">' . e($m['nome_perfil']) . '</span>' : '' ?>
                            <?php if ($m['lead_id'] && $m['lead_nome'] !== null && rbac_pode_ver_lead($usuario, (int) $m['lead_id'])): ?><br><a class="lead-link" href="/pages/lead.php?id=<?= (int) $m['lead_id'] ?>"><?= e($m['lead_nome']) ?></a><?php endif; ?></td>
                        <td data-rotulo="Mensagem"><?= e(mb_strimwidth((string) $m['conteudo'], 0, 140, '…')) ?><?= $m['erro_texto'] ? '<br><span class="txt-late">' . e($m['erro_texto']) . '</span>' : '' ?></td>
                        <td data-rotulo="Status"><span class="pill <?= $pill ?>"><?= e($rot) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <p class="empty-note">Nenhuma mensagem ainda.</p>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-top:20px;">
        <div class="card-head"><div><h2>Avisos recebidos da Meta</h2><p>Cada aviso do webhook (mensagens, confirmações de entrega e templates).</p></div></div>
        <?php if ($eventos): ?>
            <div class="table-wrap"><table class="report-table wa-msgs">
                <thead><tr><th>Recebido</th><th>Tipo</th><th>Status</th><th>Detalhe</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($eventos as $ev): [$rot, $pill] = wa_status_evento()[$ev['status']] ?? [$ev['status'], 'pill-muted']; ?>
                    <tr>
                        <td data-rotulo="Recebido"><?= e(date('d/m H:i', strtotime($ev['recebido_em']))) ?></td>
                        <td data-rotulo="Tipo"><?= e(['mensagem' => 'Mensagem', 'status' => 'Entrega/leitura', 'template' => 'Template', 'conta' => 'Conta', 'outro' => 'Outro'][$ev['tipo']] ?? $ev['tipo']) ?></td>
                        <td data-rotulo="Status"><span class="pill <?= $pill ?>"><?= e($rot) ?></span><?= (int) $ev['tentativas'] > 1 ? '<br><span class="muted">' . (int) $ev['tentativas'] . ' tentativas</span>' : '' ?></td>
                        <td data-rotulo="Detalhe"><?= $ev['erro'] ? '<span class="' . ($ev['status'] === 'erro' ? 'txt-late' : 'muted') . '">' . e($ev['erro']) . '</span>' : '' ?></td>
                        <td class="col-acoes"><?php if (in_array($ev['status'], ['erro', 'pendente'], true)): ?><button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/whatsapp.php" data-acao="evento_reprocessar" data-id="<?= (int) $ev['id'] ?>">Reprocessar</button><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <p class="empty-note">Nenhum aviso recebido da Meta até agora.</p>
        <?php endif; ?>
    </div>

<?php endif; ?>

<div class="modal-overlay" id="modal-conta" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="titulo-conta">
        <button type="button" class="overlay-close" data-close-modal="modal-conta" aria-label="Fechar">&times;</button>
        <h2 id="titulo-conta">Número oficial do WhatsApp</h2>
        <form data-api-form action="/api/whatsapp.php">
            <input type="hidden" name="acao" value="conta_salvar">
            <div class="field"><label for="c-nome">Nome interno</label><input type="text" id="c-nome" name="nome" maxlength="120" value="<?= e($conta['nome'] ?? 'WhatsApp principal') ?>"></div>
            <div class="field"><label for="c-waba">ID da conta do WhatsApp Business (WABA)</label><input type="text" inputmode="numeric" id="c-waba" name="waba_id" required maxlength="40" value="<?= e($conta['waba_id'] ?? '') ?>"></div>
            <div class="field"><label for="c-pnid">ID do número de telefone</label><input type="text" inputmode="numeric" id="c-pnid" name="phone_number_id" required maxlength="40" value="<?= e($conta['phone_number_id'] ?? '') ?>"><small class="field-hint">Os dois IDs ficam no painel do app da Meta → WhatsApp → Configuração da API.</small></div>
            <div class="field"><label for="c-token">Token de acesso permanente</label><input type="password" id="c-token" name="access_token" autocomplete="off" maxlength="2000" <?= !empty($conta['access_token_cifrado']) ? 'placeholder="Token salvo — deixe em branco para manter"' : 'required' ?>><small class="field-hint">Token de usuário do sistema com permissão de mensagens e de gerenciamento do WhatsApp Business. Fica cifrado e nunca é exibido. Trocar o token volta a conta para o modo de teste.</small></div>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar</button>
        </form>
    </div>
</div>

<?php if ($conta): ?>
<div class="modal-overlay" id="modal-real" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="titulo-real">
        <button type="button" class="overlay-close" data-close-modal="modal-real" aria-label="Fechar">&times;</button>
        <h2 id="titulo-real">Ligar o envio real</h2>
        <p>A partir daqui as mensagens <b>saem de verdade</b> para os clientes e templates são cobrados pela Meta. Só ligue depois de testar a conexão, ativar o recebimento e conferir os templates.</p>
        <form data-api-form action="/api/whatsapp.php">
            <input type="hidden" name="acao" value="conta_envio_real"><input type="hidden" name="ligar" value="1">
            <div class="field"><label for="r-conf">Para confirmar, digite ENVIAR DE VERDADE</label><input type="text" id="r-conf" name="confirmacao" required autocomplete="off"></div>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-primary"><?= icon('zap') ?>Ligar envio real</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
