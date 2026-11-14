# Central de Relatórios — Etapa 2: histórico estruturado e motor

Etapa 2 concluída. Nenhuma tela de relatório nova ainda (isso é a Etapa 3):
esta etapa garante que os números **possam** ser calculados com integridade
histórica, e entrega o motor que as telas e exportações vão usar.

## 1. Como implantar no servidor

No Terminal do cPanel, **na pasta do sistema** (`/home1/wemart86/myonespot.online`):

```
mysql -u wemart86_Aurea -p wemart86_Aurea < sql/migration_013_relatorios_historico.sql
php -q ferramentas/retroalimentar_historico.php --simular
php -q ferramentas/retroalimentar_historico.php
php -q ferramentas/auditoria_relatorios.php
```

- A migração é **não destrutiva** e pode ser rodada mais de uma vez.
- O código funciona **antes** da migração (testado): só não grava o histórico novo.
- `--simular` mostra o que seria reconstruído sem gravar nada.
- A retroalimentação pode ser rodada de novo: não duplica.

## 2. O que passou a ser gravado (no momento em que acontece)

| Tabela | O quê | Por que importa |
|---|---|---|
| `lead_atribuicoes` | cada entrega de lead a um corretor (cadastro, roleta, transferência, oferta ativa, redistribuição, mudança de equipe), com **equipe/gerente/superintendência da data**, 1º atendimento e 1º contato efetivo **daquela** atribuição | "Leads recebidos" e "taxa de atendimento" do corretor não dependem mais da carteira atual; o atendimento de quem perdeu o lead não se apaga |
| `lead_etapa_historico` | toda mudança de etapa, com origem (**usuário**, **jornada** ou **sistema**) | movimentação do funil, tempo por etapa; jornadas não contam como contato |
| `lead_contratos` | registro formal: data, VGV (opcional), unidade, **validação** e **distrato** | venda validada ≠ mover no Kanban |
| `roleta_fila_historico` | todas as passagens pela fila | espera para distribuição |
| `lead_origens` + `leads.utm_*` | origens configuráveis por empresa + UTMs | Google Ads, site, telefone… deixam de cair em "Manual/Outro" |
| `leads.chegada_roleta_aberta` | se a roleta estava aberta na **chegada** do lead | "leads fora do horário" sem depender do horário atual |
| `agenda_compromissos.confirmada_em` | cliente confirmou a visita | visitas confirmadas |

**Contato efetivo** (definição do dicionário) é gravado quando: o cliente escreve
no WhatsApp; o corretor confirma "Sim, o cliente respondeu"; o próprio usuário
move o lead para "Primeiro contato" ou adiante; a visita é realizada. Mudança
feita por jornada, mensagem automática e "Não respondeu" **não** contam.

## 3. Telas novas (mínimas)

- **Ficha do lead → card "Contrato"**: registrar (data, VGV, unidade), corrigir
  enquanto aguarda, **validar** (permissão nova `validar_contratos`: Master e
  Gerente Geral) e **distrato**. Ao registrar, o lead pode ir para "Contrato
  assinado". O card aparece para leads em visita realizada/contrato assinado,
  para quem já tem contrato e para quem pode validar.
- **Agenda → "Cliente confirmou"** nas visitas agendadas.
- **Etapas do funil → "Origens dos leads"**: criar/editar/desativar origens.
  As de sistema (Meta Ads, Indicação, Importação, Cadastro manual não
  detalhado, Outros) não mudam.
- **Cadastro/edição do lead**: origem detalhada e UTMs (recolhidas, opcionais).

## 4. Retroalimentação — o que é e o que não é reconstruído

Só **fontes registradas**, marcadas `reconstruido = 1`:

| Reconstruído | Fonte |
|---|---|
| Atribuições | `roleta_distribuicoes`, `lead_transferencias`, auditoria `lead.criado` com responsável; dono atual sem outra fonte → forma "não identificada" |
| Estrutura na data | a da transferência; senão o "de" da transferência seguinte; senão (só a atribuição em aberto) a equipe atual do lead |
| 1º atendimento | eventos do próprio corretor (contato, etapa, visita), pendências resolvidas por ele, visitas concluídas, WhatsApp real enviado por ele |
| Contato efetivo | WhatsApp recebido, pendência "respondeu", auditoria de etapa pelo usuário, visita realizada |
| Etapas | auditoria `lead.etapa_alterada` |
| Fila | `roleta_fila` (só a última passagem) |

**Não** reconstruído (fica "indisponível antes da implantação"): mudanças de
etapa feitas por jornadas antes da 013, visitas confirmadas, chegada fora do
horário, contratos formais (os antigos aparecem só como sinal da etapa).

## 5. Motor (`includes/relatorios/motor.php`)

- `relatorio_filtros($usuario, $entrada)`: período (hoje, ontem, 7d, 30d, mês,
  mês anterior, trimestre, ano, personalizado — com período anterior de mesma
  duração) e dimensões (empreendimento, plantão, roleta, superintendência,
  gerente, equipe, corretor, origem, grupo, etapa, campanha/conjunto/anúncio do
  Meta, forma de recebimento). **Id que não é da empresa → resultado vazio.**
- Permissão sempre no SQL:
  - indicadores de **leads** (coorte pela entrada): o que o usuário enxerga hoje;
  - indicadores de **corretor/atribuição** e **contratos**: a estrutura gravada
    **na data** (o gestor continua vendo o histórico da equipe dele). Só agregados.
- Funções: `relatorio_leads`, `relatorio_leads_por_origem`, `relatorio_corretores`,
  `relatorio_perdas`, `relatorio_visitas`, `relatorio_contratos`, `relatorio_funil`,
  `relatorio_executivo` (com comparação só quando a base anterior existe),
  `relatorio_cobertura_historico` (quanto do período foi reconstruído).
- Sem base → `null` ("—"), nunca 0% inventado. VGV só de contratos validados
  com valor, com a nota "X de Y com valor".

## 6. Testes

`php -q tests/relatorios_etapa2_test.php` — 124 verificações: períodos,
valores, origens, atribuições com transferência, mudança de equipe, perda e
redistribuição, permissões por escopo (corretor, gerente, superintendente,
master), filtros inválidos, isolamento entre empresas, contratos
(registrar/validar/distrato), visitas, funil, retroalimentação (idempotente).
16 mutações no código foram todas detectadas pelos testes.
