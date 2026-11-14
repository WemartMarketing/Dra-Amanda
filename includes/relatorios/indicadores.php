<?php
/**
 * Dicionário de Indicadores da Central de Relatórios (Etapa 1).
 *
 * Fonte ÚNICA das definições: o motor de relatórios (Etapa 2), as telas
 * (ajuda "?"), as exportações (notas metodológicas) e a documentação
 * (docs/RELATORIOS_DICIONARIO.md, gerado por ferramentas/gerar_dicionario.php)
 * leem daqui. Mudou a regra? Muda aqui — e os testes conferem que todo
 * indicador tem todos os campos.
 *
 * Campos de cada indicador:
 *   nome, categoria, descricao, formula, fonte, data_base (qual data define o
 *   período), filtros, deduplicacao, permissao, ausentes (tratamento de dados
 *   ausentes/limitações), atualizacao, disponibilidade:
 *     disponivel   calculável hoje, com histórico confiável;
 *     parcial      calculável hoje, com limitação explícita (ver "ausentes");
 *     etapa2       depende das estruturas de histórico da Etapa 2 (só vale
 *                  dali para frente — nada é inventado para trás);
 *     indisponivel não existe dado no sistema (ex.: investimento sem integração).
 */

/** Categorias da Central (ordem do menu). */
function relatorio_categorias(): array
{
    return [
        'executiva' => 'Visão Executiva',
        'leads' => 'Leads e Atendimentos',
        'corretores' => 'Corretores e Equipes',
        'empreendimentos' => 'Empreendimentos',
        'plantoes' => 'Plantões e Roletas',
        'marketing' => 'Marketing e Campanhas',
        'whatsapp' => 'WhatsApp e Remarketing',
        'visitas' => 'Visitas e Conversões',
        'perdas' => 'Perdas e Redistribuições',
        'auditoria' => 'Auditoria e Operação',
    ];
}

function relatorio_disponibilidades(): array
{
    return [
        'disponivel' => 'Disponível com histórico confiável',
        'parcial' => 'Disponível com limitação (ver tratamento de ausentes)',
        'etapa2' => 'Depende do histórico estruturado da Etapa 2 (vale a partir da implantação)',
        'indisponivel' => 'Sem dados no sistema — não é calculado',
    ];
}

/** Regras comuns, citadas pelas definições (evita repetir texto). */
function relatorio_regras_gerais(): array
{
    return [
        'permissao_leads' => 'Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; '
            . 'GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; '
            . 'corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam.',
        'permissao_historico' => 'Indicadores históricos de perdas/transferências usam a estrutura ANTERIOR do lead '
            . '(rbac_transferencias_scope): o gestor anterior vê só números agregados, nunca o cadastro do lead transferido.',
        'filtros_lead' => 'Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa.',
        'excluidos' => 'Leads excluídos (excluido_em preenchido) não entram em nenhum indicador; anonimizados (LGPD) entram só nas contagens.',
        'contato_valido' => 'CONTATO EFETIVO = evidência de que o cliente foi alcançado por uma pessoa: (a) o cliente escreveu no WhatsApp oficial; '
            . '(b) o corretor confirmou "Sim, o cliente respondeu" na central de pendências; (c) o corretor registrou atendimento com texto e moveu o lead '
            . 'para "Primeiro contato" ou etapa posterior; (d) visita realizada. NÃO são contato efetivo: mensagem automática, clique em Ligar/WhatsApp, '
            . '"Não respondeu", observação, mudança de etapa feita por jornada automática.',
        'feedback_valido' => 'ATUAÇÃO (feedback válido, regra da recuperação) = ação do corretor que comprova trabalho no lead: registrar atendimento, '
            . 'mudar etapa, resolver visita/retorno, responder pendência (inclusive "Não respondeu"), mensagem REAL pelo WhatsApp. '
            . 'Atuação ≠ contato efetivo: tentativa sem resposta é atuação, mas não é contato.',
        'fuso' => 'Datas gravadas e exibidas no fuso America/Sao_Paulo (UTC−03:00). Período = [início 00:00, fim 23:59:59].',
    ];
}

/**
 * O dicionário. Chaves estáveis (usadas por telas, exportações e testes).
 */
