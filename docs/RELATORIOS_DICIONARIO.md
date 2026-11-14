# Dicionário de Indicadores — Central de Relatórios

> Gerado automaticamente de `includes/relatorios/indicadores.php` (fonte única). Não edite à mão: rode `php -q ferramentas/gerar_dicionario.php`.

## Legenda de disponibilidade

- 🟢 **disponivel** — Disponível com histórico confiável
- 🟡 **parcial** — Disponível com limitação (ver tratamento de ausentes)
- 🔵 **etapa2** — Depende do histórico estruturado da Etapa 2 (vale a partir da implantação)
- ⚪ **indisponivel** — Sem dados no sistema — não é calculado

## Regras gerais

- **permissao_leads**: Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam.
- **permissao_historico**: Indicadores históricos de perdas/transferências usam a estrutura ANTERIOR do lead (rbac_transferencias_scope): o gestor anterior vê só números agregados, nunca o cadastro do lead transferido.
- **filtros_lead**: Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa.
- **excluidos**: Leads excluídos (excluido_em preenchido) não entram em nenhum indicador; anonimizados (LGPD) entram só nas contagens.
- **contato_valido**: CONTATO EFETIVO = evidência de que o cliente foi alcançado por uma pessoa: (a) o cliente escreveu no WhatsApp oficial; (b) o corretor confirmou "Sim, o cliente respondeu" na central de pendências; (c) o corretor registrou atendimento com texto e moveu o lead para "Primeiro contato" ou etapa posterior; (d) visita realizada. NÃO são contato efetivo: mensagem automática, clique em Ligar/WhatsApp, "Não respondeu", observação, mudança de etapa feita por jornada automática.
- **feedback_valido**: ATUAÇÃO (feedback válido, regra da recuperação) = ação do corretor que comprova trabalho no lead: registrar atendimento, mudar etapa, resolver visita/retorno, responder pendência (inclusive "Não respondeu"), mensagem REAL pelo WhatsApp. Atuação ≠ contato efetivo: tentativa sem resposta é atuação, mas não é contato.
- **fuso**: Datas gravadas e exibidas no fuso America/Sao_Paulo (UTC−03:00). Período = [início 00:00, fim 23:59:59].

## Visão Executiva

### 🟢 Comparação com o período anterior (`comparacao_periodo`)

Variação de cada indicador em relação ao período imediatamente anterior de mesma duração (ex.: "leads +15%", "taxa de 1º atendimento de 78% para 65%").

| Campo | Definição |
|---|---|
| Fórmula | Contagens: (atual − anterior) ÷ anterior × 100. Taxas: diferença em pontos percentuais (p.p.), mostrando os dois valores. Mês atual compara com os mesmos dias do mês anterior. |
| Fonte | O mesmo cálculo do indicador, aplicado aos dois períodos com os mesmos filtros e o mesmo recorte de permissão. |
| Data usada | A do indicador comparado. |
| Filtros | Os do relatório (iguais nos dois períodos). |
| Deduplicação | A do indicador. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Só é exibida com base válida: período anterior com pelo menos 5 registros (taxas: 5 na base) e totalmente coberto pelos dados do CRM (sem lacuna de histórico). Senão: "sem base de comparação". |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟡 Resumo executivo (`resumo_executivo`)

Painel da diretoria: recebidos, únicos, distribuídos, aguardando, com atendimento, sem atendimento, em atendimento, visitas (agendadas, realizadas, canceladas, ausentes), contratos, conversão, tempo até o 1º atendimento, recuperados, perdidos por inatividade e leads que receberam remarketing.

| Campo | Definição |
|---|---|
| Fórmula | Composição dos indicadores do dicionário, cada um com a sua definição e a sua marca de disponibilidade. |
| Fonte | Ver cada indicador. |
| Data usada | A de cada indicador (indicada no próprio card). |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | A de cada indicador. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Indicador parcial ou indisponível aparece marcado, nunca misturado com resultado confirmado. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

## Leads e Atendimentos

### 🟢 Leads recebidos (`leads_recebidos`)

Cadastros de leads que entraram no CRM no período, por qualquer origem.

| Campo | Definição |
|---|---|
| Fórmula | COUNT(leads) com criado_em no período. |
| Fonte | leads |
| Data usada | Data de entrada do lead (leads.criado_em). |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Nenhuma: cada cadastro conta. O Meta Ads não cria cadastro novo para telefone já existente (conta em "Reentradas"). |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Leads excluídos (excluido_em preenchido) não entram em nenhum indicador; anonimizados (LGPD) entram só nas contagens. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Leads únicos (`leads_unicos`)

Pessoas distintas entre os leads recebidos no período.

| Campo | Definição |
|---|---|
| Fórmula | COUNT(DISTINCT chave), chave = telefone normalizado (só dígitos, com DDI 55); sem telefone válido, e-mail em minúsculas; sem os dois, o próprio cadastro. |
| Fonte | leads |
| Data usada | Data de entrada do lead (leads.criado_em). |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Pelo telefone normalizado dentro da mesma empresa. Nunca entre empresas. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Telefones digitados de formas diferentes são normalizados; números inválidos caem para e-mail/cadastro. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟡 Reentradas (cadastros repetidos) (`reentradas`)

Cadastros que chegaram de novo para um cliente que já existia na empresa (não viraram lead novo).

