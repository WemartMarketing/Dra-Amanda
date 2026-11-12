<?php
/**
 * POST /api/pendencias.php  { id, resposta, observacao?, etapa_id?, horas? }
 * O corretor responde uma pendência da própria central. Só a dele e só de
 * lead que ainda é dele (transferido → some). Nenhuma resposta dispara
 * mensagem para o cliente.
 *   resposta: respondeu | nao_respondeu | tentativa | adiar
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/jornadas.php';

$usuario = api_iniciar(null);
$etapa = (int) ($_POST['etapa_id'] ?? 0);
if ($etapa && !funil_etapa((int) $usuario['empresa_ativa_id'], $etapa)) {
    api_erro('Etapa inválida.', 422);
}
$r = pendencia_responder($usuario, (int) ($_POST['id'] ?? 0), (string) ($_POST['resposta'] ?? ''), (string) ($_POST['observacao'] ?? ''),
                         $etapa ?: null, (int) ($_POST['horas'] ?? 2));
$r['ok'] ? api_responder(['ok' => true, 'mensagem' => $r['mensagem']]) : api_erro($r['erro'], 422);
