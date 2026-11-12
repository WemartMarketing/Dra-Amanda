<?php
/**
 * Regras do WhatsApp — ÚNICO lugar que decide se uma mensagem pode sair.
 * Jornadas, campanhas, o corretor no chat e o disparador chamam
 * wa_verificar_envio(); ninguém envia "por fora".
 *
 * Política aplicada (WhatsApp Business Platform + LGPD):
 *   - mensagem livre (texto/documento) só dentro da janela de 24 h aberta
 *     pela última mensagem DO cliente;
 *   - fora da janela, só template APROVADO;
 *   - template de utilidade (atendimento) exige consentimento de
 *     "atendimento"; de marketing exige consentimento de "marketing" —
 *     nenhum dos dois é presumido só porque o lead preencheu um formulário;
 *   - descadastro bloqueia tudo que é automático e todo template; o
 *     corretor ainda pode responder se o próprio cliente voltar a escrever;
 *   - automações respeitam teto de mensagens por contato e horário de envio.
 *
 * As funções wa_regra_* são puras (sem banco) para serem testadas isoladas.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auditoria.php';

const WA_JANELA_HORAS = 24;

// ------------------------------------------------------------------ telefone

/**
 * Normaliza para só dígitos com DDI (padrão do WhatsApp). Números
 * brasileiros sem DDI ganham 55. Devolve null se não parecer um número.
 */
function wa_normalizar_telefone(?string $bruto, string $ddiPadrao = '55'): ?string
{
    $bruto = trim((string) $bruto);
    if ($bruto === '') {
        return null;
    }
    $internacional = str_starts_with($bruto, '+') || str_starts_with($bruto, '00');
    $d = preg_replace('/\D+/', '', $bruto);
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2);
    }
    if (!$internacional) {
        $d = ltrim($d, '0');                     // 0xx11… (DDD com zero / operadora)
        if (strlen($d) === 10 || strlen($d) === 11) {
            $d = $ddiPadrao . $d;                // DDD + número
        }
    }
    if (strlen($d) < 10 || strlen($d) > 15) {
        return null;
    }
    if (str_starts_with($d, '55')) {
        // Brasil: 55 + DDD (2) + 8 ou 9 dígitos; DDD não começa com 0.
        $resto = substr($d, 2);
        if (!preg_match('/^[1-9][0-9]\d{8,9}$/', $resto)) {
            return null;
        }
    }
    return $d;
}

// ------------------------------------------------------------------ janela de 24 h

function wa_agora(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
}

/** A janela de atendimento está aberta? (última mensagem do cliente há menos de 24 h) */
function wa_regra_janela_aberta(?string $ultimaEntradaEm, ?DateTimeImmutable $agora = null): bool
{
    if (!$ultimaEntradaEm) {
        return false;
    }
    $agora = $agora ?? wa_agora();
    $limite = (new DateTimeImmutable($ultimaEntradaEm, $agora->getTimezone()))->modify('+' . WA_JANELA_HORAS . ' hours');
    return $agora < $limite;
}

function wa_janela_expira_em(?string $ultimaEntradaEm): ?DateTimeImmutable
{
    return $ultimaEntradaEm ? (new DateTimeImmutable($ultimaEntradaEm, new DateTimeZone('America/Sao_Paulo')))->modify('+' . WA_JANELA_HORAS . ' hours') : null;
}

// ------------------------------------------------------------------ descadastro

function wa_texto_normalizado(string $t): string
{
    $t = mb_strtoupper(trim($t));
    $t = strtr($t, ['Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C']);
    return trim(preg_replace('/[^A-Z0-9 ]+/', '', $t));
}

/**
 * A mensagem INTEIRA é uma palavra de descadastro? ("PARAR", "sair.", "Stop")
 * Frases como "não quero parar de receber" NÃO contam — evita falso positivo.
 */
function wa_regra_eh_descadastro(string $mensagem, string $palavras): bool
{
    $msg = wa_texto_normalizado($mensagem);
    if ($msg === '') {
        return false;
    }
    foreach (explode(',', $palavras) as $p) {
        if ($msg === wa_texto_normalizado($p)) {
            return true;
        }
    }
    return false;
}

// ------------------------------------------------------------------ templates