| Campo | Definição |
|---|---|
| Fórmula | Avisos do Meta com status "duplicado" + duplicados das importações de oferta ativa, no período. |
| Fonte | meta_eventos (status=duplicado, recebido_em); oferta_listas (duplicados, criado_em) |
| Data usada | Data de recebimento do aviso / da importação. |
| Filtros | Empresa, período (empreendimento só para importações). |
| Deduplicação | Cada aviso conta uma vez (leadgen_id único). |
| Permissão | Só escopos de empresa inteira (toda_empresa/todos_produtos): o aviso não tem corretor. |
| Dados ausentes / limitações | Cadastros manuais repetidos são bloqueados na tela e não deixam registro. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟡 Leads distribuídos (`leads_distribuidos`)

Leads recebidos no período que foram entregues a algum corretor (em qualquer momento).

| Campo | Definição |
|---|---|
| Fórmula | Leads recebidos com pelo menos uma atribuição registrada (roleta, cadastro com responsável, transferência, oferta ativa). |
| Fonte | Hoje: leads.responsavel_id (dono atual) + roleta_distribuicoes. Etapa 2: lead_atribuicoes. |
| Data usada | Data de entrada do lead (leads.criado_em). |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Lead conta uma vez, mesmo com várias atribuições. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Hoje um lead recuperado que ainda está na fila aparece como "não distribuído" embora já tenha tido dono. Corrigido com lead_atribuicoes (Etapa 2). |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟢 Aguardando distribuição (`aguardando_distribuicao`)

Leads sem corretor neste momento, esperando na fila da roleta.

| Campo | Definição |
|---|---|
| Fórmula | COUNT(roleta_fila sem resolvido_em) com lead ativo e sem responsável. |
| Fonte | roleta_fila, leads |
| Data usada | Posição atual (agora) — não depende do período. |
| Filtros | Empresa, empreendimento, roleta. |
| Deduplicação | Um registro de fila por lead (chave única). |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. Leads sem dono só aparecem para escopos acima de corretor. |
| Dados ausentes / limitações | Lead sem empreendimento e sem roleta também é contado (motivo "nenhuma roleta atende"). |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟡 Leads trabalhados (com atuação) (`leads_com_atuacao`)

Leads em que o corretor registrou pelo menos uma atuação válida.

| Campo | Definição |
|---|---|
| Fórmula | Leads com primeiro feedback válido registrado. Definição de atuação: ATUAÇÃO (feedback válido, regra da recuperação) = ação do corretor que comprova trabalho no lead: registrar atendimento, mudar etapa, resolver visita/retorno, responder pendência (inclusive "Não respondeu"), mensagem REAL pelo WhatsApp. Atuação ≠ contato efetivo: tentativa sem resposta é atuação, mas não é contato. |
| Fonte | Hoje: leads.primeiro_contato_em (do dono ATUAL). Etapa 2: por atribuição em lead_atribuicoes. |
| Data usada | Data de entrada do lead (leads.criado_em). (coorte). |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Lead conta uma vez. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | LIMITAÇÃO ATUAL: primeiro_contato_em é zerado quando o lead muda de corretor — a atuação do corretor anterior se perde. A partir da Etapa 2 fica gravada por atribuição. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟡 Leads com contato efetivo (`contatos_efetivos`)

Leads em que houve evidência de que o cliente foi alcançado por uma pessoa.

| Campo | Definição |
|---|---|
| Fórmula | Leads com pelo menos um evento de contato efetivo. CONTATO EFETIVO = evidência de que o cliente foi alcançado por uma pessoa: (a) o cliente escreveu no WhatsApp oficial; (b) o corretor confirmou "Sim, o cliente respondeu" na central de pendências; (c) o corretor registrou atendimento com texto e moveu o lead para "Primeiro contato" ou etapa posterior; (d) visita realizada. NÃO são contato efetivo: mensagem automática, clique em Ligar/WhatsApp, "Não respondeu", observação, mudança de etapa feita por jornada automática. |
| Fonte | whatsapp_mensagens (entrada), corretor_pendencias (resposta=respondeu), auditoria lead.etapa_alterada (para categoria ≥ primeiro_contato, por usuário), agenda_compromissos (visita realizada). |
| Data usada | Data de entrada do lead (leads.criado_em). (coorte) ou data do evento (visão de movimentação). |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Lead conta uma vez; vários contatos não somam. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Mudanças de etapa anteriores à auditoria estruturada (Etapa 3 do projeto) não têm usuário/categoria: esses leads ficam como "sem evidência", nunca como contatados por suposição. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟢 Tentativas de contato (`tentativas_contato`)

Ações do corretor para alcançar o cliente, com ou sem sucesso.

| Campo | Definição |
|---|---|
| Fórmula | Eventos: clique em Ligar/WhatsApp, atendimento registrado, "Não respondeu"/"Registrar tentativa" nas pendências, mensagem humana real no WhatsApp. |
| Fonte | lead_eventos (ligacao, whatsapp, contato), corretor_pendencias (nao_respondeu, tentativa), whatsapp_mensagens (saída humana não simulada). |
| Data usada | Data do evento. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. Usuário que fez a ação. |
| Deduplicação | Conta eventos (não leads). Também há a versão "leads com tentativa" (lead uma vez). |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Clique em Ligar indica intenção, não ligação completada (o CRM não acessa a operadora). |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Leads sem atendimento (`sem_atendimento`)

Leads com corretor há mais de N horas (padrão 2) e nenhuma atuação válida.

| Campo | Definição |
|---|---|
| Fórmula | responsavel_id preenchido AND primeiro_contato_em vazio AND atribuido_em < agora − N horas. |
| Fonte | leads |
| Data usada | Posição atual, para os leads recebidos no período. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Lead conta uma vez. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Leads sem atribuido_em (cadastros antigos) não entram: aparecem no painel de qualidade. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Leads em atendimento (`em_atendimento`)

Leads hoje em etapas abertas de trabalho (categorias Primeiro contato e Em atendimento).

