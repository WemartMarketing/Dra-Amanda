<?php
/**
 * Exportações grandes em segundo plano (migração 015).
 *
 * O pedido guarda só os filtros. O cron (cron/rotinas.php) gera o arquivo
 * com a permissão ATUAL de quem pediu (se perdeu o acesso, não gera), em
 * storage/exportacoes/ (bloqueado para o navegador). O link de download leva
 * um token (HMAC com a chave do servidor; no banco só o sha256 dele), só
 * funciona para o próprio usuário logado e expira em 24 h, quando o arquivo
 * é apagado.
 */
require_once __DIR__ . '/arquivos.php';
require_once __DIR__ . '/../auditoria.php';

const RELEXP_DIR = __DIR__ . '/../../storage/exportacoes';
const RELEXP_HORAS_VALIDADE = 24;
/** Acima disto (linhas de dados), a exportação vai para a fila. */
const RELEXP_LIMITE_IMEDIATO = 2000;

function relexp_fila_instalada(): bool
{
    return modulo_tabela_existe('relatorio_exportacoes');
}

/** Segredo do servidor para os links (APP_KEY do config; sem ela, deriva da conexão do banco). */
function relexp_segredo(): string
{
    if (defined('APP_KEY') && (string) APP_KEY !== '') {
        return (string) APP_KEY;
    }
    return hash('sha256', (defined('DB_PASS') ? (string) DB_PASS : '') . '|' . (defined('DB_NAME') ? (string) DB_NAME : '') . '|wemart-exportacoes');
}

/** Token do link de um pedido: HMAC (não fica gravado; no banco só o sha256 dele, para conferir). */
function relexp_token(array $job): string
{
    return hash_hmac('sha256', 'exp|' . $job['id'] . '|' . $job['usuario_id'] . '|' . $job['criado_em'], relexp_segredo());
}

function relexp_link(array $job): string
{
    return '/api/exportacao_baixar.php?id=' . (int) $job['id'] . '&t=' . relexp_token($job);
}

/** Cria o pedido; retorna o id. */
function relexp_enfileirar(array $usuario, string $rel, string $formato, string $nivel, array $pub): int
{
    $pdo = db();
    $pdo->prepare('INSERT INTO relatorio_exportacoes (empresa_id, usuario_id, relatorio, formato, nivel, parametros, token_hash) VALUES (:e, :u, :r, :f, :n, :p, :t)')
        ->execute(['e' => $usuario['empresa_ativa_id'], 'u' => $usuario['id'], 'r' => $rel, 'f' => $formato, 'n' => $nivel,
                   'p' => mb_substr(http_build_query($pub), 0, 2000), 't' => str_repeat('0', 64)]);
    $id = (int) $pdo->lastInsertId();
    $job = $pdo->query('SELECT id, usuario_id, criado_em FROM relatorio_exportacoes WHERE id = ' . $id)->fetch();
    $pdo->prepare('UPDATE relatorio_exportacoes SET token_hash = :t WHERE id = :id')->execute(['t' => hash('sha256', relexp_token($job)), 'id' => $id]);
    return $id;
}

