<?php
/**
 * Botão + diálogo "Exportar" da Inteligência de Vendas (painel, conciliação,
 * jornada). Usa o mesmo envio por JS da Central (form[data-exportar-form]):
 * o arquivo baixa sem sair da tela; erros aparecem no diálogo.
 */
function vendas_exportar_botao(array $usuario, string $tipo, array $campos): void
{
    if (!usuario_tem_permissao($usuario, 'exportar_relatorios') || !usuario_tem_permissao($usuario, 'ver_inteligencia_vendas')) {
        return;
    }
    $id = 'modal-exportar-vendas';
    $jornada = $tipo === 'jornada';
    ?>
    <button type="button" class="btn btn-outline" data-open-modal="<?= $id ?>"><?= icon('download') ?>Exportar</button>
    <div class="modal-overlay" id="<?= $id ?>" hidden>
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="<?= $id ?>-titulo">
            <button type="button" class="overlay-close" data-close-modal="<?= $id ?>" aria-label="Fechar">&times;</button>
            <h2 id="<?= $id ?>-titulo">Exportar <?= e(mb_strtolower(['painel' => 'o painel', 'conciliacao' => 'a conciliação', 'jornada' => 'a jornada'][$tipo] ?? '')) ?></h2>
            <form action="/api/vendas_exportar.php" method="post" data-exportar-form>
                <?= csrf_field() ?>
                <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
                <?php foreach ($campos as $k => $v): if ($v === null || $v === '') continue; ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
                <fieldset class="rel-exp-opcoes"><legend>Formato</legend>
                    <label class="radio-row"><input type="radio" name="formato" value="xlsx" checked> Excel (.xlsx) — abas Resumo, Detalhamento e Notas</label>
                    <label class="radio-row"><input type="radio" name="formato" value="csv"> CSV (separado por ;) — só a tabela mais detalhada</label>
                    <label class="radio-row"><input type="radio" name="formato" value="pdf"> PDF — relatório para leitura e impressão</label>
                </fieldset>
                <?php if (!$jornada): ?>
                    <fieldset class="rel-exp-opcoes"><legend>Conteúdo</legend>
                        <label class="radio-row"><input type="radio" name="nivel" value="resumido" checked> Resumido — só números agregados, sem nomes de clientes</label>
                        <label class="radio-row"><input type="radio" name="nivel" value="completo"> Completo — inclui a lista de vendas com o nome do cliente</label>
                    </fieldset>
                <?php endif; ?>
                <div class="lgpd-aviso" role="note"><?= icon('shield') ?><div>
                    <b><?= $jornada ? 'A jornada tem dados pessoais do cliente.' : 'O conteúdo completo tem o nome dos clientes.' ?></b> Telefone, e-mail e CPF nunca saem. Use só para a análise comercial interna, não compartilhe fora da empresa e apague o arquivo quando não precisar mais. A exportação fica registrada na auditoria. A importação de vendas não autoriza campanhas para esses clientes.
                    <label class="check-line"><input type="checkbox" name="ciente_lgpd" value="1"> Entendi<?= $jornada ? '' : ' (obrigatório para o conteúdo completo)' ?></label>
                </div></div>
                <p class="muted">Leva os mesmos filtros da tela, com cabeçalho (empresa, filtros, data/hora, fuso e quem gerou) e as notas de método.</p>
                <div class="form-alert" data-form-alerta hidden></div>
                <button type="submit" class="btn btn-primary"><?= icon('download') ?>Gerar arquivo</button>
            </form>
        </div>
    </div>
    <?php
}