| Campo | Definição |
|---|---|
| Fórmula | COUNT(leads) por categoria da etapa atual. |
| Fonte | leads.etapa (categoria) / leads.etapa_id |
| Data usada | Posição atual. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Lead conta uma vez. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Etapas personalizadas são agrupadas pela categoria escolhida pela empresa no cadastro da etapa. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟡 Tempo até o primeiro atendimento (`tempo_primeiro_contato`)

Tempo entre o lead chegar ao corretor e a primeira atuação válida dele.

| Campo | Definição |
|---|---|
| Fórmula | Média e MEDIANA de (primeiro_contato_em − atribuido_em), só leads com as duas datas. A mediana é o número principal (resistente a casos extremos). |
| Fonte | Hoje: leads (dono atual). Etapa 2: lead_atribuicoes (cada corretor que recebeu). |
| Data usada | Data de entrada do lead (leads.criado_em). |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Um tempo por lead (por atribuição na Etapa 2). |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Leads sem atuação ficam FORA da média (aparecem em "sem atendimento"). Mesmo limite de primeiro_contato_em ser zerado na troca de corretor. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟡 Taxa de primeiro atendimento (`taxa_primeiro_contato`)

Percentual dos leads distribuídos que tiveram atuação válida.

| Campo | Definição |
|---|---|
| Fórmula | leads únicos com primeiro feedback válido ÷ leads distribuídos elegíveis no período × 100. Elegível = distribuído há mais de N horas. |
| Fonte | leads (Etapa 2: lead_atribuicoes) |
| Data usada | Data de entrada do lead (leads.criado_em). |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Leads únicos. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Sem leads elegíveis → "—" (não 0%). |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟡 Taxa de contato efetivo (`taxa_contato_efetivo`)

Percentual dos leads distribuídos em que o cliente foi efetivamente alcançado.

| Campo | Definição |
|---|---|
| Fórmula | leads com contato efetivo ÷ leads distribuídos elegíveis × 100. |
| Fonte | Ver "Leads com contato efetivo". |
| Data usada | Data de entrada do lead (leads.criado_em). |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Leads únicos. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Sem base → "—". |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟡 Funil — posição atual (`funil_posicao`)

Quantos leads estão em cada etapa AGORA (ou numa data de corte).

| Campo | Definição |
|---|---|
| Fórmula | COUNT(leads) por etapa atual. Data de corte: reconstruída pelas transições registradas. |
| Fonte | leads.etapa_id; data de corte: lead_etapa_historico (Etapa 2). |
| Data usada | Agora / data de corte. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Lead em uma etapa só. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Data de corte no passado só a partir do histórico estruturado (antes dele: "não reconstruível"). |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟡 Funil — movimentações no período (`funil_movimentacao`)

Entradas, saídas, avanços e retrocessos entre etapas durante o período (não confundir com a posição atual).

| Campo | Definição |
|---|---|
| Fórmula | COUNT(transições) por etapa de origem/destino; avanço = destino com ordem maior; retrocesso = menor. |
| Fonte | auditoria lead.etapa_alterada (de_etapa_id, para_etapa_id); Etapa 2: lead_etapa_historico (inclui mudanças feitas por jornadas, que hoje só ficam em texto). |
| Data usada | Data da transição. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Cada transição conta; "leads que passaram pela etapa" conta o lead uma vez. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Transições feitas por jornada automática não estão na auditoria (ficam em lead_eventos tipo jornada) — corrigido na Etapa 2. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🔵 Tempo médio em cada etapa (`tempo_etapa`)

Quanto tempo os leads ficaram em cada etapa antes de sair.

| Campo | Definição |
|---|---|
| Fórmula | Mediana de (saída − entrada) por etapa, só para permanências completas no período. |
| Fonte | lead_etapa_historico (Etapa 2). |
| Data usada | Data de saída da etapa. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Cada permanência conta. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | A entrada na primeira etapa é a criação do lead. Permanências iniciadas antes do histórico estruturado não são calculadas. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Depende do histórico estruturado da Etapa 2 (vale a partir da implantação) |

### 🟢 Leads parados (`leads_parados`)

Leads em etapas abertas sem nenhuma atuação há mais de X dias (padrão: o prazo da recuperação).

| Campo | Definição |
|---|---|
| Fórmula | Etapa aberta AND COALESCE(ultimo_feedback_em, criado_em) < agora − X dias. |
| Fonte | leads, recuperacao_regras |
| Data usada | Posição atual. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Lead conta uma vez. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | — |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

## Corretores e Equipes

### 🔵 Leads recebidos pelo corretor (histórico) (`recebidos_corretor`)

Quantos leads o corretor RECEBEU no período, mesmo que depois tenham saído da carteira dele.

| Campo | Definição |
|---|---|
| Fórmula | COUNT(atribuições ao corretor no período): roleta, cadastro com responsável, transferência recebida, oferta ativa, redistribuição. |
| Fonte | Hoje: roleta_distribuicoes + lead_transferencias (para_usuario_id) + auditoria lead.criado (responsável). Etapa 2: lead_atribuicoes (tabela única, com retroalimentação dessas fontes). |
| Data usada | Data da atribuição. |
| Filtros | Empresa, período, corretor, equipe/gerente/superintendência NA DATA da atribuição, empreendimento, origem, forma de recebimento. |
| Deduplicação | Lead recebido duas vezes pelo mesmo corretor no período conta 1 em "leads únicos recebidos" e 2 em "recebimentos". |
| Permissão | Corretor: só os próprios números. Gestor: corretores da sua estrutura NA DATA da atribuição (números agregados; o cadastro só se o lead ainda estiver no seu recorte). |
| Dados ausentes / limitações | NÃO usar a carteira atual (o Dashboard atual faz isso e será corrigido). Leads distribuídos pela oferta ativa antes da Etapa 2 só têm evento em texto: entram via lead_eventos tipo atribuição, marcados como "reconstruído". |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Depende do histórico estruturado da Etapa 2 (vale a partir da implantação) |

