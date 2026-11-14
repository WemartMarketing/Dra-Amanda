<?php
/**
 * Registro formal de contratos (migração 013).
 *
 * Mover o lead para "Contrato assinado" no Kanban continua sendo só um
 * SINAL. A venda formal é o contrato registrado aqui — com data de
 * assinatura, VGV opcional (Valor Geral de Vendas, não é receita nem
 * lucro), unidade — e VALIDADO por quem tem a permissão validar_contratos.
 * Distrato mantém o registro (status distratado), para o histórico.
 *
 * O corretor e a estrutura gravados são os do lead NO MOMENTO do registro
 * (não mudam se o lead for transferido depois).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/transferencia.php';
require_once __DIR__ . '/auditoria.php';
require_once __DIR__ . '/funil.php';

function contratos_instalados(): bool
{
    return modulo_tabela_existe('lead_contratos');
}

function contrato_status(): array
{
    return ['registrado' => 'Aguardando validação', 'validado' => 'Validado', 'distratado' => 'Distratado'];
}

function contratos_do_lead(int $empresaId, int $leadId): array
{
    if (!contratos_instalados()) {
        return [];
    }
    $stmt = db()->prepare('SELECT c.*, u.nome AS corretor_nome, r.nome AS registrado_por_nome, v.nome AS validado_por_nome, emp.nome AS empreendimento_nome
        FROM lead_contratos c
        LEFT JOIN usuarios u ON u.id = c.usuario_id
        LEFT JOIN usuarios r ON r.id = c.registrado_por
        LEFT JOIN usuarios v ON v.id = c.validado_por
        LEFT JOIN empreendimentos emp ON emp.id = c.empreendimento_id
        WHERE c.empresa_id = :e AND c.lead_id = :l ORDER BY c.assinado_em DESC, c.id DESC');
    $stmt->execute(['e' => $empresaId, 'l' => $leadId]);
    return $stmt->fetchAll();
}

function contrato_carregar(int $empresaId, int $id): ?array
{
    if (!contratos_instalados()) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM lead_contratos WHERE id = :id AND empresa_id = :e');
    $stmt->execute(['id' => $id, 'e' => $empresaId]);
    return $stmt->fetch() ?: null;
}

/** "1.234.567,89" / "1234567.89" → float; '' → null; inválido → false. */
function contrato_valor(?string $texto)
{
    $t = trim((string) $texto);
    if ($t === '') {
        return null;
    }
    $t = preg_replace('/[^\d,\.]/', '', $t);
    if (strpos($t, ',') !== false) {
        $t = str_replace(['.', ','], ['', '.'], $t);
    } elseif (substr_count($t, '.') > 1 || preg_match('/\.\d{3}$/', $t)) {
        $t = str_replace('.', '', $t);
    }
    if ($t === '' || !is_numeric($t)) {
        return false;
    }
    $v = round((float) $t, 2);
    return $v > 0 && $v < 1000000000000 ? $v : false;
}

/** Valida os campos comuns. Retorna [dados, erro]. */
function contrato_dados(array $entrada): array
{
    $data = trim((string) ($entrada['assinado_em'] ?? ''));
    $d = DateTime::createFromFormat('!Y-m-d', $data);
    if (!$d || $d->format('Y-m-d') !== $data) {
        return [null, 'Informe a data de assinatura.'];
    }
    if ($data > date('Y-m-d')) {
        return [null, 'A data de assinatura não pode estar no futuro.'];
    }
    if ($data < '2000-01-01') {
        return [null, 'Data de assinatura inválida.'];
    }
    $valor = contrato_valor($entrada['valor_vgv'] ?? null);
    if ($valor === false) {
        return [null, 'Valor (VGV) inválido. Use, por exemplo, 450.000,00 — ou deixe em branco.'];
    }
    $unidade = trim(mb_substr((string) ($entrada['unidade'] ?? ''), 0, 60));
    $obs = trim(mb_substr((string) ($entrada['observacao'] ?? ''), 0, 500));
    return [['assinado_em' => $data, 'valor_vgv' => $valor, 'unidade' => $unidade !== '' ? $unidade : null, 'observacao' => $obs !== '' ? $obs : null], null];
}

/**
 * Registra o contrato do lead ($lead já carregado com o escopo do usuário).
 * $moverEtapa: também leva o lead para a etapa "Contrato assinado".
 */
