# Central de Relatórios — Etapa 3: telas

## Implantação

Na pasta do sistema (`/home1/wemart86/myonespot.online`), depois do `git pull`:

```
mysql -u wemart86_Aurea -p wemart86_Aurea < sql/migration_014_relatorios_favoritos.sql
```

Só cria a tabela dos filtros favoritos. Sem ela, tudo funciona e o botão
"Salvar filtros" simplesmente não aparece. Sem a 013, os relatórios por
corretor/estrutura mostram o aviso "Histórico estruturado não instalado".

## Menu

- Gestores (permissão **Visualizar indicadores**): **Relatórios** →
  `pages/relatorios_central.php`, com as 10 categorias. O que é da Etapa 4
  aparece como **em construção**, sem números.
- Corretor: **Meu desempenho** → só o próprio relatório (o filtro de corretor
  é forçado no servidor; trocar o id na URL não muda nada).
- "Perdas e transferências" continua existindo, dentro da categoria Perdas.

## Relatórios (`pages/relatorio.php?r=...`)

| r= | O quê |
|---|---|
| `executiva` | 10 indicadores com variação contra o período anterior de mesma duração ("sem base de comparação" quando o anterior é zero), evolução (leads, entregas, contatos efetivos, visitas, contratos; por dia/semana/mês), origem, contratos (com o sinal do Kanban à parte), movimentação do funil, perdas |
| `corretor` | recebidos (por forma), taxa de atendimento, contato efetivo, tempo mediano, visitas que ele conduziu, contratos e VGV validados, perdas, evolução, comparação com o período anterior |
| `gestao` | tabela por superintendência / gerente / equipe / corretor / empreendimento, **pela estrutura na data de cada entrega**; ranking por um critério escolhido; taxas e tempos só entram no ranking com ≥ 5 leads; clique no nome detalha o nível de baixo |
| `empreendimentos` | 2 a 6 empreendimentos lado a lado, melhor valor destacado (taxas só com base ≥ 5) |

Filtros sempre visíveis, com os aplicados em "chips" removíveis, **Limpar** e
**Favoritos**. Gráficos em SVG próprio (sem bibliotecas), com dica ao passar o
mouse/tocar/usar as setas e "Ver como tabela". Id de filtro inválido ou fora do
alcance → resultado vazio com aviso.

Exportação (Excel/CSV/PDF) dos relatórios novos é a **Etapa 5**.

## Testes

`php -q tests/relatorios_etapa3_test.php` — 58 verificações: intervalos
(dia/semana a partir de segunda/mês), série × total do mesmo período,
consistência corretor = equipe = superintendência, ranking com base mínima e
empates, visitas do corretor por escopo, quem abre cada relatório, favoritos
(isolados por usuário, só filtros conhecidos). 11 mutações detectadas.
Também conferido: todas as telas sem a migração 013 e sem a 014.