### 🟢 Carteira atual do corretor (`carteira_atual`)

Leads que estão com o corretor agora.

| Campo | Definição |
|---|---|
| Fórmula | COUNT(leads) com responsavel_id = corretor, não excluídos, por categoria de etapa. |
| Fonte | leads |
| Data usada | Posição atual. |
| Filtros | Empresa, corretor, empreendimento, etapa. |
| Deduplicação | Lead conta uma vez. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Não é histórico: use "Leads recebidos pelo corretor" para produtividade. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Leads perdidos pelo corretor (`perdidos_corretor`)

Leads que saíram da carteira do corretor no período, separados por motivo.

| Campo | Definição |
|---|---|
| Fórmula | COUNT(lead_transferencias) com de_usuario_id = corretor, por motivo (inatividade, administrativa, mudança de equipe, roleta). |
| Fonte | lead_transferencias |
| Data usada | Data da transferência. |
| Filtros | Empresa, período, corretor/estrutura ANTERIOR, empreendimento, motivo. |
| Deduplicação | Cada transferência conta. |
| Permissão | Indicadores históricos de perdas/transferências usam a estrutura ANTERIOR do lead (rbac_transferencias_scope): o gestor anterior vê só números agregados, nunca o cadastro do lead transferido. |
| Dados ausentes / limitações | Só "inatividade" é perda por falta de atuação. Transferência administrativa NÃO é contada como negligência. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Leads recebidos por redistribuição (`recebidos_redistribuicao`)

Leads que chegaram ao corretor depois de terem sido perdidos por outro.

| Campo | Definição |
|---|---|
| Fórmula | COUNT(roleta_distribuicoes origem=recuperacao para o corretor) + transferências recebidas de leads que já tiveram dono. |
| Fonte | roleta_distribuicoes, lead_transferencias |
| Data usada | Data da distribuição. |
| Filtros | Empresa, período, corretor/estrutura, empreendimento. |
| Deduplicação | Cada recebimento conta. |
| Permissão | Como "Leads recebidos pelo corretor". |
| Dados ausentes / limitações | — |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟡 Tempo médio entre atualizações (`tempo_atualizacao`)

Intervalo médio entre duas atuações válidas consecutivas do corretor nos leads da carteira.

| Campo | Definição |
|---|---|
| Fórmula | Média dos intervalos entre eventos de atuação do mesmo lead, feitos pelo corretor responsável na época. |
| Fonte | lead_eventos (contato, mudanca_etapa, visita, agenda) com usuario_id; auditoria lead.etapa_alterada. |
| Data usada | Data do evento. |
| Filtros | Empresa, período, corretor/estrutura. |
| Deduplicação | Eventos no mesmo minuto contam como um. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Lead com um único evento não gera intervalo. Pendências e mensagens reais entram a partir da Etapa 2 (evento unificado). |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟡 Ranking de corretores (`ranking_corretores`)

Comparação entre corretores por critério explícito escolhido pelo usuário (nunca um "score" opaco).

| Campo | Definição |
|---|---|
| Fórmula | Ordenação por um indicador do dicionário (ex.: taxa de contato efetivo, visitas realizadas, contratos), com a base (leads recebidos) sempre visível. |
| Fonte | Indicadores de corretor acima. |
| Data usada | A do indicador escolhido. |
| Filtros | Os do indicador + mínimo de leads para entrar no ranking (padrão 5). |
| Deduplicação | A do indicador. |
| Permissão | Gestor vê só a sua estrutura; corretor vê só a própria posição (sem nomes dos colegas). |
| Dados ausentes / limitações | Corretor abaixo do mínimo aparece como "base insuficiente". |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

## Empreendimentos

### 🟢 Comparativo entre empreendimentos (`comparativo_empreendimentos`)

Dois ou mais empreendimentos lado a lado: geração de leads, aproveitamento (atendimento/contato), comparecimento em visitas e conversão.

| Campo | Definição |
|---|---|
| Fórmula | Os mesmos indicadores do dicionário calculados por empreendimento, no mesmo período e filtros; destaque do maior valor por linha só quando as bases são comparáveis. |
| Fonte | leads.empreendimento_id + fontes de cada indicador. |
| Data usada | A de cada indicador. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. Lista de empreendimentos. |
| Deduplicação | Lead pertence ao empreendimento do cadastro (não muda com transferência). |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. GP de Produto compara só os seus empreendimentos. |
| Dados ausentes / limitações | Empreendimento com base menor que o mínimo (5 leads) mostra "base insuficiente" em vez de taxa. Leads sem empreendimento aparecem numa coluna própria. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟡 Leads por plantão (`leads_por_plantao`)

Leads do empreendimento distribuídos por cada plantão (via roleta que entregou).

| Campo | Definição |
|---|---|
| Fórmula | COUNT(roleta_distribuicoes) por plantão da roleta, para leads do empreendimento. |
| Fonte | roleta_distribuicoes, roletas.plantao_id |
| Data usada | Data da distribuição. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. Plantão. |
| Deduplicação | Lead conta uma vez por plantão. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Leads entregues fora da roleta ficam em "sem plantão". O vínculo roleta→plantão é o atual (sem versões). |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

## Plantões e Roletas

### 🟢 Distribuições pela roleta (`roleta_distribuicoes`)

Leads entregues por cada roleta, por corretor, dia e hora.

