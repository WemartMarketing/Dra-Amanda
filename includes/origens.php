<?php
/**
 * Origens de aquisição configuráveis por empresa (migração 013).
 *
 * Cada origem detalhada (Google Ads, Site, Telefone, ...) pertence a um
 * "canal" do sistema (leads.origem: meta_ads, manual, importacao, indicacao,
 * outro), que continua sendo gravado para manter compatíveis as telas e
 * relatórios antigos. Origens "do sistema" (usadas pelo Meta Ads, pela
 * importação e como padrão) não podem ser renomeadas nem desativadas.
 *
 * Origem de AQUISIÇÃO (de onde o lead veio) ≠ canal de INTERAÇÃO (WhatsApp,
 * telefone usados no atendimento): aqui só a primeira.
 *
 * Sem a migração 013 tudo cai no seletor antigo (lead_origens()).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/leads.php';

function origens_instaladas(): bool
{
    return modulo_tabela_existe('lead_origens');
}

/** Nome da origem de sistema que corresponde a cada canal (padrão quando não há detalhe). */
function origens_padrao_por_canal(): array
{
    return ['meta_ads' => 'Meta Ads', 'indicacao' => 'Indicação', 'importacao' => 'Importação de base',
            'manual' => 'Cadastro manual (não detalhado)', 'outro' => 'Outros'];
}

/** Cria as origens padrão de uma empresa que ainda não as tem (empresas criadas depois da migração). */
function origens_garantir_padrao(int $empresaId): void
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM lead_origens WHERE empresa_id = :e');
    $stmt->execute(['e' => $empresaId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }
    $padrao = [['Meta Ads', 'meta_ads', 1], ['Google Ads', 'outro', 0], ['WhatsApp', 'outro', 0], ['Telefone', 'manual', 0],
               ['Indicação', 'indicacao', 1], ['Site', 'outro', 0], ['Landing page', 'outro', 0], ['Atendimento presencial', 'manual', 0],
               ['Ação comercial', 'manual', 0], ['Importação de base', 'importacao', 1], ['Cadastro manual (não detalhado)', 'manual', 1], ['Outros', 'outro', 1]];
    $ins = db()->prepare('INSERT IGNORE INTO lead_origens (empresa_id, nome, canal, sistema, ordem) VALUES (:e, :n, :c, :s, :o)');
    foreach ($padrao as $i => [$nome, $canal, $sistema]) {
        $ins->execute(['e' => $empresaId, 'n' => $nome, 'c' => $canal, 's' => $sistema, 'o' => $i + 1]);
    }
}

