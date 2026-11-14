# Central de Relatórios — Etapa 1: auditoria de dados e plano

> Etapa 1 concluída: banco mapeado, fontes documentadas, dicionário de
> indicadores criado (`docs/RELATORIOS_DICIONARIO.md`, gerado de
> `includes/relatorios/indicadores.php`) e ferramenta de auditoria do banco
> real (`ferramentas/auditoria_relatorios.php`). Nenhuma tela ou cálculo de
> produção foi alterado nesta etapa.

## 1. Como auditar o banco de produção

No Terminal do cPanel, na pasta do sistema:

```
php -q ferramentas/auditoria_relatorios.php
```

Só leitura e só contagens (sem nomes, telefones ou e-mails). Mostra, por empresa,
quanto histórico existe e onde há buracos. O resultado define quais indicadores
poderão ser mostrados com histórico e a partir de quando.

## 2. Fontes de dados existentes (o que já dá para usar)

| Domínio | Tabela(s) | O que guarda | Confiabilidade histórica |
|---|---|---|---|
| Cadastro do lead | `leads` | entrada (`criado_em`), origem, campanha (texto), empreendimento, dono atual, etapa atual, 1º atendimento, contrato | Entrada e origem: boa. Dono/etapa: **só o estado atual** |
| Linha do tempo | `lead_eventos` | eventos em TEXTO (atribuição, contato, ligação, WhatsApp, visita, transferência, recuperação, jornada…) | Boa para "o que aconteceu", ruim para cálculo (sem origem/destino estruturados) |
| Mudanças de etapa | `auditoria` (`lead.etapa_alterada`) | de/para (id e categoria), usuário, data | Boa desde que a auditoria de etapas existe; **não cobre mudanças feitas por jornadas** |
| Entregas da roleta | `roleta_distribuicoes` | lead, corretor, roleta, origem, data | Boa |
| Transferências e perdas | `lead_transferencias` | estrutura anterior e nova completas, motivo, data | Boa (motivos: administrativa, inatividade, mudança de equipe, roleta) |
| Fila da roleta | `roleta_fila` | entrada e saída da fila | Guarda **só a última passagem** de cada lead |
| Presença | `roleta_sessoes`, `roleta_presencas` | entrada/saída, GPS, motivo de saída | Boa |
| Horário dos plantões | `roleta_horarios`, `roleta_excecoes` | horário vigente e feriados | **Sem versões**: mudar o horário muda o passado |
| Visitas e retornos | `agenda_compromissos` | criação, data, status (agendado, realizado, ausente, reagendado, cancelado), conclusão, cadeia de reagendamento | Boa; **não existe "confirmada"** |
| Contratos | `leads.contrato_assinado_em` | data em que entrou na etapa | **Apagada se o lead sair da etapa**; sem valor (VGV) nem registro formal |
| Meta Ads | `meta_eventos`, `meta_anuncios`, `leads.meta_*` | avisos, duplicados, campanha/conjunto/anúncio/criativo | Boa a partir da migração 011; antes, só o nome da campanha |
| WhatsApp | `whatsapp_mensagens`, `whatsapp_conversas`, `whatsapp_contatos`, `whatsapp_consentimentos` | mensagens com status (webhook), respostas, opt-out, custo estimado | Boa (simuladas separadas) |
| Remarketing | `whatsapp_campanhas`, `whatsapp_campanha_destinatarios` | destinatários, envio, resultados | Boa |
| Jornadas | `jornada_execucoes`, `jornada_logs` | quem entrou, passos, motivos | Boa |
| Pendências | `corretor_pendencias` | "o cliente respondeu?" com a resposta do corretor | Boa |
| Auditoria geral | `auditoria` | ações administrativas, exportações, logins | Boa |
| Investimento publicitário | — | — | **Não existe** |
| UTMs / origens detalhadas | — | origem é fixa (Meta, Manual, Importação, Indicação, Outro) | **Não existe** |

## 3. Problemas encontrados (impactam a precisão)

1. **"Desempenho por corretor" do Dashboard atual usa a carteira de HOJE**
   (`leads.responsavel_id`). Se o corretor recebeu 100 leads e perdeu 20, o
   Dashboard mostra 80. A Central usará o histórico de atribuições.
2. **O 1º atendimento é zerado quando o lead troca de corretor**
   (`lead_marcar_atribuicao`). O tempo e a taxa de 1º atendimento do corretor
   anterior se perdem.
3. **A data do contrato some se o lead sair da etapa "Contrato assinado"**
   (o Dashboard deixa de contar a venda). Mover no Kanban também não é uma
   validação formal de venda.
4. **Mudanças de etapa feitas por jornadas** ficam só em texto (sem
   origem/destino estruturados).
5. **Primeiro atendimento ≠ contato efetivo**: hoje `primeiro_contato_em`
   marca a primeira *atuação* (inclusive "Não respondeu"). O dicionário separa
   **atuação**, **tentativa** e **contato efetivo**.
6. **Origem**: Google Ads, site, landing page, telefone e WhatsApp caem em
   "Manual"/"Outro"; não há UTMs. Não dá para separar o passado.
7. **Fila e horário dos plantões sem histórico**: só a última passagem pela
   fila; horário sem versões ("lead chegou fora do horário?" precisa ser
   gravado na chegada).