/** Campos que um template pode usar. Nada fora desta lista é aceito. */
function wa_campos_template(): array
{
    return [
        'lead.nome'               => 'Nome completo do cliente',
        'lead.primeiro_nome'      => 'Primeiro nome do cliente',
        'empresa.nome'            => 'Nome da empresa',
        'empreendimento.nome'     => 'Nome do empreendimento',
        'empreendimento.bairro'   => 'Bairro do empreendimento',
        'corretor.nome'           => 'Nome do corretor responsável',
        'corretor.primeiro_nome'  => 'Primeiro nome do corretor',
    ];
}

/** Variáveis {{1}} ou {{nome}} presentes no texto, na ordem em que aparecem. */
function wa_variaveis_do_texto(string $texto): array
{
    preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $texto, $m);
    return array_values(array_unique($m[1]));
}

/**
 * Monta os valores das variáveis e a prévia do texto, VALIDANDO antes de
 * qualquer envio. $mapa: variável → campo (ex.: "1" => "lead.primeiro_nome").
 * $dados: campo → valor.
 *
 * @return array{ok: bool, texto: string, parametros: array<string,string>, erros: string[]}
 */
function wa_regra_renderizar(string $corpo, array $mapa, array $dados): array
{
    $erros = [];
    $parametros = [];
    $campos = wa_campos_template();
    foreach (wa_variaveis_do_texto($corpo) as $var) {
        $campo = $mapa[$var] ?? null;
        if ($campo === null) {
            $erros[] = "A variável {{{$var}}} não está ligada a nenhum dado.";
            continue;
        }
        if (!isset($campos[$campo])) {
            $erros[] = "A variável {{{$var}}} usa um dado desconhecido ($campo).";
            continue;
        }
        $valor = trim((string) ($dados[$campo] ?? ''));
        // A Meta recusa parâmetro com quebra de linha, tabulação ou mais de 4 espaços seguidos.
        $valor = preg_replace('/\s+/u', ' ', $valor);
        if ($valor === '') {
            $erros[] = 'Falta o dado "' . $campos[$campo] . "\" para a variável {{{$var}}}.";
            continue;
        }
        if (mb_strlen($valor) > 200) {
            $valor = mb_substr($valor, 0, 200);
        }
        $parametros[$var] = $valor;
    }
    $texto = preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', fn ($m) => $parametros[$m[1]] ?? $m[0], $corpo);
    return ['ok' => !$erros, 'texto' => $texto, 'parametros' => $parametros, 'erros' => $erros];
}

// ------------------------------------------------------------------ decisão de envio

/**
 * Decide se pode enviar. Função pura: recebe tudo o que precisa.
 *
 * $c = [
 *   'conta_ativa' => bool,
 *   'tipo' => 'livre' | 'template',
 *   'automatica' => bool,               // jornada/campanha (true) ou pessoa no chat (false)
 *   'janela_aberta' => bool,
 *   'opt_out' => bool,
 *   'cliente_escreveu_depois_do_opt_out' => bool,
 *   'template_status' => 'aprovado'|…,  // só para template
 *   'template_categoria' => 'utility'|'marketing'|'authentication',
 *   'consentimento_atendimento' => bool,
 *   'consentimento_marketing' => bool,
 *   'automaticas_24h' => int, 'max_automaticas_24h' => int,
 *   'marketing_7d' => int, 'max_marketing_7d' => int,
 *   'no_horario_envio' => bool, 'proximo_horario' => ?DateTimeImmutable,
 *   'ignorar_horario' => bool,          // boas-vindas imediatas a um lead recém-chegado
 * ]
 *
 * @return array{pode: bool, motivo: string, codigo: string, adiar_para: ?DateTimeImmutable}
 */