function origens_listar(int $empresaId, bool $apenasAtivas = true): array
{
    if (!origens_instaladas()) {
        return [];
    }
    origens_garantir_padrao($empresaId);
    $stmt = db()->prepare('SELECT o.*, (SELECT COUNT(*) FROM leads l WHERE l.origem_detalhe_id = o.id AND l.excluido_em IS NULL) AS qtd_leads
                           FROM lead_origens o WHERE o.empresa_id = :e' . ($apenasAtivas ? ' AND o.ativa = 1' : '') . ' ORDER BY o.ordem, o.nome');
    $stmt->execute(['e' => $empresaId]);
    return $stmt->fetchAll();
}

function origem_carregar(int $empresaId, int $id): ?array
{
    if (!origens_instaladas() || $id <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM lead_origens WHERE id = :id AND empresa_id = :e');
    $stmt->execute(['id' => $id, 'e' => $empresaId]);
    return $stmt->fetch() ?: null;
}

/** id da origem de sistema de um canal — usado por Meta Ads, importação e cadastros sem detalhe. */
function origem_id_do_canal(int $empresaId, string $canal): ?int
{
    if (!origens_instaladas()) {
        return null;
    }
    origens_garantir_padrao($empresaId);
    $nome = origens_padrao_por_canal()[$canal] ?? 'Outros';
    $stmt = db()->prepare('SELECT id FROM lead_origens WHERE empresa_id = :e AND nome = :n');
    $stmt->execute(['e' => $empresaId, 'n' => $nome]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/** Cria ou edita. Retorna ['ok'=>true,'id'] ou ['ok'=>false,'erro']. */
function origem_salvar(int $empresaId, ?int $id, string $nome, string $canal, bool $ativa): array
{
    $nome = trim(preg_replace('/\s+/', ' ', $nome));
    if (mb_strlen($nome) < 2 || mb_strlen($nome) > 80) {
        return ['ok' => false, 'erro' => 'O nome precisa ter entre 2 e 80 caracteres.'];
    }
    if (!array_key_exists($canal, lead_origens())) {
        return ['ok' => false, 'erro' => 'Grupo inválido.'];
    }
    $stmt = db()->prepare('SELECT id FROM lead_origens WHERE empresa_id = :e AND nome = :n AND id <> :id');
    $stmt->execute(['e' => $empresaId, 'n' => $nome, 'id' => (int) $id]);
    if ($stmt->fetchColumn()) {
        return ['ok' => false, 'erro' => 'Já existe uma origem com esse nome.'];
    }
    if ($id) {
        $atual = origem_carregar($empresaId, $id);
        if (!$atual) {
            return ['ok' => false, 'erro' => 'Origem não encontrada.'];
        }
        if ((int) $atual['sistema']) {
            // Usada pelas integrações: nome, grupo e status ficam fixos.
            return ['ok' => false, 'erro' => 'Esta origem é usada pelas integrações e não pode ser alterada.'];
        }
        if ($canal !== $atual['canal'] && (int) db()->query('SELECT COUNT(*) FROM leads WHERE origem_detalhe_id = ' . (int) $id)->fetchColumn() > 0) {
            return ['ok' => false, 'erro' => 'O grupo não muda depois que a origem tem leads (os relatórios antigos dependem dele).'];
        }
        db()->prepare('UPDATE lead_origens SET nome = :n, canal = :c, ativa = :a WHERE id = :id AND empresa_id = :e')
            ->execute(['n' => $nome, 'c' => $canal, 'a' => $ativa ? 1 : 0, 'id' => $id, 'e' => $empresaId]);
        return ['ok' => true, 'id' => $id];
    }
    $stmt = db()->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM lead_origens WHERE empresa_id = :e');
    $stmt->execute(['e' => $empresaId]);
    db()->prepare('INSERT INTO lead_origens (empresa_id, nome, canal, ativa, ordem) VALUES (:e, :n, :c, :a, :o)')
        ->execute(['e' => $empresaId, 'n' => $nome, 'c' => $canal, 'a' => $ativa ? 1 : 0, 'o' => (int) $stmt->fetchColumn()]);
    return ['ok' => true, 'id' => (int) db()->lastInsertId()];
}

/** Campos UTM aceitos (nome do campo => tamanho máximo). */
function origens_campos_utm(): array
{
    return ['utm_source' => 120, 'utm_medium' => 120, 'utm_campaign' => 190, 'utm_content' => 190, 'utm_term' => 190];
}

/** Seletor de origem do formulário de lead (detalhada se a migração existir; senão o antigo). */
function render_select_origem(int $empresaId, string $idCampo, ?array $lead = null): void
{
    $origens = origens_listar($empresaId);
    if (!$origens) {
        echo '<select id="' . e($idCampo) . '" name="origem">';
        foreach (lead_origens() as $v => $r) {
            echo '<option value="' . e($v) . '"' . ($lead && $lead['origem'] === $v ? ' selected' : '') . '>' . e($r) . '</option>';
        }
        echo '</select>';
        return;
    }
    $atual = $lead ? (int) ($lead['origem_detalhe_id'] ?? 0) : 0;
    $temAtual = false;
    echo '<select id="' . e($idCampo) . '" name="origem_detalhe_id">';
    if (!$lead) {
        echo '<option value="">Não informada</option>';
    }
    foreach ($origens as $o) {
        $sel = $atual === (int) $o['id'];
        $temAtual = $temAtual || $sel;
        echo '<option value="' . (int) $o['id'] . '"' . ($sel ? ' selected' : '') . '>' . e($o['nome']) . '</option>';
    }
    if ($lead && $atual && !$temAtual && ($o = origem_carregar($empresaId, $atual))) {
        echo '<option value="' . (int) $o['id'] . '" selected>' . e($o['nome']) . ' (desativada)</option>';
    }
    echo '</select>';
}

/** Campos UTM recolhidos (só quando a migração existe). */
function render_campos_utm(?array $lead = null, string $prefixo = 'utm'): void
{
    if (!origens_instaladas()) {
        return;
    }
    $rot = ['utm_source' => 'Fonte (utm_source)', 'utm_medium' => 'Mídia (utm_medium)', 'utm_campaign' => 'Campanha (utm_campaign)',
            'utm_content' => 'Conteúdo (utm_content)', 'utm_term' => 'Termo (utm_term)'];
    $preenchido = $lead && array_filter(array_intersect_key($lead, $rot));
    echo '<details class="utm-box"' . ($preenchido ? ' open' : '') . '><summary>Rastreamento de campanha (UTM) — opcional</summary><div class="form-grid">';
    foreach (origens_campos_utm() as $campo => $max) {
        $id = $prefixo . '-' . $campo;
        echo '<div class="field"><label for="' . e($id) . '">' . e($rot[$campo]) . '</label><input type="text" id="' . e($id) . '" name="' . $campo . '" maxlength="' . $max . '" value="' . e((string) ($lead[$campo] ?? '')) . '"></div>';
    }
    echo '</div></details>';
}
