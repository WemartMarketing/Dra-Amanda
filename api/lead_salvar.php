<?php
/**
 * POST /api/lead_salvar.php — cadastrar (sem id) ou editar (com id) um lead.
 *   { id?, nome, telefone, email, empreendimento_id, origem, interesse,
 *     campanha, renda_informada, prioridade, observacao?, responsavel_id? }
 *
 * Cadastro: permissão criar_leads. Responsável = quem cadastra; gestores
 * (quem tem redistribuir_leads) podem escolher outra pessoa do seu recorte.
 * Edição: permissão editar_leads + lead no escopo.
 *
 * Duplicidade: o telefone normalizado não pode repetir entre os leads não
 * excluídos da empresa. Se o lead existente estiver fora do alcance de
 * quem tenta cadastrar, a resposta NÃO revela nome, responsável nem id.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/funil.php';
require_once __DIR__ . '/../includes/transferencia.php';
require_once __DIR__ . '/../includes/origens.php';

$id = (int) ($_POST['id'] ?? 0);
$usuario = api_iniciar($id ? 'editar_leads' : 'criar_leads');
$empresaId = (int) $usuario['empresa_ativa_id'];
$atual = $id ? api_lead($usuario, $id) : null;

$nome = api_texto($_POST['nome'] ?? null);
if (!$nome) {
    api_erro('Informe o nome do cliente.', 422);
}
$telefoneDigitos = lead_telefone_normalizado($_POST['telefone'] ?? '');
if ($telefoneDigitos !== '' && (strlen($telefoneDigitos) < 10 || strlen($telefoneDigitos) > 11)) {
    api_erro('Telefone inválido. Use DDD + número, ex.: (11) 98877-6655.', 422);
}
$email = api_texto($_POST['email'] ?? null);
if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_erro('E-mail inválido.', 422);
}
$empreendimentoId = (int) ($_POST['empreendimento_id'] ?? 0) ?: null;
if ($empreendimentoId && !api_registro_da_empresa('empreendimentos', $empreendimentoId, $empresaId)) {
    api_erro('Empreendimento não encontrado.', 404);
}
$origem = (string) ($_POST['origem'] ?? 'manual');
if (!array_key_exists($origem, lead_origens())) {
    api_erro('Origem inválida.', 422);
}
$prioridade = (string) ($_POST['prioridade'] ?? 'normal');
if (!array_key_exists($prioridade, lead_prioridades())) {
    api_erro('Prioridade inválida.', 422);
}
$rendaTexto = trim((string) ($_POST['renda_informada'] ?? ''));
$renda = null;
if ($rendaTexto !== '') {
    $renda = (float) str_replace(['.', ','], ['', '.'], preg_replace('/[^\d,\.]/', '', $rendaTexto));
    if ($renda < 0 || $renda > 99999999) {
        api_erro('Renda inválida.', 422);
    }
}

// Duplicidade por telefone (empresa inteira, só leads não excluídos).
if ($telefoneDigitos !== '') {
    $stmt = db()->prepare("
        SELECT id FROM leads
        WHERE empresa_id = :empresa_id AND excluido_em IS NULL AND id <> :id
          AND " . lead_sql_telefone_digitos('telefone') . " IN (:tel, CONCAT('55', :tel2))
        LIMIT 1");
    $stmt->execute(['empresa_id' => $empresaId, 'id' => $id, 'tel' => $telefoneDigitos, 'tel2' => $telefoneDigitos]);
    $duplicadoId = $stmt->fetchColumn();
    if ($duplicadoId) {
        if (rbac_pode_ver_lead($usuario, (int) $duplicadoId)) {
            api_responder(['ok' => false, 'duplicado' => true, 'lead_id' => (int) $duplicadoId,
                'erro' => 'Já existe um lead com este telefone na sua carteira. Abra o cadastro existente em vez de criar outro.'], 409);
        }
        api_responder(['ok' => false, 'duplicado' => true,
            'erro' => 'Já existe um lead com este telefone na empresa, atendido por outra equipe. Fale com seu gerente antes de cadastrar.'], 409);
    }
}

$dados = [
    'nome' => $nome,
    'telefone' => $telefoneDigitos !== '' ? lead_formatar_telefone($telefoneDigitos) : null,
    'email' => $email,
    'empreendimento_id' => $empreendimentoId,
    'origem' => $origem,
    'interesse' => api_texto($_POST['interesse'] ?? null),
    'renda_informada' => $renda,
    'prioridade' => $prioridade,
];
// Campanha só muda quando o formulário a envia (outros formulários não a apagam).
if (!$atual || array_key_exists('campanha', $_POST)) {
    $dados['campanha'] = api_texto($_POST['campanha'] ?? null, 120);
}

// Origem detalhada (migração 013): a origem escolhida define o canal (leads.origem).
// Sem detalhe, o canal recebe a origem padrão dele ("Cadastro manual (não detalhado)"...).
if (origens_instaladas()) {
    if (array_key_exists('origem_detalhe_id', $_POST)) {
        $detalheId = (int) $_POST['origem_detalhe_id'];
        if ($detalheId) {
            $det = origem_carregar($empresaId, $detalheId);
            if (!$det) {
                api_erro('Origem não encontrada.', 404);
            }
            $dados['origem'] = $det['canal'];
            $dados['origem_detalhe_id'] = $detalheId;
        } else {
            $dados['origem'] = 'manual';
            $dados['origem_detalhe_id'] = origem_id_do_canal($empresaId, 'manual');
        }
    } elseif (!$atual || $atual['origem'] !== $origem) {
        $dados['origem_detalhe_id'] = origem_id_do_canal($empresaId, $origem);
    }
    foreach (origens_campos_utm() as $campo => $max) {
        if (!$atual || array_key_exists($campo, $_POST)) {
            $dados[$campo] = api_texto($_POST[$campo] ?? null, $max);
        }
    }
}

if ($atual) {
    $sets = implode(', ', array_map(fn ($c) => "{$c} = :{$c}", array_keys($dados)));
    db()->prepare("UPDATE leads SET {$sets} WHERE id = :id AND empresa_id = :empresa_id")
        ->execute($dados + ['id' => $id, 'empresa_id' => $empresaId]);
    $alterados = array_keys(array_filter($dados, fn ($valor, $campo) => (string) $valor !== (string) ($atual[$campo] ?? ''), ARRAY_FILTER_USE_BOTH));
    if ($alterados) {
        lead_registrar_evento($id, (int) $usuario['id'], 'sistema', 'Cadastro atualizado (' . implode(', ', $alterados) . ')');
        auditoria_registrar($empresaId, (int) $usuario['id'], 'lead.editado', 'lead', $id, ['campos' => $alterados]);
    }
    api_responder(['ok' => true, 'id' => $id]);
}

// ---------------------------------------------------------------- Cadastro
$etapa = funil_primeira_etapa($empresaId);
if (!$etapa) {
    api_erro('O funil da empresa não tem etapas ativas. Fale com o administrador.', 409);
}

// "Distribuir pela roleta": o lead nasce sem responsável e o rodízio
// escolhe quem recebe (ou ele vai para a fila de espera).
if (!empty($_POST['distribuir_roleta'])) {
    require_once __DIR__ . '/../includes/roleta.php';
    if (!usuario_tem_permissao($usuario, 'gerenciar_roletas') && !usuario_tem_permissao($usuario, 'redistribuir_leads')) {
        api_erro('Sua função não pode enviar leads para a roleta.', 403);
    }
    if (!$empreendimentoId) {
        api_erro('Para distribuir pela roleta, escolha o empreendimento de interesse.', 422);
    }
    $colunas = array_keys($dados);
    db()->prepare('INSERT INTO leads (empresa_id, equipe_id, responsavel_id, etapa_id, etapa, ultima_atividade_em, ' . implode(', ', $colunas) . ')
                   VALUES (:empresa_id, NULL, NULL, :etapa_id, :etapa, NOW(), :' . implode(', :', $colunas) . ')')
        ->execute($dados + ['empresa_id' => $empresaId, 'etapa_id' => $etapa['id'], 'etapa' => $etapa['categoria']]);
    $novoId = (int) db()->lastInsertId();
    lead_registrar_evento($novoId, (int) $usuario['id'], 'sistema', 'Lead cadastrado por ' . $usuario['nome'] . ' e enviado para a roleta');
    $observacao = api_texto($_POST['observacao'] ?? null, 500);
    if ($observacao) {
        lead_registrar_evento($novoId, (int) $usuario['id'], 'nota', $observacao);
    }
    auditoria_registrar($empresaId, (int) $usuario['id'], 'lead.criado', 'lead', $novoId, ['distribuir_roleta' => true, 'origem' => $origem]);

    $res = roleta_distribuir_lead($novoId, 'manual', $usuario);
    require_once __DIR__ . '/../includes/jornadas.php';
    jornada_lead_chegou($empresaId, $novoId, (string) $origem);
    $visivel = rbac_pode_ver_lead($usuario, $novoId);
    if ($res['status'] === 'distribuido') {
        $msg = $visivel
            ? 'Lead cadastrado e entregue a ' . $res['usuario_nome'] . ' pela roleta ' . $res['roleta_nome'] . '.'
            : 'Lead cadastrado e entregue pela roleta ' . $res['roleta_nome'] . ' a um corretor de plantão.';
    } else {
        $msg = 'Lead cadastrado e colocado na fila de espera: ' . mb_strtolower($res['motivo']) . '. Ele será entregue assim que um corretor entrar na roleta.';
    }
    api_responder(['ok' => true, 'id' => $novoId, 'roleta' => $res['status'], 'mensagem' => $msg,
        'redirecionar' => $visivel ? '/pages/lead.php?id=' . $novoId . '&novo=1' : null, 'permanecer' => !$visivel]);
}

$responsavelId = (int) $usuario['id'];
$pedido = (int) ($_POST['responsavel_id'] ?? 0);
if ($pedido && $pedido !== $responsavelId) {
    if (!usuario_tem_permissao($usuario, 'redistribuir_leads')) {
        api_erro('Sua função não pode cadastrar leads para outra pessoa.', 403);
    }
    $permitidos = array_map('intval', array_column(rbac_usuarios_atribuiveis($usuario), 'id'));
    if (!in_array($pedido, $permitidos, true)) {
        api_erro('Responsável fora da sua equipe.', 404);
    }
    $responsavelId = $pedido;
}
$estrutura = estrutura_do_usuario($responsavelId);

$colunas = array_keys($dados);
db()->prepare('INSERT INTO leads (empresa_id, equipe_id, responsavel_id, etapa_id, etapa, ultima_atividade_em, ultimo_feedback_em, atribuido_em, ' . implode(', ', $colunas) . ')
               VALUES (:empresa_id, :equipe_id, :responsavel_id, :etapa_id, :etapa, NOW(), NOW(), NOW(), :' . implode(', :', $colunas) . ')')
    ->execute($dados + [
        'empresa_id' => $empresaId, 'equipe_id' => $estrutura['equipe_id'], 'responsavel_id' => $responsavelId,
        'etapa_id' => $etapa['id'], 'etapa' => $etapa['categoria'],
    ]);
$novoId = (int) db()->lastInsertId();
historico_abrir_atribuicao($novoId, $responsavelId, 'cadastro');

$stmt = db()->prepare('SELECT nome FROM usuarios WHERE id = :id');
$stmt->execute(['id' => $responsavelId]);
lead_registrar_evento($novoId, (int) $usuario['id'], 'atribuicao', 'Lead cadastrado por ' . $usuario['nome'] . ' e atribuído a ' . $stmt->fetchColumn());
$observacao = api_texto($_POST['observacao'] ?? null, 500);
if ($observacao) {
    lead_registrar_evento($novoId, (int) $usuario['id'], 'nota', $observacao);
}
auditoria_registrar($empresaId, (int) $usuario['id'], 'lead.criado', 'lead', $novoId, ['responsavel_id' => $responsavelId, 'origem' => $origem]);
require_once __DIR__ . '/../includes/jornadas.php';
jornada_lead_chegou($empresaId, $novoId, (string) $origem);
api_responder(['ok' => true, 'id' => $novoId]);