function wa_regra_pode_enviar(array $c): array
{
    $nao = fn (string $codigo, string $motivo, ?DateTimeImmutable $adiar = null) => ['pode' => false, 'codigo' => $codigo, 'motivo' => $motivo, 'adiar_para' => $adiar];

    if (empty($c['conta_ativa'])) {
        return $nao('conta_inativa', 'O WhatsApp da empresa não está conectado ou está pausado.');
    }
    $automatica = !empty($c['automatica']);
    $tipo = $c['tipo'] ?? 'livre';

    if (!empty($c['opt_out'])) {
        $reabriu = !empty($c['cliente_escreveu_depois_do_opt_out']) && !empty($c['janela_aberta']);
        if ($automatica || $tipo === 'template' || !$reabriu) {
            return $nao('descadastrado', 'O cliente pediu para não receber mensagens.');
        }
    }

    if ($tipo === 'livre') {
        if (empty($c['janela_aberta'])) {
            return $nao('janela_fechada', 'A janela de 24 horas está fechada: use um template aprovado.');
        }
    } else {
        if (($c['template_status'] ?? '') !== 'aprovado') {
            return $nao('template_nao_aprovado', 'Este template ainda não foi aprovado pela Meta.');
        }
        $cat = $c['template_categoria'] ?? '';
        if ($cat === 'marketing' && empty($c['consentimento_marketing'])) {
            return $nao('sem_consentimento_marketing', 'O cliente não autorizou receber ofertas e novidades.');
        }
        if ($cat !== 'marketing' && empty($c['consentimento_atendimento']) && empty($c['janela_aberta'])) {
            return $nao('sem_consentimento', 'O cliente não autorizou contato pelo WhatsApp.');
        }
        if ($cat === 'marketing' && (int) ($c['marketing_7d'] ?? 0) >= (int) ($c['max_marketing_7d'] ?? 2)) {
            return $nao('limite_marketing', 'Limite de mensagens de marketing para este contato nos últimos 7 dias.');
        }
    }

    if ($automatica) {
        if ((int) ($c['automaticas_24h'] ?? 0) >= (int) ($c['max_automaticas_24h'] ?? 2)) {
            return $nao('limite_frequencia', 'Limite de mensagens automáticas para este contato nas últimas 24 horas.');
        }
        // A resposta imediata a um lead que ACABOU de pedir contato pode sair a
        // qualquer hora (ignorar_horario); follow-ups e marketing respeitam o horário.
        if (empty($c['ignorar_horario']) && array_key_exists('no_horario_envio', $c) && !$c['no_horario_envio']) {
            return $nao('fora_do_horario', 'Fora do horário de envio automático.', $c['proximo_horario'] ?? null);
        }
    }
    return ['pode' => true, 'codigo' => 'ok', 'motivo' => '', 'adiar_para' => null];
}

/**
 * Está no horário de envio automático? Devolve [bool, próximo início].
 * $dias: "1,2,3,4,5,6,7" (1 = segunda). Horário que atravessa a meia-noite é aceito.
 */
function wa_regra_horario_envio(string $inicio, string $fim, string $dias, ?DateTimeImmutable $agora = null): array
{
    $agora = $agora ?? wa_agora();
    $diasOk = array_map('intval', array_filter(explode(',', str_replace(' ', '', $dias)), 'strlen'));
    $hora = $agora->format('H:i:s');
    $dentroHora = $inicio <= $fim ? ($hora >= $inicio && $hora < $fim) : ($hora >= $inicio || $hora < $fim);
    $diaRef = ($inicio > $fim && $hora < $fim) ? $agora->modify('-1 day') : $agora;
    if ($dentroHora && in_array((int) $diaRef->format('N'), $diasOk, true)) {
        return [true, null];
    }
    for ($i = 0; $i <= 8; $i++) {
        $dia = $agora->modify("+$i day");
        $cand = new DateTimeImmutable($dia->format('Y-m-d') . ' ' . $inicio, $agora->getTimezone());
        if ($cand > $agora && in_array((int) $cand->format('N'), $diasOk, true)) {
            return [false, $cand];
        }
    }
    return [false, null];
}

// ------------------------------------------------------------------ banco: config, contato, consentimento

function wa_config(int $empresaId): array
{
    $stmt = db()->prepare('SELECT * FROM whatsapp_config WHERE empresa_id = :e');
    $stmt->execute(['e' => $empresaId]);
    return $stmt->fetch() ?: [
        'empresa_id' => $empresaId, 'max_automaticas_24h' => 2, 'max_marketing_7d' => 2,
        'envio_inicio' => '08:00:00', 'envio_fim' => '21:00:00', 'envio_dias' => '1,2,3,4,5,6,7',
        'palavras_descadastro' => 'PARAR,SAIR,DESCADASTRAR,STOP,CANCELAR,REMOVER', 'resposta_sem_corretor' => null,
    ];
}

/** Conta ativa da empresa (a primeira ativa; a estrutura aceita mais de uma). */
function wa_conta_da_empresa(int $empresaId): ?array
{
    $stmt = db()->prepare("SELECT * FROM whatsapp_contas WHERE empresa_id = :e ORDER BY status = 'ativa' DESC, id LIMIT 1");
    $stmt->execute(['e' => $empresaId]);
    return $stmt->fetch() ?: null;
}