| Campo | Definição |
|---|---|
| Fórmula | COUNT(roleta_distribuicoes) no período. |
| Fonte | roleta_distribuicoes |
| Data usada | distribuido_em. |
| Filtros | Empresa, período, roleta, plantão (via roleta), corretor, empreendimento, origem. |
| Deduplicação | Cada entrega conta. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. (pelo lead) — gestor de roletas vê o total da roleta. |
| Dados ausentes / limitações | Entregas manuais fora da roleta estão em "Leads recebidos pelo corretor". |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟡 Tempo de espera na fila (`espera_distribuicao`)

Quanto tempo o lead esperou um corretor disponível.

| Campo | Definição |
|---|---|
| Fórmula | Mediana de (resolvido_em − entrou_em) da fila; leads entregues direto contam 0. |
| Fonte | roleta_fila, roleta_distribuicoes |
| Data usada | entrou_em. |
| Filtros | Empresa, período, roleta, empreendimento. |
| Deduplicação | Uma espera por passagem pela fila. |
| Permissão | Escopos acima de corretor. |
| Dados ausentes / limitações | A fila guarda a ÚLTIMA passagem do lead (o registro é reaproveitado): passagens anteriores do mesmo lead não ficam. Etapa 2: histórico da fila. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟢 Tempo de presença na roleta (`presenca_corretor`)

Quanto tempo cada corretor ficou disponível na roleta (presença real), diferente do horário de funcionamento do plantão.

| Campo | Definição |
|---|---|
| Fórmula | SUM(saiu_em − entrou_em) das sessões; sessão aberta usa a última verificação. |
| Fonte | roleta_sessoes, roleta_presencas |
| Data usada | entrou_em (dividida por dia). |
| Filtros | Empresa, período, roleta, corretor. |
| Deduplicação | Sessões sobrepostas do mesmo corretor não existem (chave única). |
| Permissão | Corretor: só a própria; gestor: a sua estrutura. |
| Dados ausentes / limitações | — |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🔵 Leads recebidos dentro/fora do horário (`leads_fora_horario`)

Se o lead chegou com a roleta aberta ou fechada.

| Campo | Definição |
|---|---|
| Fórmula | Para cada lead, avaliar o expediente da roleta no momento da chegada. |
| Fonte | leads, roleta_horarios, roleta_excecoes |
| Data usada | Data de entrada do lead (leads.criado_em). |
| Filtros | Empresa, período, roleta, empreendimento. |
| Deduplicação | Lead conta uma vez. |
| Permissão | Escopos acima de corretor. |
| Dados ausentes / limitações | O horário das roletas não tem versões: mudanças de horário reescrevem o passado. Etapa 2: guardar "roleta aberta?" no momento da chegada. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Depende do histórico estruturado da Etapa 2 (vale a partir da implantação) |

## Marketing e Campanhas

### 🟡 Leads por origem de aquisição (`leads_origem`)

De onde veio o cliente (origem ORIGINAL). Canal de atendimento (ex.: WhatsApp depois) não muda a origem.

| Campo | Definição |
|---|---|
| Fórmula | COUNT(leads) por origem. |
| Fonte | leads.origem (hoje: Meta Ads, Manual, Importação, Indicação, Outro). Etapa 2: tabela de origens configuráveis por empresa + UTMs. |
| Data usada | Data de entrada do lead (leads.criado_em). |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Lead conta uma vez. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Google Ads, Site, Landing page, Telefone etc. hoje caem em "Manual"/"Outro" — não há como separar retroativamente; ficam como "origem não detalhada". |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟡 Resultados por campanha, conjunto e anúncio (`leads_campanha_anuncio`)

Leads, contato, visitas e contratos por campanha/conjunto/anúncio/criativo do Meta Ads, com a prévia do criativo.

| Campo | Definição |
|---|---|
| Fórmula | Indicadores de leads/visitas/contratos agrupados pelos ids de origem gravados no lead. |
| Fonte | leads.meta_* + meta_anuncios (migração 011); leads.campanha (texto) para outros casos. |
| Data usada | Data de entrada do lead (leads.criado_em). (coorte). |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. Campanha, conjunto, anúncio. |
| Deduplicação | Lead conta uma vez, no anúncio do formulário que enviou. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Leads do Meta anteriores à migração 011 só têm o nome da campanha. Criativo dinâmico: a prévia é uma versão, não necessariamente a vista pelo cliente. |
| Atualização | Tempo real (consulta direta ao banco). Criativos: sincronização a cada 15 min. |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### ⚪ Investimento publicitário (`investimento`)

Quanto foi investido em mídia, por período, plataforma, campanha e empreendimento.

| Campo | Definição |
|---|---|
| Fórmula | SUM(valor) dos lançamentos do período. |
| Fonte | Etapa 2/6: tabela de investimentos (lançamento manual identificado, importação de planilha, sincronização autorizada Meta/Google). |
| Data usada | Data do gasto. |
| Filtros | Empresa, período, plataforma, campanha, empreendimento. |
| Deduplicação | Um lançamento por (fonte, campanha, dia); sincronização substitui o importado. |
| Permissão | Só cargos com visão financeira (permissão nova "ver_investimento"). |
| Dados ausentes / limitações | Hoje NÃO existe: mostrar "Investimento não disponível". Nunca estimar. |
| Atualização | — |
| Disponibilidade | Sem dados no sistema — não é calculado |

### ⚪ Custo por lead / visita / contrato (`custos_por_resultado`)

CPL, custo por lead qualificado, por visita agendada, por visita realizada e por contrato.

