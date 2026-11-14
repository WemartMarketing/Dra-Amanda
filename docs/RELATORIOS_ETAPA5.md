# Central de Relatórios — Etapa 5: exportações

## Implantação

Na pasta do sistema, depois do `git pull`:

```
mysql -u wemart86_Aurea -p wemart86_Aurea < sql/migration_015_relatorios_exportacoes.sql
```

Cria só a fila das exportações grandes. Sem ela, todas as exportações
funcionam na hora (até 50.000 linhas); com ela, a "exportação de dados"
acima de 2.000 leads é preparada pelo cron já existente (`cron/rotinas.php`,
etapa "exportações") e aparece em **Relatórios → Minhas exportações**.
Os arquivos ficam em `storage/exportacoes/` (bloqueado para o navegador pelo
`storage/.htaccess`) e são apagados quando expiram (24 h).

## Como usar

Em qualquer relatório da Central: **Exportar** (para quem tem a permissão
*Exportar relatórios*). O arquivo leva exatamente os filtros e o período da tela.

| Conteúdo | Formatos | O que vem |
|---|---|---|
| Relatório resumido | Excel, CSV, PDF | indicadores + tabela principal |
| Relatório completo | Excel, CSV, PDF | indicadores, todas as tabelas, evolução, notas e definições do dicionário |
| Exportação de dados | Excel, CSV | uma linha por lead do período (origem, UTM, etapa, corretor, 1ª entrega, 1º atendimento, 1º contato efetivo, visita, contrato, VGV). **Sem telefone e sem e-mail.** Exige confirmar o uso adequado (LGPD) |

- **Excel**: abas *Resumo* (empresa, relatório, período, comparação, filtros pelo
  nome, data/hora, fuso, quem gerou + indicadores com período anterior e
  variação), *Detalhamento* (tabelas) e *Notas* (avisos e definições). Números,
  moeda e datas tipados. **CSV**: UTF-8 com BOM, `;`, vírgula decimal — só a
  tabela principal. **PDF**: relatório executivo com a identidade WeMart Sales,
  cartões de indicadores, gráfico da evolução, tabelas paginadas com cabeçalho
  repetido, notas e "Página X de Y".
- **Segurança**: mesma permissão e mesmo escopo da tela; texto que começa com
  `= + - @` é gravado como texto (não vira fórmula); cada exportação (e cada
  download da fila) fica na auditoria com relatório, formato, filtros e linhas.
  Na fila, o arquivo é gerado com a permissão **atual** de quem pediu; o link
  só funciona para essa pessoa logada, com o token do link, por 24 h.
- O PDF é gerado por um gerador próprio (`includes/pdf_simples.php`), sem
  bibliotecas — funciona na HostGator sem Composer.

## Testes

`php -q tests/relatorios_etapa5_test.php` — 60 verificações: mesmos números
da tela, cabeçalho, abas, tipos, fórmulas neutralizadas, sem telefone/e-mail,
escopo do gerente, CSV brasileiro, PDF bem formado (xref, páginas, acentos,
continuação de tabela), permissões, ciclo da fila (gerar, baixar só com o
token e o dono, expirar e apagar, permissão retirada, geração travada).
13 mutações detectadas. Conferido também no navegador e sem a migração 015.
