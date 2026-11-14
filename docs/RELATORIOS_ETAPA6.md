# Central de Relatórios — Etapa 6: inteligência e qualidade

## Implantação

Na pasta do sistema, depois do `git pull`:

```
mysql -u wemart86_Aurea -p wemart86_Aurea < sql/migration_016_relatorios_investimentos_modelos.sql
php -q ferramentas/medir_relatorios.php
```

A migração cria o investimento em anúncios, os modelos personalizados, a
permissão **"Lançar investimento em anúncios"** (Master da empresa) e dois
índices de desempenho. Sem ela, tudo funciona; os custos aparecem como
"não disponível" e só os modelos prontos existem (testado).

## Investimento em anúncios e custos (`pages/investimentos.php`)

- Lançamento manual (período, origem, empreendimento opcional, campanha,
  valor) ou **planilha CSV** (`data_inicio;data_fim;origem;empreendimento;campanha;valor;descricao`,
  datas dd/mm/aaaa, nomes como no sistema). Planilha é **tudo ou nada**: com
  uma linha errada, nada entra e o erro aponta a linha.
- **Rateio por dia**: R$ 3.000 de 01 a 30/06 = R$ 100/dia; um relatório de 01 a
  10/06 considera R$ 1.000.
- Custos (em **Origens e UTMs**, na **Visão executiva** e nas exportações):
  custo por lead, por contato efetivo, por visita e por contrato **validado**,
  sempre com o investimento e os resultados das **mesmas origens**.
- Regras de confiabilidade — nada estimado:
  - sem lançamento no recorte → "não disponível" (não zero);
  - só para quem vê a empresa toda e sem filtro de estrutura, corretor,
    roleta, plantão ou campanha (o investimento não é lançado nesses níveis);
  - com filtro de empreendimento, só lançamentos daquele empreendimento;
  - **ROI não é calculado**: o sistema não tem receita (VGV não é receita).
- Integração automática com Meta/Google Ads para o investimento: **não
  implementada** (depende de autorização das contas); o lançamento manual ou
  por planilha cobre o uso até lá.

## Modelos de relatório (`pages/relatorio_modelos.php`)

Prontos: **Executivo Mensal, Prestação de Contas, Solicitação de Verba,
Empreendimento, Produtividade, Superintendência, Marketing, Perdas**.
Remarketing continua no relatório de automação (Fase 7), que já é completo.
Cada modelo gera **um PDF** (capa + seções numeradas + notas e definições
reunidas) ou **um Excel** (Capa, uma aba por seção, Notas), com o período e os
filtros escolhidos na hora, a permissão de quem gera (seção sem acesso é
omitida com aviso) e registro na auditoria. **Novo modelo**: o usuário escolhe
as seções, o período padrão e o empreendimento, e salva para si.

## Painel de Qualidade dos Dados (`pages/qualidade_dados.php`)

Para quem vê a auditoria ou administra usuários: críticos, pontos de atenção e
verificações em ordem, por área (leads, atribuição, atendimento, funil,
contratos e visitas, plantões, marketing — inclusive "leads de Meta/Google
sem investimento lançado" —, WhatsApp, histórico estruturado), com o link de
onde corrigir. Só contagens. É a mesma verificação de
`ferramentas/auditoria_relatorios.php`.

## Volume e agregações diárias — decisão

Medido com uma empresa sintética de **120 mil leads em 1 ano** (137 mil
entregas, 240 mil mudanças de etapa, 24 mil visitas), visão da empresa toda:

| Relatório | Tempo |
|---|---|
| Visão executiva (com comparação) | ~2,9–3,4 s |
| Funil em detalhe | ~2,8 s |
| Gestão por corretor | ~1,3 s |
| Origens | ~0,9 s |
| Visitas, perdas, séries, plantões | < 0,5 s |
| Memória máxima | ~55 MB |

Ajustes feitos para chegar aqui: funil agregado no banco e lido aos poucos
(memória caiu de 236 MB para 55 MB), leitura ordenada da 1ª entrega e dois
índices novos. **Não foram criadas tabelas de agregação diária**: seriam uma
segunda fonte de cálculo (risco de divergir da tela) sem necessidade medida.
Critério para criá-las: se `ferramentas/medir_relatorios.php` no servidor
passar de ~8 s em alguma tela. O teste de volume é reproduzível:
`php -q tests/relatorios_volume.php 120000` (empresa de teste apagada no fim).

## Testes

`php -q tests/relatorios_etapa6_test.php` — 59 verificações (rateio, regras de
custo, planilha tudo-ou-nada, modelos prontos e personalizados, permissões,
painel). 12 mutações detectadas. O teste encontrou um erro real que foi
corrigido: com filtro de empreendimento, o custo dividia o investimento de
uma origem pelos leads de todas as origens.
