<?php
/**
 * Início comum dos endpoints de escrita (api/*.php) a partir da Etapa 2.
 *
 * Ordem fixa das verificações, antes de qualquer acesso ao banco:
 *   1. sessão válida            → 401
 *   2. token CSRF               → 403
 *   3. permissão da função      → 403
 * Toda consulta depois disso deve filtrar por empresa_id (ver
 * api_registro_da_empresa()) — um ID de outra empresa responde 404 e
 * nunca é alterado.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rbac.php';

function api_responder(array $dados, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_erro(string $mensagem, int $status): void
{
    api_responder(['ok' => false, 'erro' => $mensagem], $status);
}

/** Valida sessão, CSRF e permissão (null = qualquer usuário de empresa logado); devolve o usuário logado. */
function api_iniciar(?string $permissao): array
{
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_erro('Método não permitido.', 405);
    }
    if (!auth_check()) {
        api_erro('Sessão expirada. Entre novamente.', 401);
    }
    // POST acima de post_max_size chega vazio ao PHP (sem campos e sem o
    // token) — avisa o motivo real em vez de um falso "token inválido".
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        api_erro('O envio foi grande demais para o servidor. Use um arquivo menor.', 413);
    }
    if (!csrf_validar_requisicao()) {
        api_erro('Token de segurança inválido. Recarregue a página e tente de novo.', 403);
    }

    $usuario = current_user();
    if (!empty($usuario['eh_superadmin']) || ($permissao !== null && !usuario_tem_permissao($usuario, $permissao))) {
        api_erro('Sua função não tem permissão para esta ação.', 403);
    }
    return $usuario;
}

/** True se o registro $id da $tabela pertence à empresa. Tabela sempre vem de código, nunca do usuário. */
function api_registro_da_empresa(string $tabela, int $id, int $empresaId): bool
{
    $permitidas = ['superintendencias', 'equipes', 'empreendimentos', 'plantoes', 'roletas', 'usuarios'];
    if (!in_array($tabela, $permitidas, true) || $id <= 0) {
        return false;
    }
    $stmt = db()->prepare("SELECT 1 FROM {$tabela} WHERE id = :id AND empresa_id = :empresa_id");
    $stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Filtra uma lista de IDs enviada pelo formulário, mantendo só os que
 * pertencem à empresa (IDs de outra empresa são descartados em silêncio).
 */
function api_ids_da_empresa(string $tabela, $ids, int $empresaId): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
    return array_values(array_filter($ids, function ($id) use ($tabela, $empresaId) {
        return api_registro_da_empresa($tabela, $id, $empresaId);
    }));
}

/** Texto opcional: trim e vazio → null, com limite de tamanho. */
function api_texto(?string $valor, int $max = 190): ?string
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return null;
    }
    return mb_substr($valor, 0, $max);
}

/** Lead visível ao usuário (empresa + escopo + não excluído) ou responde 404. */
function api_lead(array $usuario, int $leadId): array
{
    require_once __DIR__ . '/leads.php';
    $lead = lead_carregar($usuario, $leadId);
    if (!$lead) {
        api_erro('Lead não encontrado ou fora da sua carteira.', 404);
    }
    return $lead;
}

/** Data/hora vinda de <input type="datetime-local"> ou date+time; null se vazia, false se inválida. */
function api_data_hora(?string $valor)
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return null;
    }
    $valor = str_replace('T', ' ', $valor);
    foreach (['Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d'] as $formato) {
        $dt = DateTime::createFromFormat('!' . $formato, $valor);
        if ($dt && $dt->format($formato) === $valor) {
            if ($formato === 'Y-m-d') {
                $dt->setTime(9, 0);
            }
            return $dt->format('Y-m-d H:i:s');
        }
    }
    return false;
}