| Campo | Definição |
|---|---|
| Fórmula | investimento do recorte ÷ resultado do mesmo recorte (mesmo período, mesma campanha/empreendimento). |
| Fonte | Investimento + indicadores do CRM. |
| Data usada | Período do investimento. |
| Filtros | Os do investimento. |
| Deduplicação | Resultados com leads únicos. |
| Permissão | Como "Investimento". |
| Dados ausentes / limitações | Sem investimento confiável do recorte → "não disponível". Investimento parcial (só parte das campanhas) → indicador marcado como parcial. |
| Atualização | — |
| Disponibilidade | Sem dados no sistema — não é calculado |

### ⚪ Retorno (ROI) (`roi`)

Só com receita e custos completos e metodologia explícita. VGV não é receita.

| Campo | Definição |
|---|---|
| Fórmula | (receita atribuída − investimento) ÷ investimento, com regra de atribuição declarada. |
| Fonte | — |
| Data usada | — |
| Filtros | — |
| Deduplicação | — |
| Permissão | Como "Investimento". |
| Dados ausentes / limitações | Não há receita no CRM. Não é calculado. |
| Atualização | — |
| Disponibilidade | Sem dados no sistema — não é calculado |

## WhatsApp e Remarketing

### 🟢 Mensagens enviadas / entregues / lidas / falhas (`wa_mensagens`)

Volume de mensagens do WhatsApp oficial (não confundir com pessoas impactadas).

| Campo | Definição |
|---|---|
| Fórmula | COUNT(mensagens de saída) por status; simuladas (modo de teste) à parte. |
| Fonte | whatsapp_mensagens (status atualizado pelos webhooks da Meta) |
| Data usada | criado_em da mensagem. |
| Filtros | Empresa, período, jornada, campanha, template, automática/humana + filtros do lead. |
| Deduplicação | Mensagem conta uma vez (wamid único). |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. (pelo lead da conversa). |
| Dados ausentes / limitações | "Lida" depende do cliente permitir confirmação de leitura. |
| Atualização | Webhook (segundos) + reprocessamento a cada 15 min. |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Leads únicos impactados (`wa_impactados`)

Pessoas que receberam pelo menos uma mensagem (automática ou de campanha).

| Campo | Definição |
|---|---|
| Fórmula | COUNT(DISTINCT lead) com mensagem de saída enviada/entregue/lida. |
| Fonte | whatsapp_mensagens + whatsapp_conversas |
| Data usada | Data da primeira mensagem do lead no período. |
| Filtros | Como "Mensagens". |
| Deduplicação | Lead conta UMA vez, não importa quantas mensagens. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Contatos sem lead vinculado contam em "contatos sem cadastro". |
| Atualização | Webhook. |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Clientes que responderam / taxa de resposta (`wa_respostas`)

Pessoas que escreveram em até 7 dias depois de uma mensagem automática ou de campanha.

| Campo | Definição |
|---|---|
| Fórmula | leads impactados com mensagem de entrada em até 7 dias ÷ leads impactados × 100. |
| Fonte | whatsapp_mensagens |
| Data usada | Data da mensagem que gerou a resposta. |
| Filtros | Como "Mensagens". |
| Deduplicação | Lead conta uma vez. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Resposta não é venda: resultados depois do remarketing são mostrados como influência, sem atribuição automática. |
| Atualização | Webhook. |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Tempo de resposta do corretor no WhatsApp (`wa_tempo_resposta_corretor`)

Quanto tempo o corretor levou para responder o cliente pela Central de conversas.

| Campo | Definição |
|---|---|
| Fórmula | Mediana de (primeira saída humana − mensagem de entrada que a antecede). |
| Fonte | whatsapp_mensagens |
| Data usada | Data da mensagem do cliente. |
| Filtros | Empresa, período, corretor/estrutura. |
| Deduplicação | Mensagens seguidas do cliente contam a partir da primeira. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Respostas por outro canal (telefone, WhatsApp pessoal) não são medidas. |
| Atualização | Webhook. |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Remarketing: reativações e resultados depois (`remarketing_resultados`)

Destinatários de campanhas, respostas, visitas e contratos DEPOIS da campanha (influência, não atribuição).

| Campo | Definição |
|---|---|
| Fórmula | Leads destinatários; dos que receberam, quantos responderam, agendaram visita e assinaram depois da 1ª mensagem da campanha. |
| Fonte | whatsapp_campanhas, whatsapp_campanha_destinatarios, whatsapp_mensagens, agenda_compromissos, contratos |
| Data usada | Data do envio. |
| Filtros | Empresa, período, campanha, template, empreendimento, estrutura. |
| Deduplicação | Lead em várias campanhas conta uma vez no total e uma vez em cada campanha. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Sem grupo de controle não há como provar causa: a tela diz "depois da campanha", nunca "por causa da campanha". |
| Atualização | Webhook + rotina. |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Pedidos de descadastro (`wa_descadastros`)

Contatos que pediram para não receber mais mensagens.

| Campo | Definição |
|---|---|
| Fórmula | COUNT(contatos com opt_out_em no período). |
| Fonte | whatsapp_contatos, whatsapp_consentimentos |
| Data usada | opt_out_em. |
| Filtros | Empresa, período. |
| Deduplicação | Contato conta uma vez. |
| Permissão | Escopos de empresa (o contato pode não ter lead). |
| Dados ausentes / limitações | — |
| Atualização | Webhook. |
| Disponibilidade | Disponível com histórico confiável |

### 🟡 Custo estimado das mensagens (`wa_custo`)

Estimativa pela tarifa cadastrada (a fatura real é da Meta).

