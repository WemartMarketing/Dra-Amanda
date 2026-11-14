# Central de Relatórios — Etapa 4: relatórios especializados

Sem migração nova: usa as tabelas das migrações 013 e 014. Sem a 013 as telas
abrem normalmente, com os números que dependem do histórico vazios e o aviso
"Histórico estruturado não instalado" (testado).

Todos em `pages/relatorio.php?r=...`, para quem tem **Visualizar indicadores**,
com os mesmos filtros, chips, limpar e favoritos da Etapa 3. Cálculos em
`includes/relatorios/especializados.php`; telas em
`includes/relatorios/telas_especializadas.php`.

| r= | O quê | Regras |
|---|---|---|
| `funil` | Onde estão hoje os leads do período; **até onde chegaram** (fase mais adiantada alcançada — quem voltou de etapa continua contando onde chegou); transições de → para separando pessoas, jornadas e sistema; tempo mediano em cada etapa | Permissão: leads visíveis hoje. "Sem interesse"/"Perdido" contam só na entrada |
| `plantoes` | Por roleta: entregas e redistribuições, chegadas e chegadas fora do horário, passagens pela fila, espera mediana, quem ainda aguarda, horas de presença; presença por corretor | Entregas pela estrutura na data; presença só de pessoas da estrutura de quem vê, recortada ao período (sessão que passa da meia-noite do último dia conta só até 23:59:59) |
| `origens` | Leads do período por origem, grupo, utm_source/medium/campaign, e o que já aconteceu com eles: chegaram a corretor, contato efetivo, visita realizada, **contrato validado** | Custo por lead/venda e ROI: **"—"** (não há investimento registrado; nunca estimado). Prévia do criativo: "Leads por anúncio" |
| `visitas` | Por corretor (quem conduziu) ou empreendimento: agendadas, confirmadas, realizadas, cliente ausente, canceladas, reagendadas, comparecimento, **visita → contrato** | Cada situação pela própria data; escopo de agenda (o mesmo da tela de agenda) |
| `perdas` | Pela estrutura de quem perdeu (na data): perdidos por inatividade, redistribuídos, tempo até redistribuir, contato efetivo do novo corretor, contrato depois, transferidos; para onde foram | Lead excluído (LGPD) não entra. "Transferências (detalhe)" continua na página antiga |

WhatsApp e remarketing: o relatório da Fase 7 (`pages/relatorio_automacao.php`)
já cobre mensagens × pessoas, respostas e resultados depois da campanha; a
central aponta para ele.

## Testes

`php -q tests/relatorios_etapa4_test.php` — 47 verificações com datas fixas e
contas feitas à mão (alcance 4→3→2→2→2→1; espera mediana 20 min; presença
4 h + 2 h; visita → contrato; redistribuição em 2 h com contato depois),
permissões por gerente, filtros inválidos, exclusão LGPD. 12 mutações detectadas.
