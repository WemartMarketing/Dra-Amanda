<?php
/**
 * Inteligência de Vendas: regras de correspondência venda × CRM.
 * Funções PURAS (sem banco), reutilizadas pela prévia da Fase 1, pelo
 * motor de cruzamento (Fase 3) e pelos testes.
 *
 * Regras v1 (VENDAS_REGRAS_VERSAO), resumidas:
 *  - Só telefone (formato internacional exato) e e-mail (minúsculo, exato).
 *    Nome NUNCA é prova. CPF não entra na v1.
 *  - Telefone e e-mail no mesmo cadastro ............ forte
 *  - Só telefone, sem conflito ...................... telefone
 *  - Só e-mail, sem conflito ........................ email
 *  - Telefone num cadastro e e-mail em outro, vários
 *    candidatos com contatos diferentes, ou só a
 *    variante do 9º dígito coincide ................. ambigua (revisão humana)
 *  - Nada coincide .................................. sem
 *  - Coincide, mas o 1º cadastro é depois da venda .. posterior (não conta como conversão do CRM)
 */
require_once __DIR__ . '/planilha.php';

const VENDAS_REGRAS_VERSAO = 'v1';

function vendas_status_rotulos(): array
{
    return [
        'forte' => 'Correspondência forte',
        'telefone' => 'Correspondência por telefone',
        'email' => 'Correspondência por e-mail',
        'ambigua' => 'Correspondência ambígua',
        'sem' => 'Sem correspondência',
        'posterior' => 'Correspondência posterior à venda',
    ];
}

/**
 * Forma "com o 9" de um celular antigo de 8 dígitos (só para APONTAR um
 * candidato à revisão — nunca para confirmar). null se não se aplica.
 */
function vendas_telefone_variante(string $bruto): ?string
{
    $t = vendas_telefone($bruto);
    if ($t['problema'] !== 'celular_sem_9') {
        return null;
    }
    $d = preg_replace('/\D+/', '', $bruto);
    if ((strlen($d) === 12) && str_starts_with($d, '55')) {
        $d = substr($d, 2);
    }
    $d = ltrim($d, '0');
    return strlen($d) === 10 ? '55' . substr($d, 0, 2) . '9' . substr($d, 2) : null;
}

/**
 * Classifica uma venda.
 * $porTelefone / $porEmail / $porVariante: [lead_id => ['email' => ?, 'tel' => ?, 'primeira_data' => 'Y-m-d H:i:s'|null]]
 * $emailVenda: e-mail normalizado da venda (ou null).
 * Retorna [status, motivo, lead_ids (ordenados), match_por, primeira_data, cronologia, alertas[]].
 * cronologia: anterior | mesmo_dia | posterior | desconhecida | null (sem vínculo)
 */
function vendas_classificar(array $porTelefone, array $porEmail, ?string $dataVenda, ?string $emailVenda = null, array $porVariante = []): array
{
    $P = array_keys($porTelefone);
    $E = array_keys($porEmail);
    $r = ['status' => 'sem', 'motivo' => 'Nenhum cadastro do CRM com o mesmo telefone ou e-mail.', 'lead_ids' => [], 'match_por' => null,
          'primeira_data' => null, 'cronologia' => null, 'alertas' => []];
    $distintos = function (array $leads, string $campo): int {
        $v = [];
        foreach ($leads as $l) {
            if (!empty($l[$campo])) {
                $v[$l[$campo]] = true;
            }
        }
        return count($v);
    };

    if ($P && $E) {
        if (!array_intersect($P, $E)) {
            $r['status'] = 'ambigua';
            $r['motivo'] = 'O telefone aponta para um cadastro e o e-mail para outro.';
            $r['lead_ids'] = array_values(array_unique(array_merge($P, $E)));
            sort($r['lead_ids']);
            return $r;
        }
        $grupo = $porTelefone + $porEmail;
        $r['status'] = 'forte';
        $r['match_por'] = 'telefone_email';
        $r['motivo'] = 'Telefone e e-mail no mesmo cadastro.';
    } elseif ($P) {
        $grupo = $porTelefone;
        if ($distintos($grupo, 'email') >= 2) {
            $r['status'] = 'ambigua';
            $r['motivo'] = 'O telefone aparece em cadastros com e-mails diferentes (vários candidatos).';
            $r['lead_ids'] = $P;
            sort($r['lead_ids']);
            return $r;
        }
        $r['status'] = 'telefone';
        $r['match_por'] = 'telefone';
        $r['motivo'] = 'Telefone coincide; e-mail não coincide com nenhum cadastro.';
        if ($emailVenda && $distintos($grupo, 'email') === 1) {
            $r['alertas'][] = 'O cadastro do CRM tem outro e-mail.';
        }
    } elseif ($E) {
        $grupo = $porEmail;
        if ($distintos($grupo, 'tel') >= 2) {
            $r['status'] = 'ambigua';
            $r['motivo'] = 'O e-mail aparece em cadastros com telefones diferentes (vários candidatos).';
            $r['lead_ids'] = $E;
            sort($r['lead_ids']);
            return $r;
        }
        $r['status'] = 'email';
        $r['match_por'] = 'email';
        $r['motivo'] = 'E-mail coincide; telefone não coincide com nenhum cadastro.';
        if ($distintos($grupo, 'tel') === 1) {
            $r['alertas'][] = 'O cadastro do CRM tem outro telefone.';
        }
    } elseif ($porVariante) {
        $r['status'] = 'ambigua';
        $r['motivo'] = 'Só coincide se acrescentar/remover o 9º dígito do celular: confirme manualmente.';
        $r['lead_ids'] = array_keys($porVariante);
        sort($r['lead_ids']);
        return $r;
    } else {
        return $r;
    }

    $ids = array_keys($grupo);
    sort($ids);
    $r['lead_ids'] = $ids;
    $datas = array_filter(array_map(fn ($l) => $l['primeira_data'] ?? null, $grupo));
    $r['primeira_data'] = $datas ? min($datas) : null;
    if (!$r['primeira_data'] || !$dataVenda) {
        $r['cronologia'] = 'desconhecida';
        $r['alertas'][] = 'Sem data para comparar a entrada no CRM com a venda.';
    } else {
        $dl = substr($r['primeira_data'], 0, 10);
        $dv = substr($dataVenda, 0, 10);
        $r['cronologia'] = $dl < $dv ? 'anterior' : ($dl === $dv ? 'mesmo_dia' : 'posterior');
        if ($r['cronologia'] === 'posterior') {
            $r['status'] = 'posterior';
            $r['motivo'] .= ' Mas o primeiro cadastro no CRM é posterior à venda.';
        } elseif ($r['cronologia'] === 'mesmo_dia') {
            $r['alertas'][] = 'Entrou no CRM no mesmo dia da venda (a venda não tem hora): revise a cronologia.';
        }
    }
    return $r;
}

/** Dias corridos entre duas datas (calendário de São Paulo; ignora horas quando uma delas é só data). */
function vendas_dias_entre(?string $de, ?string $ate): ?int
{
    if (!$de || !$ate) {
        return null;
    }
    $tz = new DateTimeZone('America/Sao_Paulo');
    $a = new DateTimeImmutable(substr($de, 0, 10), $tz);
    $b = new DateTimeImmutable(substr($ate, 0, 10), $tz);
    return (int) $a->diff($b)->format('%r%a');
}