| Campo | Definição |
|---|---|
| Fórmula | SUM(custo_estimado) das mensagens cobradas. |
| Fonte | whatsapp_mensagens, whatsapp_tarifas |
| Data usada | Data do envio. |
| Filtros | Como "Mensagens". |
| Deduplicação | Mensagem conta uma vez. |
| Permissão | Como "Investimento". |
| Dados ausentes / limitações | Sem tarifa cadastrada → "não disponível". Simuladas custam zero. |
| Atualização | Webhook. |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

## Visitas e Conversões

### 🟢 Visitas agendadas (`visitas_agendadas`)

Visitas criadas na agenda no período.

| Campo | Definição |
|---|---|
| Fórmula | COUNT(agenda_compromissos tipo=visita, criado_em no período, reagendado_de vazio). |
| Fonte | agenda_compromissos |
| Data usada | Data em que a visita foi marcada (criado_em), não a data da visita. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. Corretor que marcou. |
| Deduplicação | Reagendamento não conta como nova visita agendada. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | — |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Visitas realizadas (`visitas_realizadas`)

Visitas marcadas como realizadas no período.

| Campo | Definição |
|---|---|
| Fórmula | COUNT(visitas status=realizado, concluido_em no período). |
| Fonte | agenda_compromissos |
| Data usada | Data em que a visita foi concluída (concluido_em). |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Cada visita conta. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Visita no passado ainda "agendada" fica em "Visitas sem situação" (qualidade), não em realizadas. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Visitas canceladas / reagendadas / cliente ausente (`visitas_canceladas`)

Desfechos das visitas no período.

| Campo | Definição |
|---|---|
| Fórmula | COUNT por status (cancelado, reagendado, cliente_ausente) com concluido_em no período. |
| Fonte | agenda_compromissos |
| Data usada | concluido_em. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Cada visita conta. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | — |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### ⚪ Visitas confirmadas (`visitas_confirmadas`)

Visitas confirmadas com o cliente antes da data.

| Campo | Definição |
|---|---|
| Fórmula | Requer o status "confirmada" na agenda. |
| Fonte | — (não existe hoje). |
| Data usada | — |
| Filtros | — |
| Deduplicação | — |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | A agenda não tem confirmação. Proposta da Etapa 2: status/campo "confirmada_em". Até lá: "não disponível". |
| Atualização | — |
| Disponibilidade | Sem dados no sistema — não é calculado |

### 🟢 Taxa de comparecimento (`taxa_comparecimento`)

Das visitas com desfecho no período, quantas o cliente compareceu.

| Campo | Definição |
|---|---|
| Fórmula | realizadas ÷ (realizadas + cliente ausente) × 100. |
| Fonte | agenda_compromissos |
| Data usada | concluido_em. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Cada visita conta. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Canceladas e reagendadas ficam fora da base (não houve encontro marcado naquele dia). Sem base → "—". |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟡 Contratos assinados (`contratos_assinados`)

Vendas concluídas no período.

| Campo | Definição |
|---|---|
| Fórmula | Hoje: leads que entraram na etapa de categoria "contrato assinado" no período (pela auditoria de etapas). Etapa 2: registros formais em lead_contratos (fonte oficial quando existir). |
| Fonte | auditoria lead.etapa_alterada (para=contrato_assinado) e leads.contrato_assinado_em; Etapa 2: lead_contratos. |
| Data usada | Data da assinatura (data da mudança de etapa até existir o registro formal). |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. Corretor responsável na data da assinatura. |
| Deduplicação | Um contrato por lead por entrada na etapa; idas e voltas no Kanban não duplicam. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | LIMITAÇÃO ATUAL: contrato_assinado_em é apagado se o lead sair da etapa. Mover no Kanban não é validação formal: a tela indica "contrato pela etapa do Kanban" até a empresa usar o registro de contrato. |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟡 Taxa de conversão em contrato (`taxa_conversao`)

Duas leituras, sempre identificadas: (1) COORTE — dos leads recebidos no período, quantos já viraram contrato (até hoje); (2) PERÍODO — contratos do período ÷ leads recebidos do período.

| Campo | Definição |
|---|---|
| Fórmula | (1) leads da coorte com contrato ÷ leads da coorte × 100; (2) contratos com data no período ÷ leads recebidos no período × 100. |
| Fonte | leads, contratos (ver acima) |
| Data usada | (1) criado_em do lead; (2) data do contrato e criado_em. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Leads únicos. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Coortes recentes ainda estão "maturando" (a tela mostra a idade da coorte). |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟡 Conversão de visita em contrato (`taxa_visita_contrato`)

Dos leads com visita realizada, quantos assinaram contrato.

| Campo | Definição |
|---|---|
| Fórmula | leads com visita realizada e contrato ÷ leads com visita realizada × 100 (coorte pela data da visita). |
| Fonte | agenda_compromissos, contratos |
| Data usada | concluido_em da visita. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Leads únicos. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Sem base → "—". |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### ⚪ VGV dos contratos (`vgv`)

Valor Geral de Vendas dos contratos assinados (não é receita nem lucro).

| Campo | Definição |
|---|---|
| Fórmula | SUM(valor do contrato) dos contratos com data no período. |
| Fonte | Etapa 2: lead_contratos.valor_vgv. |
| Data usada | Data da assinatura. |
| Filtros | Empresa, período, empreendimento, superintendência, gerente, equipe, corretor, origem, campanha/anúncio, etapa. |
| Deduplicação | Um valor por contrato. |
| Permissão | Sempre dentro do recorte de leads do usuário (rbac_leads_scope): empresa ativa; GP de Produto só os empreendimentos vinculados; superintendente a sua superintendência; gerente a sua equipe; corretor a própria carteira. Superadmin não vê dados de leads. Filtros só restringem, nunca ampliam. |
| Dados ausentes / limitações | Hoje não existe valor de contrato no CRM: "VGV não disponível". |
| Atualização | — |
| Disponibilidade | Sem dados no sistema — não é calculado |

