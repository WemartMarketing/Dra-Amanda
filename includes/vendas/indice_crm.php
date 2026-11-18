<?php
/**
 * Inteligência de Vendas: índice em memória dos contatos do CRM de UMA
 * empresa (telefone e e-mail normalizados → leads). Usado pela prévia da
 * Fase 1; a Fase 3 grava este índice em tabela.
 *
 * Fontes: leads.telefone, leads.email e o telefone do contato de WhatsApp
 * ligado ao lead (contato secundário). Leads excluídos (LGPD) ficam de fora.
 * Data de entrada do lead = a MENOR entre criado_em e meta_cadastro_em.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/correspondencia.php';

function vendas_indice_crm(int $empresaId): array
{
    $idx = ['tel' => [], 'var' => [], 'email' => [], 'leads' => []];
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, telefone, email, empreendimento_id, criado_em, meta_cadastro_em, oferta_lista_id, origem, origem_detalhe_id, meta_ad_id
                           FROM leads WHERE empresa_id = :e AND excluido_em IS NULL');
    $stmt->execute(['e' => $empresaId]);
    while ($l = $stmt->fetch()) {
        $id = (int) $l['id'];
        $datas = array_filter([$l['criado_em'], $l['meta_cadastro_em']]);
        $t = vendas_telefone((string) $l['telefone']);
        $em = vendas_email((string) $l['email']);
        $idx['leads'][$id] = [
            'tel' => $t['e164'], 'email' => $em['email'], 'primeira_data' => $datas ? min($datas) : null,
            'empreendimento_id' => $l['empreendimento_id'] ? (int) $l['empreendimento_id'] : null,
            'oferta' => (bool) $l['oferta_lista_id'], 'origem' => $l['origem'], 'origem_detalhe_id' => $l['origem_detalhe_id'], 'meta_ad_id' => $l['meta_ad_id'],
        ];
        if ($t['e164']) {
            $idx['tel'][$t['e164']][$id] = true;
        } elseif ($v = vendas_telefone_variante((string) $l['telefone'])) {
            $idx['var'][$v][$id] = true;
        }
        if ($em['email']) {
            $idx['email'][$em['email']][$id] = true;
        }
    }
    if (modulo_tabela_existe('whatsapp_contatos')) {
        $stmt = $pdo->prepare('SELECT c.telefone, c.lead_id FROM whatsapp_contatos c JOIN leads l ON l.id = c.lead_id AND l.excluido_em IS NULL
                               WHERE c.empresa_id = :e AND c.lead_id IS NOT NULL');
        $stmt->execute(['e' => $empresaId]);
        while ($c = $stmt->fetch()) {
            $t = vendas_telefone('+' . preg_replace('/\D/', '', (string) $c['telefone']));
            if ($t['e164']) {
                $idx['tel'][$t['e164']][(int) $c['lead_id']] = true;
            }
        }
    }
    return $idx;
}

/** Candidatos de uma venda normalizada no índice, no formato de vendas_classificar(). */
function vendas_candidatos(array $idx, array $venda): array
{
    $info = fn (array $ids) => array_intersect_key($idx['leads'], $ids);
    $porTel = $porVar = [];
    foreach ($venda['telefones'] as $tel) {
        $porTel += $info($idx['tel'][$tel] ?? []);
        $porVar += $info($idx['var'][$tel] ?? []);
    }
    foreach ($venda['telefones_brutos'] ?? [] as $bruto) {
        if ($v = vendas_telefone_variante($bruto)) {
            $porVar += $info($idx['tel'][$v] ?? []);
        }
    }
    $porEmail = $venda['email'] ? $info($idx['email'][$venda['email']] ?? []) : [];
    return [$porTel, $porEmail, array_diff_key($porVar, $porTel, $porEmail)];
}