/** Usuário como no login (função, escopo, permissões), para o cron. null se inativo. */
function relexp_usuario(int $usuarioId, int $empresaId): ?array
{
    $stmt = db()->prepare('SELECT u.*, f.escopo, f.nome AS funcao_nome FROM usuarios u JOIN funcoes f ON f.id = u.funcao_id
                           JOIN empresas e ON e.id = u.empresa_id AND e.ativa = 1 WHERE u.id = :id AND u.ativo = 1');
    $stmt->execute(['id' => $usuarioId]);
    $u = $stmt->fetch();
    if (!$u || (int) $u['empresa_id'] !== $empresaId) {
        return null;
    }
    unset($u['senha_hash']);
    $u['empresa_ativa_id'] = $empresaId;
    $u['eh_superadmin'] = !empty($u['eh_superadmin']);
    $stmt = db()->prepare('SELECT permissao_chave FROM funcao_permissoes WHERE funcao_id = :f');
    $stmt->execute(['f' => $u['funcao_id']]);
    $u['permissoes'] = array_column($stmt->fetchAll(), 'permissao_chave');
    return $u;
}

/**
 * Gera um arquivo (imediato ou pela fila) em $destino. Retorna
 * ['ok', 'linhas', 'extensao', 'mime', 'nome', 'erro'].
 */
function relexp_gerar_arquivo(array $usuario, string $rel, string $formato, string $nivel, array $get, string $destino): array
{
    if (!usuario_tem_permissao($usuario, 'exportar_relatorios')) {
        return ['ok' => false, 'erro' => 'Sua função não pode exportar relatórios.'];
    }
    if (!isset(relexp_formatos()[$formato]) || !isset(relexp_niveis()[$nivel]) || ($formato === 'pdf' && $nivel === 'dados')) {
        return ['ok' => false, 'erro' => 'Formato inválido (PDF não está disponível para a exportação de dados).'];
    }
    $d = relexp_dados($usuario, $rel, $get, $nivel);
    if (!$d['ok']) {
        return $d;
    }
    $dados = $nivel === 'dados' ? relexp_dados_linhas($usuario, $get) : null;
    $r = relarq_gerar($d, $formato, $destino, $dados);
    $nome = exportacao_nome_arquivo($d['titulo'] . ($nivel === 'dados' ? ' dados' : '')) . '.' . $r['extensao'];
    return ['ok' => true, 'nome' => $nome, 'filtros' => $d['pub'], 'titulo' => $d['titulo']] + $r;
}

/** Processa até $limite pedidos pendentes e apaga os expirados. Texto para o log do cron. */
function relexp_processar_fila(int $limite = 3): string
{
    if (!relexp_fila_instalada()) {
        return 'migração 015 pendente';
    }
    $pdo = db();
    // Expirados: apaga o arquivo.
    $exp = 0;
    foreach ($pdo->query("SELECT id, arquivo FROM relatorio_exportacoes WHERE status = 'pronto' AND expira_em < NOW()")->fetchAll() as $x) {
        if ($x['arquivo'] && is_file(RELEXP_DIR . '/' . basename($x['arquivo']))) {
            @unlink(RELEXP_DIR . '/' . basename($x['arquivo']));
        }
        $pdo->prepare("UPDATE relatorio_exportacoes SET status = 'expirado', arquivo = NULL WHERE id = :id")->execute(['id' => $x['id']]);
        $exp++;
    }
    // Travados (o processo caiu no meio): erro, para não ficar "gerando" para sempre.
    $pdo->exec("UPDATE relatorio_exportacoes SET status = 'erro', erro = 'A geração foi interrompida. Peça de novo.' WHERE status = 'gerando' AND iniciado_em < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
    $ok = 0;
    $falhas = 0;
    $ids = $pdo->query("SELECT id FROM relatorio_exportacoes WHERE status = 'pendente' ORDER BY criado_em, id LIMIT " . max(1, $limite))->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $upd = $pdo->prepare("UPDATE relatorio_exportacoes SET status = 'gerando', iniciado_em = NOW() WHERE id = :id AND status = 'pendente'");
        $upd->execute(['id' => $id]);
        if ($upd->rowCount() !== 1) {
            continue; // outra execução pegou
        }
        $job = $pdo->query('SELECT * FROM relatorio_exportacoes WHERE id = ' . (int) $id)->fetch();
        $arq = bin2hex(random_bytes(16));
        try {
            $u = relexp_usuario((int) $job['usuario_id'], (int) $job['empresa_id']);
            if (!$u) {
                throw new RuntimeException('Usuário inativo.');
            }
            parse_str($job['parametros'], $get);
            if (!is_dir(RELEXP_DIR)) {
                @mkdir(RELEXP_DIR, 0750, true);
            }
            $r = relexp_gerar_arquivo($u, $job['relatorio'], $job['formato'], $job['nivel'], $get, RELEXP_DIR . '/' . $arq);
            if (!$r['ok']) {
                throw new RuntimeException($r['erro']);
            }
            $pdo->prepare("UPDATE relatorio_exportacoes SET status = 'pronto', arquivo = :a, nome_download = :n, linhas = :l, tamanho = :t, concluido_em = NOW(),
                               expira_em = DATE_ADD(NOW(), INTERVAL " . RELEXP_HORAS_VALIDADE . " HOUR) WHERE id = :id")
                ->execute(['a' => $arq, 'n' => mb_substr($r['nome'], 0, 190), 'l' => $r['linhas'], 't' => filesize(RELEXP_DIR . '/' . $arq), 'id' => $id]);
            auditoria_registrar((int) $job['empresa_id'], (int) $job['usuario_id'], 'relatorio.exportado', 'relatorio', null, [
                'relatorio' => $job['relatorio'], 'formato' => $r['extensao'], 'nivel' => $job['nivel'], 'linhas' => $r['linhas'], 'filtros' => $r['filtros'], 'em_segundo_plano' => true]);
            $ok++;
        } catch (Throwable $e) {
            @unlink(RELEXP_DIR . '/' . $arq);
            $pdo->prepare("UPDATE relatorio_exportacoes SET status = 'erro', erro = :e, concluido_em = NOW() WHERE id = :id")
                ->execute(['e' => mb_substr($e->getMessage(), 0, 300), 'id' => $id]);
            $falhas++;
        }
    }
    return $ok . ' gerada(s), ' . $falhas . ' falha(s), ' . $exp . ' expirada(s)';
}

/** Últimos pedidos do usuário (para a Central). */
function relexp_minhas(array $usuario, int $limite = 10): array
{
    if (!relexp_fila_instalada()) {
        return [];
    }
    $stmt = db()->prepare('SELECT * FROM relatorio_exportacoes WHERE usuario_id = :u AND empresa_id = :e ORDER BY criado_em DESC, id DESC LIMIT ' . max(1, $limite));
    $stmt->execute(['u' => $usuario['id'], 'e' => $usuario['empresa_ativa_id']]);
    return $stmt->fetchAll();
}

/** Pedido que o usuário pode baixar agora (dono, pronto, não expirado, token correto) ou null. */
function relexp_para_baixar(array $usuario, int $id, string $token): ?array
{
    if (!relexp_fila_instalada() || $token === '') {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM relatorio_exportacoes WHERE id = :id AND usuario_id = :u AND empresa_id = :e AND status = 'pronto' AND expira_em > NOW()");
    $stmt->execute(['id' => $id, 'u' => $usuario['id'], 'e' => $usuario['empresa_ativa_id']]);
    $job = $stmt->fetch();
    if (!$job || !hash_equals($job['token_hash'], hash('sha256', $token)) || !$job['arquivo'] || !is_file(RELEXP_DIR . '/' . basename($job['arquivo']))) {
        return null;
    }
    return $job;
}