function relatorio_indicadores(): array
{
    $R = relatorio_regras_gerais();
    $periodoLead = 'Data de entrada do lead (leads.criado_em).';
    $realtime = 'Tempo real (consulta direta ao banco).';
    $i = [];

    // ============================================================ Visão executiva
    $i['comparacao_periodo'] = ['nome' => 'Comparação com o período anterior', 'categoria' => 'executiva',
        'descricao' => 'Variação de cada indicador em relação ao período imediatamente anterior de mesma duração (ex.: "leads +15%", "taxa de 1º atendimento de 78% para 65%").',
        'formula' => 'Contagens: (atual − anterior) ÷ anterior × 100. Taxas: diferença em pontos percentuais (p.p.), mostrando os dois valores. Mês atual compara com os mesmos dias do mês anterior.',
        'fonte' => 'O mesmo cálculo do indicador, aplicado aos dois períodos com os mesmos filtros e o mesmo recorte de permissão.', 'data_base' => 'A do indicador comparado.',
        'filtros' => 'Os do relatório (iguais nos dois períodos).', 'deduplicacao' => 'A do indicador.', 'permissao' => $R['permissao_leads'],
        'ausentes' => 'Só é exibida com base válida: período anterior com pelo menos 5 registros (taxas: 5 na base) e totalmente coberto pelos dados do CRM (sem lacuna de histórico). Senão: "sem base de comparação".',
        'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['resumo_executivo'] = ['nome' => 'Resumo executivo', 'categoria' => 'executiva',
        'descricao' => 'Painel da diretoria: recebidos, únicos, distribuídos, aguardando, com atendimento, sem atendimento, em atendimento, visitas (agendadas, realizadas, canceladas, ausentes), contratos, conversão, tempo até o 1º atendimento, recuperados, perdidos por inatividade e leads que receberam remarketing.',
        'formula' => 'Composição dos indicadores do dicionário, cada um com a sua definição e a sua marca de disponibilidade.', 'fonte' => 'Ver cada indicador.',
        'data_base' => 'A de cada indicador (indicada no próprio card).', 'filtros' => $R['filtros_lead'], 'deduplicacao' => 'A de cada indicador.', 'permissao' => $R['permissao_leads'],
        'ausentes' => 'Indicador parcial ou indisponível aparece marcado, nunca misturado com resultado confirmado.', 'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];

    // ============================================================ Empreendimentos
    $i['comparativo_empreendimentos'] = ['nome' => 'Comparativo entre empreendimentos', 'categoria' => 'empreendimentos',
        'descricao' => 'Dois ou mais empreendimentos lado a lado: geração de leads, aproveitamento (atendimento/contato), comparecimento em visitas e conversão.',
        'formula' => 'Os mesmos indicadores do dicionário calculados por empreendimento, no mesmo período e filtros; destaque do maior valor por linha só quando as bases são comparáveis.',
        'fonte' => 'leads.empreendimento_id + fontes de cada indicador.', 'data_base' => 'A de cada indicador.', 'filtros' => $R['filtros_lead'] . ' Lista de empreendimentos.',
        'deduplicacao' => 'Lead pertence ao empreendimento do cadastro (não muda com transferência).', 'permissao' => $R['permissao_leads'] . ' GP de Produto compara só os seus empreendimentos.',
        'ausentes' => 'Empreendimento com base menor que o mínimo (5 leads) mostra "base insuficiente" em vez de taxa. Leads sem empreendimento aparecem numa coluna própria.',
        'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['leads_por_plantao'] = ['nome' => 'Leads por plantão', 'categoria' => 'empreendimentos',
        'descricao' => 'Leads do empreendimento distribuídos por cada plantão (via roleta que entregou).', 'formula' => 'COUNT(roleta_distribuicoes) por plantão da roleta, para leads do empreendimento.',
        'fonte' => 'roleta_distribuicoes, roletas.plantao_id', 'data_base' => 'Data da distribuição.', 'filtros' => $R['filtros_lead'] . ' Plantão.', 'deduplicacao' => 'Lead conta uma vez por plantão.',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'Leads entregues fora da roleta ficam em "sem plantão". O vínculo roleta→plantão é o atual (sem versões).', 'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];

    // ============================================================ Leads e atendimentos
    $i['leads_recebidos'] = ['nome' => 'Leads recebidos', 'categoria' => 'leads',
        'descricao' => 'Cadastros de leads que entraram no CRM no período, por qualquer origem.',
        'formula' => 'COUNT(leads) com criado_em no período.',
        'fonte' => 'leads', 'data_base' => $periodoLead, 'filtros' => $R['filtros_lead'],
        'deduplicacao' => 'Nenhuma: cada cadastro conta. O Meta Ads não cria cadastro novo para telefone já existente (conta em "Reentradas").',
        'permissao' => $R['permissao_leads'], 'ausentes' => $R['excluidos'], 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['leads_unicos'] = ['nome' => 'Leads únicos', 'categoria' => 'leads',
        'descricao' => 'Pessoas distintas entre os leads recebidos no período.',
        'formula' => 'COUNT(DISTINCT chave), chave = telefone normalizado (só dígitos, com DDI 55); sem telefone válido, e-mail em minúsculas; sem os dois, o próprio cadastro.',
        'fonte' => 'leads', 'data_base' => $periodoLead, 'filtros' => $R['filtros_lead'],
        'deduplicacao' => 'Pelo telefone normalizado dentro da mesma empresa. Nunca entre empresas.',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'Telefones digitados de formas diferentes são normalizados; números inválidos caem para e-mail/cadastro.',
        'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['reentradas'] = ['nome' => 'Reentradas (cadastros repetidos)', 'categoria' => 'leads',
        'descricao' => 'Cadastros que chegaram de novo para um cliente que já existia na empresa (não viraram lead novo).',
        'formula' => 'Avisos do Meta com status "duplicado" + duplicados das importações de oferta ativa, no período.',
        'fonte' => 'meta_eventos (status=duplicado, recebido_em); oferta_listas (duplicados, criado_em)', 'data_base' => 'Data de recebimento do aviso / da importação.',
        'filtros' => 'Empresa, período (empreendimento só para importações).', 'deduplicacao' => 'Cada aviso conta uma vez (leadgen_id único).',
        'permissao' => 'Só escopos de empresa inteira (toda_empresa/todos_produtos): o aviso não tem corretor.', 'ausentes' => 'Cadastros manuais repetidos são bloqueados na tela e não deixam registro.',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['leads_distribuidos'] = ['nome' => 'Leads distribuídos', 'categoria' => 'leads',
        'descricao' => 'Leads recebidos no período que foram entregues a algum corretor (em qualquer momento).',
        'formula' => 'Leads recebidos com pelo menos uma atribuição registrada (roleta, cadastro com responsável, transferência, oferta ativa).',
        'fonte' => 'Hoje: leads.responsavel_id (dono atual) + roleta_distribuicoes. Etapa 2: lead_atribuicoes.', 'data_base' => $periodoLead, 'filtros' => $R['filtros_lead'],
        'deduplicacao' => 'Lead conta uma vez, mesmo com várias atribuições.', 'permissao' => $R['permissao_leads'],
        'ausentes' => 'Hoje um lead recuperado que ainda está na fila aparece como "não distribuído" embora já tenha tido dono. Corrigido com lead_atribuicoes (Etapa 2).',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['aguardando_distribuicao'] = ['nome' => 'Aguardando distribuição', 'categoria' => 'leads',
        'descricao' => 'Leads sem corretor neste momento, esperando na fila da roleta.',
        'formula' => 'COUNT(roleta_fila sem resolvido_em) com lead ativo e sem responsável.',
        'fonte' => 'roleta_fila, leads', 'data_base' => 'Posição atual (agora) — não depende do período.', 'filtros' => 'Empresa, empreendimento, roleta.',
        'deduplicacao' => 'Um registro de fila por lead (chave única).', 'permissao' => $R['permissao_leads'] . ' Leads sem dono só aparecem para escopos acima de corretor.',
        'ausentes' => 'Lead sem empreendimento e sem roleta também é contado (motivo "nenhuma roleta atende").', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['leads_com_atuacao'] = ['nome' => 'Leads trabalhados (com atuação)', 'categoria' => 'leads',
        'descricao' => 'Leads em que o corretor registrou pelo menos uma atuação válida.',
        'formula' => 'Leads com primeiro feedback válido registrado. Definição de atuação: ' . $R['feedback_valido'],
        'fonte' => 'Hoje: leads.primeiro_contato_em (do dono ATUAL). Etapa 2: por atribuição em lead_atribuicoes.', 'data_base' => $periodoLead . ' (coorte).',
        'filtros' => $R['filtros_lead'], 'deduplicacao' => 'Lead conta uma vez.', 'permissao' => $R['permissao_leads'],
        'ausentes' => 'LIMITAÇÃO ATUAL: primeiro_contato_em é zerado quando o lead muda de corretor — a atuação do corretor anterior se perde. A partir da Etapa 2 fica gravada por atribuição.',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['contatos_efetivos'] = ['nome' => 'Leads com contato efetivo', 'categoria' => 'leads',
        'descricao' => 'Leads em que houve evidência de que o cliente foi alcançado por uma pessoa.',
        'formula' => 'Leads com pelo menos um evento de contato efetivo. ' . $R['contato_valido'],
        'fonte' => 'whatsapp_mensagens (entrada), corretor_pendencias (resposta=respondeu), auditoria lead.etapa_alterada (para categoria ≥ primeiro_contato, por usuário), agenda_compromissos (visita realizada).',
        'data_base' => $periodoLead . ' (coorte) ou data do evento (visão de movimentação).', 'filtros' => $R['filtros_lead'],
        'deduplicacao' => 'Lead conta uma vez; vários contatos não somam.', 'permissao' => $R['permissao_leads'],
        'ausentes' => 'Mudanças de etapa anteriores à auditoria estruturada (Etapa 3 do projeto) não têm usuário/categoria: esses leads ficam como "sem evidência", nunca como contatados por suposição.',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['tentativas_contato'] = ['nome' => 'Tentativas de contato', 'categoria' => 'leads',
        'descricao' => 'Ações do corretor para alcançar o cliente, com ou sem sucesso.',
        'formula' => 'Eventos: clique em Ligar/WhatsApp, atendimento registrado, "Não respondeu"/"Registrar tentativa" nas pendências, mensagem humana real no WhatsApp.',
        'fonte' => 'lead_eventos (ligacao, whatsapp, contato), corretor_pendencias (nao_respondeu, tentativa), whatsapp_mensagens (saída humana não simulada).',
        'data_base' => 'Data do evento.', 'filtros' => $R['filtros_lead'] . ' Usuário que fez a ação.', 'deduplicacao' => 'Conta eventos (não leads). Também há a versão "leads com tentativa" (lead uma vez).',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'Clique em Ligar indica intenção, não ligação completada (o CRM não acessa a operadora).', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['sem_atendimento'] = ['nome' => 'Leads sem atendimento', 'categoria' => 'leads',
        'descricao' => 'Leads com corretor há mais de N horas (padrão 2) e nenhuma atuação válida.',
        'formula' => 'responsavel_id preenchido AND primeiro_contato_em vazio AND atribuido_em < agora − N horas.',
        'fonte' => 'leads', 'data_base' => 'Posição atual, para os leads recebidos no período.', 'filtros' => $R['filtros_lead'],
        'deduplicacao' => 'Lead conta uma vez.', 'permissao' => $R['permissao_leads'], 'ausentes' => 'Leads sem atribuido_em (cadastros antigos) não entram: aparecem no painel de qualidade.',
        'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['em_atendimento'] = ['nome' => 'Leads em atendimento', 'categoria' => 'leads',
        'descricao' => 'Leads hoje em etapas abertas de trabalho (categorias Primeiro contato e Em atendimento).',
        'formula' => 'COUNT(leads) por categoria da etapa atual.', 'fonte' => 'leads.etapa (categoria) / leads.etapa_id', 'data_base' => 'Posição atual.',
        'filtros' => $R['filtros_lead'], 'deduplicacao' => 'Lead conta uma vez.', 'permissao' => $R['permissao_leads'],
        'ausentes' => 'Etapas personalizadas são agrupadas pela categoria escolhida pela empresa no cadastro da etapa.', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['tempo_primeiro_contato'] = ['nome' => 'Tempo até o primeiro atendimento', 'categoria' => 'leads',
        'descricao' => 'Tempo entre o lead chegar ao corretor e a primeira atuação válida dele.',
        'formula' => 'Média e MEDIANA de (primeiro_contato_em − atribuido_em), só leads com as duas datas. A mediana é o número principal (resistente a casos extremos).',
        'fonte' => 'Hoje: leads (dono atual). Etapa 2: lead_atribuicoes (cada corretor que recebeu).', 'data_base' => $periodoLead,
        'filtros' => $R['filtros_lead'], 'deduplicacao' => 'Um tempo por lead (por atribuição na Etapa 2).', 'permissao' => $R['permissao_leads'],
        'ausentes' => 'Leads sem atuação ficam FORA da média (aparecem em "sem atendimento"). Mesmo limite de primeiro_contato_em ser zerado na troca de corretor.',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['taxa_primeiro_contato'] = ['nome' => 'Taxa de primeiro atendimento', 'categoria' => 'leads',
        'descricao' => 'Percentual dos leads distribuídos que tiveram atuação válida.',
        'formula' => 'leads únicos com primeiro feedback válido ÷ leads distribuídos elegíveis no período × 100. Elegível = distribuído há mais de N horas.',
        'fonte' => 'leads (Etapa 2: lead_atribuicoes)', 'data_base' => $periodoLead, 'filtros' => $R['filtros_lead'],
        'deduplicacao' => 'Leads únicos.', 'permissao' => $R['permissao_leads'], 'ausentes' => 'Sem leads elegíveis → "—" (não 0%).', 'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['taxa_contato_efetivo'] = ['nome' => 'Taxa de contato efetivo', 'categoria' => 'leads',
        'descricao' => 'Percentual dos leads distribuídos em que o cliente foi efetivamente alcançado.',
        'formula' => 'leads com contato efetivo ÷ leads distribuídos elegíveis × 100.', 'fonte' => 'Ver "Leads com contato efetivo".', 'data_base' => $periodoLead,
        'filtros' => $R['filtros_lead'], 'deduplicacao' => 'Leads únicos.', 'permissao' => $R['permissao_leads'], 'ausentes' => 'Sem base → "—".',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];

    // ============================================================ Corretores e equipes
    $i['recebidos_corretor'] = ['nome' => 'Leads recebidos pelo corretor (histórico)', 'categoria' => 'corretores',
        'descricao' => 'Quantos leads o corretor RECEBEU no período, mesmo que depois tenham saído da carteira dele.',
        'formula' => 'COUNT(atribuições ao corretor no período): roleta, cadastro com responsável, transferência recebida, oferta ativa, redistribuição.',
        'fonte' => 'Hoje: roleta_distribuicoes + lead_transferencias (para_usuario_id) + auditoria lead.criado (responsável). Etapa 2: lead_atribuicoes (tabela única, com retroalimentação dessas fontes).',
        'data_base' => 'Data da atribuição.', 'filtros' => 'Empresa, período, corretor, equipe/gerente/superintendência NA DATA da atribuição, empreendimento, origem, forma de recebimento.',
        'deduplicacao' => 'Lead recebido duas vezes pelo mesmo corretor no período conta 1 em "leads únicos recebidos" e 2 em "recebimentos".',
        'permissao' => 'Corretor: só os próprios números. Gestor: corretores da sua estrutura NA DATA da atribuição (números agregados; o cadastro só se o lead ainda estiver no seu recorte).',
        'ausentes' => 'NÃO usar a carteira atual (o Dashboard atual faz isso e será corrigido). Leads distribuídos pela oferta ativa antes da Etapa 2 só têm evento em texto: entram via lead_eventos tipo atribuição, marcados como "reconstruído".',
        'atualizacao' => $realtime, 'disponibilidade' => 'etapa2'];
    $i['carteira_atual'] = ['nome' => 'Carteira atual do corretor', 'categoria' => 'corretores',
        'descricao' => 'Leads que estão com o corretor agora.', 'formula' => 'COUNT(leads) com responsavel_id = corretor, não excluídos, por categoria de etapa.',
        'fonte' => 'leads', 'data_base' => 'Posição atual.', 'filtros' => 'Empresa, corretor, empreendimento, etapa.', 'deduplicacao' => 'Lead conta uma vez.',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'Não é histórico: use "Leads recebidos pelo corretor" para produtividade.', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['perdidos_corretor'] = ['nome' => 'Leads perdidos pelo corretor', 'categoria' => 'corretores',
        'descricao' => 'Leads que saíram da carteira do corretor no período, separados por motivo.',
        'formula' => 'COUNT(lead_transferencias) com de_usuario_id = corretor, por motivo (inatividade, administrativa, mudança de equipe, roleta).',
        'fonte' => 'lead_transferencias', 'data_base' => 'Data da transferência.', 'filtros' => 'Empresa, período, corretor/estrutura ANTERIOR, empreendimento, motivo.',
        'deduplicacao' => 'Cada transferência conta.', 'permissao' => $R['permissao_historico'],
        'ausentes' => 'Só "inatividade" é perda por falta de atuação. Transferência administrativa NÃO é contada como negligência.', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['recebidos_redistribuicao'] = ['nome' => 'Leads recebidos por redistribuição', 'categoria' => 'corretores',
        'descricao' => 'Leads que chegaram ao corretor depois de terem sido perdidos por outro.',
        'formula' => 'COUNT(roleta_distribuicoes origem=recuperacao para o corretor) + transferências recebidas de leads que já tiveram dono.',
        'fonte' => 'roleta_distribuicoes, lead_transferencias', 'data_base' => 'Data da distribuição.', 'filtros' => 'Empresa, período, corretor/estrutura, empreendimento.',
        'deduplicacao' => 'Cada recebimento conta.', 'permissao' => 'Como "Leads recebidos pelo corretor".', 'ausentes' => '—', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['tempo_atualizacao'] = ['nome' => 'Tempo médio entre atualizações', 'categoria' => 'corretores',
        'descricao' => 'Intervalo médio entre duas atuações válidas consecutivas do corretor nos leads da carteira.',
        'formula' => 'Média dos intervalos entre eventos de atuação do mesmo lead, feitos pelo corretor responsável na época.',
        'fonte' => 'lead_eventos (contato, mudanca_etapa, visita, agenda) com usuario_id; auditoria lead.etapa_alterada.', 'data_base' => 'Data do evento.',
        'filtros' => 'Empresa, período, corretor/estrutura.', 'deduplicacao' => 'Eventos no mesmo minuto contam como um.', 'permissao' => $R['permissao_leads'],
        'ausentes' => 'Lead com um único evento não gera intervalo. Pendências e mensagens reais entram a partir da Etapa 2 (evento unificado).', 'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['ranking_corretores'] = ['nome' => 'Ranking de corretores', 'categoria' => 'corretores',
        'descricao' => 'Comparação entre corretores por critério explícito escolhido pelo usuário (nunca um "score" opaco).',
        'formula' => 'Ordenação por um indicador do dicionário (ex.: taxa de contato efetivo, visitas realizadas, contratos), com a base (leads recebidos) sempre visível.',
        'fonte' => 'Indicadores de corretor acima.', 'data_base' => 'A do indicador escolhido.', 'filtros' => 'Os do indicador + mínimo de leads para entrar no ranking (padrão 5).',
        'deduplicacao' => 'A do indicador.', 'permissao' => 'Gestor vê só a sua estrutura; corretor vê só a própria posição (sem nomes dos colegas).',
        'ausentes' => 'Corretor abaixo do mínimo aparece como "base insuficiente".', 'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];

    // ============================================================ Visitas e conversões
    $i['visitas_agendadas'] = ['nome' => 'Visitas agendadas', 'categoria' => 'visitas',
        'descricao' => 'Visitas criadas na agenda no período.', 'formula' => 'COUNT(agenda_compromissos tipo=visita, criado_em no período, reagendado_de vazio).',
        'fonte' => 'agenda_compromissos', 'data_base' => 'Data em que a visita foi marcada (criado_em), não a data da visita.', 'filtros' => $R['filtros_lead'] . ' Corretor que marcou.',
        'deduplicacao' => 'Reagendamento não conta como nova visita agendada.', 'permissao' => $R['permissao_leads'], 'ausentes' => '—', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['visitas_realizadas'] = ['nome' => 'Visitas realizadas', 'categoria' => 'visitas',
        'descricao' => 'Visitas marcadas como realizadas no período.', 'formula' => 'COUNT(visitas status=realizado, concluido_em no período).',
        'fonte' => 'agenda_compromissos', 'data_base' => 'Data em que a visita foi concluída (concluido_em).', 'filtros' => $R['filtros_lead'],
        'deduplicacao' => 'Cada visita conta.', 'permissao' => $R['permissao_leads'], 'ausentes' => 'Visita no passado ainda "agendada" fica em "Visitas sem situação" (qualidade), não em realizadas.',
        'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['visitas_canceladas'] = ['nome' => 'Visitas canceladas / reagendadas / cliente ausente', 'categoria' => 'visitas',
        'descricao' => 'Desfechos das visitas no período.', 'formula' => 'COUNT por status (cancelado, reagendado, cliente_ausente) com concluido_em no período.',
        'fonte' => 'agenda_compromissos', 'data_base' => 'concluido_em.', 'filtros' => $R['filtros_lead'], 'deduplicacao' => 'Cada visita conta.',
        'permissao' => $R['permissao_leads'], 'ausentes' => '—', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['visitas_confirmadas'] = ['nome' => 'Visitas confirmadas', 'categoria' => 'visitas',
        'descricao' => 'Visitas confirmadas com o cliente antes da data.', 'formula' => 'Requer o status "confirmada" na agenda.', 'fonte' => '— (não existe hoje).',
        'data_base' => '—', 'filtros' => '—', 'deduplicacao' => '—', 'permissao' => $R['permissao_leads'],
        'ausentes' => 'A agenda não tem confirmação. Proposta da Etapa 2: status/campo "confirmada_em". Até lá: "não disponível".', 'atualizacao' => '—', 'disponibilidade' => 'indisponivel'];
    $i['taxa_comparecimento'] = ['nome' => 'Taxa de comparecimento', 'categoria' => 'visitas',
        'descricao' => 'Das visitas com desfecho no período, quantas o cliente compareceu.', 'formula' => 'realizadas ÷ (realizadas + cliente ausente) × 100.',
        'fonte' => 'agenda_compromissos', 'data_base' => 'concluido_em.', 'filtros' => $R['filtros_lead'], 'deduplicacao' => 'Cada visita conta.',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'Canceladas e reagendadas ficam fora da base (não houve encontro marcado naquele dia). Sem base → "—".',
        'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['contratos_assinados'] = ['nome' => 'Contratos assinados', 'categoria' => 'visitas',
        'descricao' => 'Vendas concluídas no período.',
        'formula' => 'Hoje: leads que entraram na etapa de categoria "contrato assinado" no período (pela auditoria de etapas). Etapa 2: registros formais em lead_contratos (fonte oficial quando existir).',
        'fonte' => 'auditoria lead.etapa_alterada (para=contrato_assinado) e leads.contrato_assinado_em; Etapa 2: lead_contratos.', 'data_base' => 'Data da assinatura (data da mudança de etapa até existir o registro formal).',
        'filtros' => $R['filtros_lead'] . ' Corretor responsável na data da assinatura.', 'deduplicacao' => 'Um contrato por lead por entrada na etapa; idas e voltas no Kanban não duplicam.',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'LIMITAÇÃO ATUAL: contrato_assinado_em é apagado se o lead sair da etapa. Mover no Kanban não é validação formal: a tela indica "contrato pela etapa do Kanban" até a empresa usar o registro de contrato.',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['taxa_conversao'] = ['nome' => 'Taxa de conversão em contrato', 'categoria' => 'visitas',
        'descricao' => 'Duas leituras, sempre identificadas: (1) COORTE — dos leads recebidos no período, quantos já viraram contrato (até hoje); (2) PERÍODO — contratos do período ÷ leads recebidos do período.',
        'formula' => '(1) leads da coorte com contrato ÷ leads da coorte × 100; (2) contratos com data no período ÷ leads recebidos no período × 100.',
        'fonte' => 'leads, contratos (ver acima)', 'data_base' => '(1) criado_em do lead; (2) data do contrato e criado_em.', 'filtros' => $R['filtros_lead'],
        'deduplicacao' => 'Leads únicos.', 'permissao' => $R['permissao_leads'], 'ausentes' => 'Coortes recentes ainda estão "maturando" (a tela mostra a idade da coorte).',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['taxa_visita_contrato'] = ['nome' => 'Conversão de visita em contrato', 'categoria' => 'visitas',
        'descricao' => 'Dos leads com visita realizada, quantos assinaram contrato.', 'formula' => 'leads com visita realizada e contrato ÷ leads com visita realizada × 100 (coorte pela data da visita).',
        'fonte' => 'agenda_compromissos, contratos', 'data_base' => 'concluido_em da visita.', 'filtros' => $R['filtros_lead'], 'deduplicacao' => 'Leads únicos.',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'Sem base → "—".', 'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['vgv'] = ['nome' => 'VGV dos contratos', 'categoria' => 'visitas',
        'descricao' => 'Valor Geral de Vendas dos contratos assinados (não é receita nem lucro).', 'formula' => 'SUM(valor do contrato) dos contratos com data no período.',
        'fonte' => 'Etapa 2: lead_contratos.valor_vgv.', 'data_base' => 'Data da assinatura.', 'filtros' => $R['filtros_lead'], 'deduplicacao' => 'Um valor por contrato.',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'Hoje não existe valor de contrato no CRM: "VGV não disponível".', 'atualizacao' => '—', 'disponibilidade' => 'indisponivel'];

    // ============================================================ Funil
    $i['funil_posicao'] = ['nome' => 'Funil — posição atual', 'categoria' => 'leads',
        'descricao' => 'Quantos leads estão em cada etapa AGORA (ou numa data de corte).', 'formula' => 'COUNT(leads) por etapa atual. Data de corte: reconstruída pelas transições registradas.',
        'fonte' => 'leads.etapa_id; data de corte: lead_etapa_historico (Etapa 2).', 'data_base' => 'Agora / data de corte.', 'filtros' => $R['filtros_lead'],
        'deduplicacao' => 'Lead em uma etapa só.', 'permissao' => $R['permissao_leads'], 'ausentes' => 'Data de corte no passado só a partir do histórico estruturado (antes dele: "não reconstruível").',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['funil_movimentacao'] = ['nome' => 'Funil — movimentações no período', 'categoria' => 'leads',
        'descricao' => 'Entradas, saídas, avanços e retrocessos entre etapas durante o período (não confundir com a posição atual).',
        'formula' => 'COUNT(transições) por etapa de origem/destino; avanço = destino com ordem maior; retrocesso = menor.',
        'fonte' => 'auditoria lead.etapa_alterada (de_etapa_id, para_etapa_id); Etapa 2: lead_etapa_historico (inclui mudanças feitas por jornadas, que hoje só ficam em texto).',
        'data_base' => 'Data da transição.', 'filtros' => $R['filtros_lead'], 'deduplicacao' => 'Cada transição conta; "leads que passaram pela etapa" conta o lead uma vez.',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'Transições feitas por jornada automática não estão na auditoria (ficam em lead_eventos tipo jornada) — corrigido na Etapa 2.',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['tempo_etapa'] = ['nome' => 'Tempo médio em cada etapa', 'categoria' => 'leads',
        'descricao' => 'Quanto tempo os leads ficaram em cada etapa antes de sair.', 'formula' => 'Mediana de (saída − entrada) por etapa, só para permanências completas no período.',
        'fonte' => 'lead_etapa_historico (Etapa 2).', 'data_base' => 'Data de saída da etapa.', 'filtros' => $R['filtros_lead'], 'deduplicacao' => 'Cada permanência conta.',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'A entrada na primeira etapa é a criação do lead. Permanências iniciadas antes do histórico estruturado não são calculadas.',
        'atualizacao' => $realtime, 'disponibilidade' => 'etapa2'];
    $i['leads_parados'] = ['nome' => 'Leads parados', 'categoria' => 'leads',
        'descricao' => 'Leads em etapas abertas sem nenhuma atuação há mais de X dias (padrão: o prazo da recuperação).',
        'formula' => 'Etapa aberta AND COALESCE(ultimo_feedback_em, criado_em) < agora − X dias.', 'fonte' => 'leads, recuperacao_regras', 'data_base' => 'Posição atual.',
        'filtros' => $R['filtros_lead'], 'deduplicacao' => 'Lead conta uma vez.', 'permissao' => $R['permissao_leads'], 'ausentes' => '—', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];

    // ============================================================ Perdas e redistribuições
    $i['perdidos_inatividade'] = ['nome' => 'Perdidos por inatividade', 'categoria' => 'perdas',
        'descricao' => 'Leads retirados do corretor pela recuperação automática (sem feedback no prazo, ou corretor desativado).',
        'formula' => 'COUNT(lead_transferencias motivo=inatividade) no período.', 'fonte' => 'lead_transferencias', 'data_base' => 'Data da perda.',
        'filtros' => 'Empresa, período, corretor/equipe/gerente/superintendência ANTERIORES, empreendimento.', 'deduplicacao' => 'Cada perda conta (o mesmo lead pode ser perdido duas vezes por corretores diferentes).',
        'permissao' => $R['permissao_historico'], 'ausentes' => '—', 'atualizacao' => 'Rotina de 15 em 15 minutos (a perda acontece no cron).', 'disponibilidade' => 'disponivel'];
    $i['redistribuidos'] = ['nome' => 'Leads redistribuídos', 'categoria' => 'perdas',
        'descricao' => 'Das perdas por inatividade, quantas já foram entregues a outro corretor.', 'formula' => 'perdas com para_usuario_id preenchido.',
        'fonte' => 'lead_transferencias', 'data_base' => 'Data da perda.', 'filtros' => 'Como "Perdidos por inatividade".', 'deduplicacao' => 'Cada perda conta.',
        'permissao' => $R['permissao_historico'], 'ausentes' => '—', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['tempo_redistribuicao'] = ['nome' => 'Tempo até a redistribuição', 'categoria' => 'perdas',
        'descricao' => 'Quanto tempo o lead perdido esperou até ter novo corretor.', 'formula' => 'Mediana de (próxima atribuição − data da perda).',
        'fonte' => 'lead_transferencias + roleta_distribuicoes (origem recuperacao) / lead_atribuicoes', 'data_base' => 'Data da perda.', 'filtros' => 'Como "Perdidos por inatividade".',
        'deduplicacao' => 'Uma medida por perda.', 'permissao' => $R['permissao_historico'], 'ausentes' => 'Perdas ainda na fila ficam fora (contadas em "aguardando").',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['transferencias_motivo'] = ['nome' => 'Transferências por motivo', 'categoria' => 'perdas',
        'descricao' => 'Todas as trocas de responsável, separadas por motivo — não são todas perdas por negligência.',
        'formula' => 'COUNT(lead_transferencias) por motivo: administrativa, inatividade (redistribuição), mudança de equipe, roleta. Etapa 2 acrescenta: voluntária (corretor devolveu) e oferta ativa.',
        'fonte' => 'lead_transferencias', 'data_base' => 'Data da transferência.', 'filtros' => 'Empresa, período, estrutura anterior/nova, empreendimento.',
        'deduplicacao' => 'Cada transferência conta.', 'permissao' => $R['permissao_historico'], 'ausentes' => 'Hoje não existe motivo "voluntária" nem "oferta ativa" (oferta distribui leads sem dono e não gera transferência).',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['destino_redistribuidos'] = ['nome' => 'Destino das oportunidades redistribuídas', 'categoria' => 'perdas',
        'descricao' => 'Para qual estrutura foram os leads perdidos, e o que aconteceu com eles depois.', 'formula' => 'Agrupamento por para_* das perdas + etapa atual agregada.',
        'fonte' => 'lead_transferencias, leads', 'data_base' => 'Data da perda.', 'filtros' => 'Como "Perdidos por inatividade".', 'deduplicacao' => 'Cada perda conta.',
        'permissao' => $R['permissao_historico'] . ' A estrutura anterior vê o destino só como número agregado.', 'ausentes' => '—', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];

    // ============================================================ Plantões e roletas
    $i['roleta_distribuicoes'] = ['nome' => 'Distribuições pela roleta', 'categoria' => 'plantoes',
        'descricao' => 'Leads entregues por cada roleta, por corretor, dia e hora.', 'formula' => 'COUNT(roleta_distribuicoes) no período.', 'fonte' => 'roleta_distribuicoes',
        'data_base' => 'distribuido_em.', 'filtros' => 'Empresa, período, roleta, plantão (via roleta), corretor, empreendimento, origem.', 'deduplicacao' => 'Cada entrega conta.',
        'permissao' => $R['permissao_leads'] . ' (pelo lead) — gestor de roletas vê o total da roleta.', 'ausentes' => 'Entregas manuais fora da roleta estão em "Leads recebidos pelo corretor".',
        'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['espera_distribuicao'] = ['nome' => 'Tempo de espera na fila', 'categoria' => 'plantoes',
        'descricao' => 'Quanto tempo o lead esperou um corretor disponível.', 'formula' => 'Mediana de (resolvido_em − entrou_em) da fila; leads entregues direto contam 0.',
        'fonte' => 'roleta_fila, roleta_distribuicoes', 'data_base' => 'entrou_em.', 'filtros' => 'Empresa, período, roleta, empreendimento.', 'deduplicacao' => 'Uma espera por passagem pela fila.',
        'permissao' => 'Escopos acima de corretor.', 'ausentes' => 'A fila guarda a ÚLTIMA passagem do lead (o registro é reaproveitado): passagens anteriores do mesmo lead não ficam. Etapa 2: histórico da fila.',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['presenca_corretor'] = ['nome' => 'Tempo de presença na roleta', 'categoria' => 'plantoes',
        'descricao' => 'Quanto tempo cada corretor ficou disponível na roleta (presença real), diferente do horário de funcionamento do plantão.',
        'formula' => 'SUM(saiu_em − entrou_em) das sessões; sessão aberta usa a última verificação.', 'fonte' => 'roleta_sessoes, roleta_presencas', 'data_base' => 'entrou_em (dividida por dia).',
        'filtros' => 'Empresa, período, roleta, corretor.', 'deduplicacao' => 'Sessões sobrepostas do mesmo corretor não existem (chave única).', 'permissao' => 'Corretor: só a própria; gestor: a sua estrutura.',
        'ausentes' => '—', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['leads_fora_horario'] = ['nome' => 'Leads recebidos dentro/fora do horário', 'categoria' => 'plantoes',
        'descricao' => 'Se o lead chegou com a roleta aberta ou fechada.', 'formula' => 'Para cada lead, avaliar o expediente da roleta no momento da chegada.',
        'fonte' => 'leads, roleta_horarios, roleta_excecoes', 'data_base' => $periodoLead, 'filtros' => 'Empresa, período, roleta, empreendimento.', 'deduplicacao' => 'Lead conta uma vez.',
        'permissao' => 'Escopos acima de corretor.', 'ausentes' => 'O horário das roletas não tem versões: mudanças de horário reescrevem o passado. Etapa 2: guardar "roleta aberta?" no momento da chegada.',
        'atualizacao' => $realtime, 'disponibilidade' => 'etapa2'];

    // ============================================================ Marketing e investimento
    $i['leads_origem'] = ['nome' => 'Leads por origem de aquisição', 'categoria' => 'marketing',
        'descricao' => 'De onde veio o cliente (origem ORIGINAL). Canal de atendimento (ex.: WhatsApp depois) não muda a origem.',
        'formula' => 'COUNT(leads) por origem.', 'fonte' => 'leads.origem (hoje: Meta Ads, Manual, Importação, Indicação, Outro). Etapa 2: tabela de origens configuráveis por empresa + UTMs.',
        'data_base' => $periodoLead, 'filtros' => $R['filtros_lead'], 'deduplicacao' => 'Lead conta uma vez.', 'permissao' => $R['permissao_leads'],
        'ausentes' => 'Google Ads, Site, Landing page, Telefone etc. hoje caem em "Manual"/"Outro" — não há como separar retroativamente; ficam como "origem não detalhada".',
        'atualizacao' => $realtime, 'disponibilidade' => 'parcial'];
    $i['leads_campanha_anuncio'] = ['nome' => 'Resultados por campanha, conjunto e anúncio', 'categoria' => 'marketing',
        'descricao' => 'Leads, contato, visitas e contratos por campanha/conjunto/anúncio/criativo do Meta Ads, com a prévia do criativo.',
        'formula' => 'Indicadores de leads/visitas/contratos agrupados pelos ids de origem gravados no lead.', 'fonte' => 'leads.meta_* + meta_anuncios (migração 011); leads.campanha (texto) para outros casos.',
        'data_base' => $periodoLead . ' (coorte).', 'filtros' => $R['filtros_lead'] . ' Campanha, conjunto, anúncio.', 'deduplicacao' => 'Lead conta uma vez, no anúncio do formulário que enviou.',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'Leads do Meta anteriores à migração 011 só têm o nome da campanha. Criativo dinâmico: a prévia é uma versão, não necessariamente a vista pelo cliente.',
        'atualizacao' => $realtime . ' Criativos: sincronização a cada 15 min.', 'disponibilidade' => 'parcial'];
    $i['investimento'] = ['nome' => 'Investimento publicitário', 'categoria' => 'marketing',
        'descricao' => 'Quanto foi investido em mídia, por período, plataforma, campanha e empreendimento.', 'formula' => 'SUM(valor) dos lançamentos do período.',
        'fonte' => 'Etapa 2/6: tabela de investimentos (lançamento manual identificado, importação de planilha, sincronização autorizada Meta/Google).',
        'data_base' => 'Data do gasto.', 'filtros' => 'Empresa, período, plataforma, campanha, empreendimento.', 'deduplicacao' => 'Um lançamento por (fonte, campanha, dia); sincronização substitui o importado.',
        'permissao' => 'Só cargos com visão financeira (permissão nova "ver_investimento").', 'ausentes' => 'Hoje NÃO existe: mostrar "Investimento não disponível". Nunca estimar.',
        'atualizacao' => '—', 'disponibilidade' => 'indisponivel'];
    $i['custos_por_resultado'] = ['nome' => 'Custo por lead / visita / contrato', 'categoria' => 'marketing',
        'descricao' => 'CPL, custo por lead qualificado, por visita agendada, por visita realizada e por contrato.',
        'formula' => 'investimento do recorte ÷ resultado do mesmo recorte (mesmo período, mesma campanha/empreendimento).', 'fonte' => 'Investimento + indicadores do CRM.',
        'data_base' => 'Período do investimento.', 'filtros' => 'Os do investimento.', 'deduplicacao' => 'Resultados com leads únicos.', 'permissao' => 'Como "Investimento".',
        'ausentes' => 'Sem investimento confiável do recorte → "não disponível". Investimento parcial (só parte das campanhas) → indicador marcado como parcial.',
        'atualizacao' => '—', 'disponibilidade' => 'indisponivel'];
    $i['roi'] = ['nome' => 'Retorno (ROI)', 'categoria' => 'marketing',
        'descricao' => 'Só com receita e custos completos e metodologia explícita. VGV não é receita.', 'formula' => '(receita atribuída − investimento) ÷ investimento, com regra de atribuição declarada.',
        'fonte' => '—', 'data_base' => '—', 'filtros' => '—', 'deduplicacao' => '—', 'permissao' => 'Como "Investimento".',
        'ausentes' => 'Não há receita no CRM. Não é calculado.', 'atualizacao' => '—', 'disponibilidade' => 'indisponivel'];

    // ============================================================ WhatsApp e remarketing
    $i['wa_mensagens'] = ['nome' => 'Mensagens enviadas / entregues / lidas / falhas', 'categoria' => 'whatsapp',
        'descricao' => 'Volume de mensagens do WhatsApp oficial (não confundir com pessoas impactadas).', 'formula' => 'COUNT(mensagens de saída) por status; simuladas (modo de teste) à parte.',
        'fonte' => 'whatsapp_mensagens (status atualizado pelos webhooks da Meta)', 'data_base' => 'criado_em da mensagem.', 'filtros' => 'Empresa, período, jornada, campanha, template, automática/humana + filtros do lead.',
        'deduplicacao' => 'Mensagem conta uma vez (wamid único).', 'permissao' => $R['permissao_leads'] . ' (pelo lead da conversa).', 'ausentes' => '"Lida" depende do cliente permitir confirmação de leitura.',
        'atualizacao' => 'Webhook (segundos) + reprocessamento a cada 15 min.', 'disponibilidade' => 'disponivel'];
    $i['wa_impactados'] = ['nome' => 'Leads únicos impactados', 'categoria' => 'whatsapp',
        'descricao' => 'Pessoas que receberam pelo menos uma mensagem (automática ou de campanha).', 'formula' => 'COUNT(DISTINCT lead) com mensagem de saída enviada/entregue/lida.',
        'fonte' => 'whatsapp_mensagens + whatsapp_conversas', 'data_base' => 'Data da primeira mensagem do lead no período.', 'filtros' => 'Como "Mensagens".',
        'deduplicacao' => 'Lead conta UMA vez, não importa quantas mensagens.', 'permissao' => $R['permissao_leads'], 'ausentes' => 'Contatos sem lead vinculado contam em "contatos sem cadastro".',
        'atualizacao' => 'Webhook.', 'disponibilidade' => 'disponivel'];
    $i['wa_respostas'] = ['nome' => 'Clientes que responderam / taxa de resposta', 'categoria' => 'whatsapp',
        'descricao' => 'Pessoas que escreveram em até 7 dias depois de uma mensagem automática ou de campanha.', 'formula' => 'leads impactados com mensagem de entrada em até 7 dias ÷ leads impactados × 100.',
        'fonte' => 'whatsapp_mensagens', 'data_base' => 'Data da mensagem que gerou a resposta.', 'filtros' => 'Como "Mensagens".', 'deduplicacao' => 'Lead conta uma vez.',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'Resposta não é venda: resultados depois do remarketing são mostrados como influência, sem atribuição automática.',
        'atualizacao' => 'Webhook.', 'disponibilidade' => 'disponivel'];
    $i['wa_tempo_resposta_corretor'] = ['nome' => 'Tempo de resposta do corretor no WhatsApp', 'categoria' => 'whatsapp',
        'descricao' => 'Quanto tempo o corretor levou para responder o cliente pela Central de conversas.', 'formula' => 'Mediana de (primeira saída humana − mensagem de entrada que a antecede).',
        'fonte' => 'whatsapp_mensagens', 'data_base' => 'Data da mensagem do cliente.', 'filtros' => 'Empresa, período, corretor/estrutura.', 'deduplicacao' => 'Mensagens seguidas do cliente contam a partir da primeira.',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'Respostas por outro canal (telefone, WhatsApp pessoal) não são medidas.', 'atualizacao' => 'Webhook.', 'disponibilidade' => 'disponivel'];
    $i['remarketing_resultados'] = ['nome' => 'Remarketing: reativações e resultados depois', 'categoria' => 'whatsapp',
        'descricao' => 'Destinatários de campanhas, respostas, visitas e contratos DEPOIS da campanha (influência, não atribuição).',
        'formula' => 'Leads destinatários; dos que receberam, quantos responderam, agendaram visita e assinaram depois da 1ª mensagem da campanha.',
        'fonte' => 'whatsapp_campanhas, whatsapp_campanha_destinatarios, whatsapp_mensagens, agenda_compromissos, contratos', 'data_base' => 'Data do envio.',
        'filtros' => 'Empresa, período, campanha, template, empreendimento, estrutura.', 'deduplicacao' => 'Lead em várias campanhas conta uma vez no total e uma vez em cada campanha.',
        'permissao' => $R['permissao_leads'], 'ausentes' => 'Sem grupo de controle não há como provar causa: a tela diz "depois da campanha", nunca "por causa da campanha".',
        'atualizacao' => 'Webhook + rotina.', 'disponibilidade' => 'disponivel'];
    $i['wa_descadastros'] = ['nome' => 'Pedidos de descadastro', 'categoria' => 'whatsapp',
        'descricao' => 'Contatos que pediram para não receber mais mensagens.', 'formula' => 'COUNT(contatos com opt_out_em no período).', 'fonte' => 'whatsapp_contatos, whatsapp_consentimentos',
        'data_base' => 'opt_out_em.', 'filtros' => 'Empresa, período.', 'deduplicacao' => 'Contato conta uma vez.', 'permissao' => 'Escopos de empresa (o contato pode não ter lead).',
        'ausentes' => '—', 'atualizacao' => 'Webhook.', 'disponibilidade' => 'disponivel'];
    $i['wa_custo'] = ['nome' => 'Custo estimado das mensagens', 'categoria' => 'whatsapp',
        'descricao' => 'Estimativa pela tarifa cadastrada (a fatura real é da Meta).', 'formula' => 'SUM(custo_estimado) das mensagens cobradas.', 'fonte' => 'whatsapp_mensagens, whatsapp_tarifas',
        'data_base' => 'Data do envio.', 'filtros' => 'Como "Mensagens".', 'deduplicacao' => 'Mensagem conta uma vez.', 'permissao' => 'Como "Investimento".',
        'ausentes' => 'Sem tarifa cadastrada → "não disponível". Simuladas custam zero.', 'atualizacao' => 'Webhook.', 'disponibilidade' => 'parcial'];

    // ============================================================ Auditoria e operação
    $i['exportacoes'] = ['nome' => 'Exportações de relatórios', 'categoria' => 'auditoria',
        'descricao' => 'Quem exportou o quê, quando e com quais filtros.', 'formula' => 'Registros de auditoria relatorio.exportado (e lead.dados_titular_exportados).', 'fonte' => 'auditoria',
        'data_base' => 'criado_em.', 'filtros' => 'Empresa, período, usuário.', 'deduplicacao' => '—', 'permissao' => 'Só quem tem "ver auditoria".', 'ausentes' => '—', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    $i['qualidade_dados'] = ['nome' => 'Qualidade dos dados', 'categoria' => 'auditoria',
        'descricao' => 'Problemas que comprometem os relatórios (origem ausente, duplicados, datas incoerentes, visitas sem situação, falhas de integração).',
        'formula' => 'Verificações do painel de qualidade (ver ferramentas/auditoria_relatorios.php).', 'fonte' => 'Todas as tabelas acima.', 'data_base' => 'Posição atual.',
        'filtros' => 'Empresa.', 'deduplicacao' => '—', 'permissao' => 'Master da empresa / administradores.', 'ausentes' => '—', 'atualizacao' => $realtime, 'disponibilidade' => 'disponivel'];
    return $i;
}

/** Documento do dicionário (docs/RELATORIOS_DICIONARIO.md). */
function relatorio_dicionario_markdown(): string
{
    $cat = relatorio_categorias();
    $disp = relatorio_disponibilidades();
    $sel = ['disponivel' => '🟢', 'parcial' => '🟡', 'etapa2' => '🔵', 'indisponivel' => '⚪'];
    $md = "# Dicionário de Indicadores — Central de Relatórios\n\n"
        . "> Gerado automaticamente de `includes/relatorios/indicadores.php` (fonte única). Não edite à mão: rode `php -q ferramentas/gerar_dicionario.php`.\n\n"
        . "## Legenda de disponibilidade\n\n";
    foreach ($disp as $k => $v) {
        $md .= "- {$sel[$k]} **{$k}** — {$v}\n";
    }
    $md .= "\n## Regras gerais\n\n";
    foreach (relatorio_regras_gerais() as $k => $v) {
        $md .= "- **{$k}**: {$v}\n";
    }
    $ind = relatorio_indicadores();
    foreach ($cat as $ck => $cn) {
        $lista = array_filter($ind, fn ($i) => $i['categoria'] === $ck);
        if (!$lista) {
            continue;
        }
        $md .= "\n## {$cn}\n";
        foreach ($lista as $k => $i) {
            $md .= "\n### {$sel[$i['disponibilidade']]} {$i['nome']} (`{$k}`)\n\n{$i['descricao']}\n\n"
                . "| Campo | Definição |\n|---|---|\n";
            foreach (['formula' => 'Fórmula', 'fonte' => 'Fonte', 'data_base' => 'Data usada', 'filtros' => 'Filtros', 'deduplicacao' => 'Deduplicação',
                      'permissao' => 'Permissão', 'ausentes' => 'Dados ausentes / limitações', 'atualizacao' => 'Atualização', 'disponibilidade' => 'Disponibilidade'] as $c => $r) {
                $v = $c === 'disponibilidade' ? $disp[$i[$c]] : $i[$c];
                $md .= "| {$r} | " . str_replace(["\n", '|'], [' ', '\\|'], $v) . " |\n";
            }
        }
    }
    return $md;
}