function contrato_registrar(array $usuario, array $lead, array $entrada, bool $moverEtapa = true): array
{
    [$dados, $erro] = contrato_dados($entrada);
    if ($erro) {
        return ['ok' => false, 'erro' => $erro];
    }
    if (!$lead['responsavel_id']) {
        return ['ok' => false, 'erro' => 'O lead está sem corretor responsável. Atribua um corretor antes de registrar o contrato.'];
    }
    $empresaId = (int) $lead['empresa_id'];
    $est = estrutura_do_lead($lead);
    $pdo = db();
    $pdo->prepare('INSERT INTO lead_contratos (empresa_id, lead_id, empreendimento_id, usuario_id, equipe_id, gerente_id, superintendencia_id,
                       assinado_em, valor_vgv, unidade, observacao, status, registrado_por)
                   VALUES (:e, :l, :emp, :u, :eq, :g, :s, :d, :v, :un, :o, \'registrado\', :r)')
        ->execute([
            'e' => $empresaId, 'l' => $lead['id'], 'emp' => $lead['empreendimento_id'] ?: null, 'u' => $lead['responsavel_id'],
            'eq' => $est['equipe_id'], 'g' => $est['gerente_id'], 's' => $est['superintendencia_id'],
            'd' => $dados['assinado_em'], 'v' => $dados['valor_vgv'], 'un' => $dados['unidade'], 'o' => $dados['observacao'], 'r' => $usuario['id'],
        ]);
    $id = (int) $pdo->lastInsertId();
    $txt = 'Contrato registrado (assinado em ' . date('d/m/Y', strtotime($dados['assinado_em'])) . ')'
        . ($dados['unidade'] ? ' — unidade ' . $dados['unidade'] : '') . '. Aguardando validação.';
    lead_registrar_evento((int) $lead['id'], (int) $usuario['id'], 'sistema', $txt);
    auditoria_registrar($empresaId, (int) $usuario['id'], 'contrato.registrado', 'lead_contrato', $id,
        ['lead_id' => (int) $lead['id'], 'assinado_em' => $dados['assinado_em'], 'com_valor' => $dados['valor_vgv'] !== null]);
    $movido = false;
    if ($moverEtapa && $lead['etapa'] !== 'contrato_assinado' && ($et = funil_etapa_por_categoria($empresaId, 'contrato_assinado'))) {
        $movido = lead_mudar_etapa($lead, (int) $et['id'], $usuario, 'contrato registrado');
    }
    return ['ok' => true, 'id' => $id, 'movido' => $movido];
}

/** Corrige data/valor/unidade enquanto o contrato aguarda validação. */
function contrato_editar(array $usuario, array $contrato, array $entrada): array
{
    if ($contrato['status'] !== 'registrado') {
        return ['ok' => false, 'erro' => 'Só dá para corrigir contratos que ainda aguardam validação.'];
    }
    [$dados, $erro] = contrato_dados($entrada);
    if ($erro) {
        return ['ok' => false, 'erro' => $erro];
    }
    db()->prepare('UPDATE lead_contratos SET assinado_em = :d, valor_vgv = :v, unidade = :un, observacao = :o WHERE id = :id AND status = \'registrado\'')
        ->execute(['d' => $dados['assinado_em'], 'v' => $dados['valor_vgv'], 'un' => $dados['unidade'], 'o' => $dados['observacao'], 'id' => $contrato['id']]);
    lead_registrar_evento((int) $contrato['lead_id'], (int) $usuario['id'], 'sistema', 'Dados do contrato corrigidos.');
    auditoria_registrar((int) $contrato['empresa_id'], (int) $usuario['id'], 'contrato.editado', 'lead_contrato', (int) $contrato['id'], []);
    return ['ok' => true];
}

function contrato_validar(array $usuario, array $contrato): array
{
    if ($contrato['status'] !== 'registrado') {
        return ['ok' => false, 'erro' => 'Este contrato não está aguardando validação.'];
    }
    $upd = db()->prepare('UPDATE lead_contratos SET status = \'validado\', validado_por = :u, validado_em = NOW() WHERE id = :id AND status = \'registrado\'');
    $upd->execute(['u' => $usuario['id'], 'id' => $contrato['id']]);
    if ($upd->rowCount() !== 1) {
        return ['ok' => false, 'erro' => 'Este contrato não está aguardando validação.'];
    }
    lead_registrar_evento((int) $contrato['lead_id'], (int) $usuario['id'], 'sistema', 'Contrato validado por ' . $usuario['nome'] . '.');
    auditoria_registrar((int) $contrato['empresa_id'], (int) $usuario['id'], 'contrato.validado', 'lead_contrato', (int) $contrato['id'], []);
    return ['ok' => true];
}

function contrato_distratar(array $usuario, array $contrato, string $data, string $motivo): array
{
    if ($contrato['status'] === 'distratado') {
        return ['ok' => false, 'erro' => 'Este contrato já está distratado.'];
    }
    $d = DateTime::createFromFormat('!Y-m-d', $data);
    if (!$d || $d->format('Y-m-d') !== $data || $data > date('Y-m-d') || $data < $contrato['assinado_em']) {
        return ['ok' => false, 'erro' => 'Informe a data do distrato (entre a assinatura e hoje).'];
    }
    $motivo = trim(mb_substr($motivo, 0, 190));
    if (mb_strlen($motivo) < 3) {
        return ['ok' => false, 'erro' => 'Informe o motivo do distrato.'];
    }
    db()->prepare('UPDATE lead_contratos SET status = \'distratado\', distratado_em = :d, distrato_motivo = :m WHERE id = :id')
        ->execute(['d' => $data, 'm' => $motivo, 'id' => $contrato['id']]);
    lead_registrar_evento((int) $contrato['lead_id'], (int) $usuario['id'], 'sistema', 'Distrato registrado em ' . date('d/m/Y', strtotime($data)) . ': ' . $motivo);
    auditoria_registrar((int) $contrato['empresa_id'], (int) $usuario['id'], 'contrato.distratado', 'lead_contrato', (int) $contrato['id'], ['distratado_em' => $data]);
    return ['ok' => true];
}