8. **Não existem**: investimento, VGV, contrato formal, visita confirmada,
   transferência "voluntária" e "oferta ativa" como motivos.

> Regra adotada: **nada é reconstruído por suposição**. O que tiver fonte
> registrada (roleta, transferências, auditoria) é aproveitado e marcado como
> "reconstruído"; o que não tiver fica como "histórico indisponível antes de
> DD/MM/AAAA".

## 4. Plano de implementação (após aprovação de cada etapa)

### Etapa 2 — Motor de relatórios e histórico estruturado ✅ concluída (ver `docs/RELATORIOS_ETAPA2.md`)
Migração 013 (não destrutiva):
- `lead_atribuicoes`: cada vez que um lead chega a um corretor (roleta, cadastro,
  transferência, oferta, redistribuição), com estrutura na data, forma de
  recebimento, 1º atendimento, 1º contato efetivo e saída **daquela atribuição**.
  Retroalimentada só com fontes registradas (roleta, transferências, auditoria
  de criação), marcando `reconstruido = 1`.
- `lead_etapa_historico`: toda transição (usuário, jornada ou sistema), com
  de/para e duração; retroalimentada da auditoria de etapas.
- `lead_contratos`: registro formal (data, valor/VGV opcional, unidade,
  validado por, status ativo/distratado). Kanban continua como sinal, marcado
  como "pela etapa".
- Agenda: `confirmada_em`; transferências: motivos `voluntaria` e `oferta_ativa`.
- Origens configuráveis por empresa (mantendo as atuais) + UTMs e canal de
  primeiro contato no lead; "lead chegou com a roleta aberta?" gravado na
  chegada; histórico de passagens pela fila.
- Motor `includes/relatorios/motor.php`: filtros (empresa, períodos rápidos,
  personalizado, trimestre, ano, empreendimento, plantão, roleta, estrutura,
  corretor, campanha/conjunto/anúncio, origem, etapa, status de atendimento,
  visita, venda, remarketing), permissão sempre aplicada no SQL, cada
  indicador implementado conforme o dicionário, com testes por consulta
  independente.

### Etapa 3 — Central de Relatórios (dashboards)
Menu **Relatórios** com as 10 categorias; fluxo *escolher relatório → filtros
→ resultado → exportar*; Visão Executiva (com comparação de períodos só com
base válida), Corretor (individual com evolução diária/semanal/mensal),
Gerentes/Superintendências (comparativos e rankings por critério explícito),
Empreendimentos (comparação de 2+). Filtros aplicados sempre visíveis, botão
limpar, filtros favoritos. Gráficos em SVG (sem bibliotecas externas), clique
para detalhar quando autorizado.

### Etapa 4 — Relatórios especializados
Marketing (campanha/anúncio com prévia do criativo), Plantões e Roletas
(presença real × horário do plantão, espera, fora do horário), Funil (posição ×
movimentação, coortes, tempo por etapa, avanços/retrocessos), Visitas e Vendas,
WhatsApp e Remarketing (mensagens × pessoas, respostas, depois da campanha),
Perdas e Redistribuições (por motivo, estrutura anterior, destino, tempo).

### Etapa 5 — Exportações
- **Excel (.xlsx real)**: o gerador nativo do sistema (ZipArchive, já usado)
  ampliado para várias abas (Resumo, Detalhamento, Notas), cabeçalho com
  empresa, período, filtros, data/hora e fuso; datas e números tipados;
  proteção contra fórmulas.
- **CSV** (UTF-8 com BOM, `;`), com a mesma proteção.
- **PDF**: relatório executivo com a identidade WeMart Sales. Opção avaliada
  para HostGator sem Composer: biblioteca PHP pura de arquivo único (FPDF/tFPDF,
  sem dependências) com gráficos desenhados no próprio PDF. Decisão final e
  teste de compatibilidade no início da etapa.
- Relatório Resumido / Completo / Exportação de Dados; permissões idênticas às
  da tela; auditoria de quem exportou e com quais filtros; arquivos grandes
  gerados em tarefa controlada (fila do cron) e baixados depois, com link
  temporário protegido.

### Etapa 6 — Inteligência e qualidade
Modelos prontos (Executivo Mensal, Prestação de Contas, Solicitação de Verba,
Empreendimento, Produtividade, Superintendência, Marketing, Remarketing, Perdas)
e modelos personalizados salvos; Painel de Qualidade dos Dados (a auditoria
desta etapa, com alertas); investimento publicitário (lançamento manual
identificado e importação de planilha; integração Meta/Google quando
autorizada) e indicadores financeiros **só com dados confiáveis**; agregações
diárias para alto volume, conferidas contra os eventos originais.

## 5. Decisões de arquitetura
- PHP + MySQL/MariaDB, sem Node.js nem processos permanentes; cálculos no
  banco com `GROUP BY` e índices; paginação; tarefas pesadas pela fila do cron
  existente (`automacao_fila`).
- Uma única fonte de cálculo por indicador: tela, gráfico, Excel, CSV e PDF saem
  da mesma função (testes conferem a consistência).
- Fuso America/Sao_Paulo em gravação e exibição (já padronizado em `db.php`).
- Dados em tempo real × sincronizações periódicas sinalizados em cada indicador
  (campo "Atualização" do dicionário).