/** Busca ou cria o contato (por empresa + telefone normalizado). */
function wa_contato_obter(int $empresaId, string $telefone, ?int $leadId = null, ?string $nomePerfil = null): ?array
{
    $tel = wa_normalizar_telefone($telefone);
    if ($tel === null) {
        return null;
    }
    $pdo = db();
    $pdo->prepare('INSERT INTO whatsapp_contatos (empresa_id, telefone, lead_id, nome_perfil) VALUES (:e, :t, :l, :n)
                   ON DUPLICATE KEY UPDATE lead_id = COALESCE(lead_id, VALUES(lead_id)), nome_perfil = COALESCE(VALUES(nome_perfil), nome_perfil), atualizado_em = NOW()')
        ->execute(['e' => $empresaId, 't' => $tel, 'l' => $leadId, 'n' => $nomePerfil]);
    $stmt = $pdo->prepare('SELECT * FROM whatsapp_contatos WHERE empresa_id = :e AND telefone = :t');
    $stmt->execute(['e' => $empresaId, 't' => $tel]);
    return $stmt->fetch() ?: null;
}

/** Contato SÓ da empresa informada (isolamento entre empresas). */
function wa_contato(int $empresaId, int $contatoId): ?array
{
    $stmt = db()->prepare('SELECT * FROM whatsapp_contatos WHERE id = :id AND empresa_id = :e');
    $stmt->execute(['id' => $contatoId, 'e' => $empresaId]);
    return $stmt->fetch() ?: null;
}

/**
 * Registra concessão ou revogação (nunca apaga: é o histórico/evidência).
 * Revogar marketing não revoga atendimento; descadastro revoga os dois e marca opt-out.
 */
function wa_consentimento_registrar(int $empresaId, int $contatoId, string $finalidade, string $acao, string $origem,
                                    ?string $evidencia = null, ?int $usuarioId = null, ?int $leadId = null): void
{
    if (!in_array($finalidade, ['atendimento', 'marketing'], true) || !in_array($acao, ['concedido', 'revogado'], true)) {
        throw new InvalidArgumentException('Finalidade ou ação de consentimento inválida.');
    }
    if (!wa_contato($empresaId, $contatoId)) {
        throw new RuntimeException('Contato não pertence a esta empresa.');
    }
    db()->prepare('INSERT INTO whatsapp_consentimentos (empresa_id, contato_id, lead_id, finalidade, acao, origem, evidencia, registrado_por, ip)
                   VALUES (:e, :c, :l, :f, :a, :o, :ev, :u, :ip)')
        ->execute([
            'e' => $empresaId, 'c' => $contatoId, 'l' => $leadId, 'f' => $finalidade, 'a' => $acao, 'o' => $origem,
            'ev' => $evidencia !== null ? mb_substr($evidencia, 0, 500) : null, 'u' => $usuarioId,
            'ip' => PHP_SAPI === 'cli' ? null : ($_SERVER['REMOTE_ADDR'] ?? null),
        ]);
    auditoria_registrar($empresaId, $usuarioId, 'whatsapp.consentimento_' . $acao, 'whatsapp_contato', $contatoId, ['finalidade' => $finalidade, 'origem' => $origem]);
}

/** Estado atual (última linha) de uma finalidade. */
function wa_consentimento_atual(int $empresaId, int $contatoId, string $finalidade): array
{
    $stmt = db()->prepare('SELECT acao, origem, evidencia, criado_em FROM whatsapp_consentimentos
                           WHERE empresa_id = :e AND contato_id = :c AND finalidade = :f ORDER BY id DESC LIMIT 1');
    $stmt->execute(['e' => $empresaId, 'c' => $contatoId, 'f' => $finalidade]);
    $l = $stmt->fetch();
    return ['concedido' => $l && $l['acao'] === 'concedido', 'registro' => $l ?: null];
}

/** Cliente pediu para sair: marca opt-out, revoga as duas finalidades e cancela o que estiver na fila. */
function wa_descadastrar(int $empresaId, int $contatoId, string $motivo, string $origem = 'descadastro', ?int $usuarioId = null): void
{
    $pdo = db();
    $pdo->prepare('UPDATE whatsapp_contatos SET opt_out_em = NOW(), opt_out_motivo = :m, atualizado_em = NOW() WHERE id = :id AND empresa_id = :e')
        ->execute(['m' => mb_substr($motivo, 0, 190), 'id' => $contatoId, 'e' => $empresaId]);
    foreach (['atendimento', 'marketing'] as $f) {
        wa_consentimento_registrar($empresaId, $contatoId, $f, 'revogado', $origem, $motivo, $usuarioId);
    }
    // Mensagens ainda não enviadas para este contato deixam de sair.
    $pdo->prepare("UPDATE whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id
                   SET m.status = 'cancelada', m.erro_texto = 'Contato descadastrado'
                   WHERE cv.contato_id = :c AND cv.empresa_id = :e AND m.status = 'na_fila'")
        ->execute(['c' => $contatoId, 'e' => $empresaId]);
    $pdo->prepare("UPDATE automacao_fila SET status = 'cancelada', resultado = 'Contato descadastrado', concluida_em = NOW()
                   WHERE empresa_id = :e AND referencia_tipo = 'wa_contato' AND referencia_id = :c AND status = 'pendente'")
        ->execute(['e' => $empresaId, 'c' => $contatoId]);
}

/** Quantas mensagens automáticas/de marketing saíram para o contato recentemente (teto de frequência). */
function wa_contagem_recente(int $empresaId, int $contatoId): array
{
    $stmt = db()->prepare("SELECT
            SUM(m.enviada_por IS NULL AND m.criado_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS automaticas_24h,
            SUM(m.categoria = 'marketing' AND m.criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS marketing_7d
        FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id
        WHERE cv.empresa_id = :e AND cv.contato_id = :c AND m.direcao = 'saida'
          AND m.status NOT IN ('falhou','cancelada')");
    $stmt->execute(['e' => $empresaId, 'c' => $contatoId]);
    $l = $stmt->fetch() ?: [];
    return ['automaticas_24h' => (int) ($l['automaticas_24h'] ?? 0), 'marketing_7d' => (int) ($l['marketing_7d'] ?? 0)];
}

/**
 * Reúne os dados do banco e aplica wa_regra_pode_enviar().
 * $opcoes: tipo ('livre'|'template'), automatica (bool), template (linha de whatsapp_templates).
 */
function wa_verificar_envio(int $empresaId, int $contatoId, array $opcoes): array
{
    $contato = wa_contato($empresaId, $contatoId);
    if (!$contato) {
        return ['pode' => false, 'codigo' => 'contato_invalido', 'motivo' => 'Contato não encontrado nesta empresa.', 'adiar_para' => null];
    }
    $conta = wa_conta_da_empresa($empresaId);
    $cfg = wa_config($empresaId);
    $template = $opcoes['template'] ?? null;
    if ($template && (int) $template['empresa_id'] !== $empresaId) {
        return ['pode' => false, 'codigo' => 'template_invalido', 'motivo' => 'Template de outra empresa.', 'adiar_para' => null];
    }
    $cont = wa_contagem_recente($empresaId, $contatoId);
    [$noHorario, $proximo] = wa_regra_horario_envio($cfg['envio_inicio'], $cfg['envio_fim'], $cfg['envio_dias']);
    $janela = wa_regra_janela_aberta($contato['ultima_entrada_em']);
    return wa_regra_pode_enviar([
        'conta_ativa' => $conta && $conta['status'] === 'ativa',
        'tipo' => $opcoes['tipo'] ?? 'livre',
        'automatica' => !empty($opcoes['automatica']),
        'janela_aberta' => $janela,
        'opt_out' => $contato['opt_out_em'] !== null,
        'cliente_escreveu_depois_do_opt_out' => $contato['opt_out_em'] !== null && $contato['ultima_entrada_em'] !== null
            && $contato['ultima_entrada_em'] > $contato['opt_out_em'],
        'template_status' => $template['status'] ?? null,
        'template_categoria' => $template['categoria'] ?? null,
        'consentimento_atendimento' => wa_consentimento_atual($empresaId, $contatoId, 'atendimento')['concedido'],
        'consentimento_marketing' => wa_consentimento_atual($empresaId, $contatoId, 'marketing')['concedido'],
        'automaticas_24h' => $cont['automaticas_24h'],
        'max_automaticas_24h' => (int) $cfg['max_automaticas_24h'],
        'marketing_7d' => $cont['marketing_7d'],
        'max_marketing_7d' => (int) $cfg['max_marketing_7d'],
        'no_horario_envio' => $noHorario,
        'proximo_horario' => $proximo,
        'ignorar_horario' => !empty($opcoes['ignorar_horario']),
    ]);
}