## Perdas e Redistribuições

### 🟢 Perdidos por inatividade (`perdidos_inatividade`)

Leads retirados do corretor pela recuperação automática (sem feedback no prazo, ou corretor desativado).

| Campo | Definição |
|---|---|
| Fórmula | COUNT(lead_transferencias motivo=inatividade) no período. |
| Fonte | lead_transferencias |
| Data usada | Data da perda. |
| Filtros | Empresa, período, corretor/equipe/gerente/superintendência ANTERIORES, empreendimento. |
| Deduplicação | Cada perda conta (o mesmo lead pode ser perdido duas vezes por corretores diferentes). |
| Permissão | Indicadores históricos de perdas/transferências usam a estrutura ANTERIOR do lead (rbac_transferencias_scope): o gestor anterior vê só números agregados, nunca o cadastro do lead transferido. |
| Dados ausentes / limitações | — |
| Atualização | Rotina de 15 em 15 minutos (a perda acontece no cron). |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Leads redistribuídos (`redistribuidos`)

Das perdas por inatividade, quantas já foram entregues a outro corretor.

| Campo | Definição |
|---|---|
| Fórmula | perdas com para_usuario_id preenchido. |
| Fonte | lead_transferencias |
| Data usada | Data da perda. |
| Filtros | Como "Perdidos por inatividade". |
| Deduplicação | Cada perda conta. |
| Permissão | Indicadores históricos de perdas/transferências usam a estrutura ANTERIOR do lead (rbac_transferencias_scope): o gestor anterior vê só números agregados, nunca o cadastro do lead transferido. |
| Dados ausentes / limitações | — |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟡 Tempo até a redistribuição (`tempo_redistribuicao`)

Quanto tempo o lead perdido esperou até ter novo corretor.

| Campo | Definição |
|---|---|
| Fórmula | Mediana de (próxima atribuição − data da perda). |
| Fonte | lead_transferencias + roleta_distribuicoes (origem recuperacao) / lead_atribuicoes |
| Data usada | Data da perda. |
| Filtros | Como "Perdidos por inatividade". |
| Deduplicação | Uma medida por perda. |
| Permissão | Indicadores históricos de perdas/transferências usam a estrutura ANTERIOR do lead (rbac_transferencias_scope): o gestor anterior vê só números agregados, nunca o cadastro do lead transferido. |
| Dados ausentes / limitações | Perdas ainda na fila ficam fora (contadas em "aguardando"). |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟡 Transferências por motivo (`transferencias_motivo`)

Todas as trocas de responsável, separadas por motivo — não são todas perdas por negligência.

| Campo | Definição |
|---|---|
| Fórmula | COUNT(lead_transferencias) por motivo: administrativa, inatividade (redistribuição), mudança de equipe, roleta. Etapa 2 acrescenta: voluntária (corretor devolveu) e oferta ativa. |
| Fonte | lead_transferencias |
| Data usada | Data da transferência. |
| Filtros | Empresa, período, estrutura anterior/nova, empreendimento. |
| Deduplicação | Cada transferência conta. |
| Permissão | Indicadores históricos de perdas/transferências usam a estrutura ANTERIOR do lead (rbac_transferencias_scope): o gestor anterior vê só números agregados, nunca o cadastro do lead transferido. |
| Dados ausentes / limitações | Hoje não existe motivo "voluntária" nem "oferta ativa" (oferta distribui leads sem dono e não gera transferência). |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com limitação (ver tratamento de ausentes) |

### 🟢 Destino das oportunidades redistribuídas (`destino_redistribuidos`)

Para qual estrutura foram os leads perdidos, e o que aconteceu com eles depois.

| Campo | Definição |
|---|---|
| Fórmula | Agrupamento por para_* das perdas + etapa atual agregada. |
| Fonte | lead_transferencias, leads |
| Data usada | Data da perda. |
| Filtros | Como "Perdidos por inatividade". |
| Deduplicação | Cada perda conta. |
| Permissão | Indicadores históricos de perdas/transferências usam a estrutura ANTERIOR do lead (rbac_transferencias_scope): o gestor anterior vê só números agregados, nunca o cadastro do lead transferido. A estrutura anterior vê o destino só como número agregado. |
| Dados ausentes / limitações | — |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

## Auditoria e Operação

### 🟢 Exportações de relatórios (`exportacoes`)

Quem exportou o quê, quando e com quais filtros.

| Campo | Definição |
|---|---|
| Fórmula | Registros de auditoria relatorio.exportado (e lead.dados_titular_exportados). |
| Fonte | auditoria |
| Data usada | criado_em. |
| Filtros | Empresa, período, usuário. |
| Deduplicação | — |
| Permissão | Só quem tem "ver auditoria". |
| Dados ausentes / limitações | — |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |

### 🟢 Qualidade dos dados (`qualidade_dados`)

Problemas que comprometem os relatórios (origem ausente, duplicados, datas incoerentes, visitas sem situação, falhas de integração).

| Campo | Definição |
|---|---|
| Fórmula | Verificações do painel de qualidade (ver ferramentas/auditoria_relatorios.php). |
| Fonte | Todas as tabelas acima. |
| Data usada | Posição atual. |
| Filtros | Empresa. |
| Deduplicação | — |
| Permissão | Master da empresa / administradores. |
| Dados ausentes / limitações | — |
| Atualização | Tempo real (consulta direta ao banco). |
| Disponibilidade | Disponível com histórico confiável |
