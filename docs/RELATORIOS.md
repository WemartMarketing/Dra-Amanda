# Central de Relatórios — visão geral

| Etapa | O quê | Documento |
|---|---|---|
| 1 | Auditoria dos dados, dicionário de indicadores | `RELATORIOS_AUDITORIA.md`, `RELATORIOS_DICIONARIO.md` |
| 2 | Histórico estruturado (atribuições, etapas, contratos, fila, origens/UTM) e motor | `RELATORIOS_ETAPA2.md` |
| 3 | Telas: central, executiva, corretor, gestão, empreendimentos; filtros e favoritos | `RELATORIOS_ETAPA3.md` |
| 4 | Funil, plantões, origens/UTM, visitas e vendas, perdas | `RELATORIOS_ETAPA4.md` |
| 5 | Exportações Excel/CSV/PDF, fila em segundo plano | `RELATORIOS_ETAPA5.md` |
| 6 | Investimento e custos, modelos, qualidade, volume | `RELATORIOS_ETAPA6.md` |

## Migrações (na pasta do sistema, nesta ordem)

```
mysql -u wemart86_Aurea -p wemart86_Aurea < sql/migration_013_relatorios_historico.sql
php -q ferramentas/retroalimentar_historico.php
mysql -u wemart86_Aurea -p wemart86_Aurea < sql/migration_014_relatorios_favoritos.sql
mysql -u wemart86_Aurea -p wemart86_Aurea < sql/migration_015_relatorios_exportacoes.sql
mysql -u wemart86_Aurea -p wemart86_Aurea < sql/migration_016_relatorios_investimentos_modelos.sql
php -q ferramentas/auditoria_relatorios.php
php -q ferramentas/medir_relatorios.php
```

Todas são não destrutivas e podem ser repetidas. O sistema funciona antes de
cada uma (testado); o que depende dela aparece como "não disponível".

## Princípios

- Uma única fonte de cálculo (`includes/relatorios/motor.php` e
  `especializados.php`) para tela, gráfico, Excel, CSV e PDF.
- Permissão sempre no SQL; filtro inválido → resultado vazio.
- Nada inventado: sem base → "—"; reconstruído de registros → marcado;
  investimento só o lançado; ROI não calculado sem receita.
- Histórico pela estrutura da data (o gestor vê o que a equipe dele recebeu,
  mesmo depois que o lead saiu dela).

## Testes

`for t in tests/relatorios_etapa*_test.php; do php -q $t; done` e, para volume,
`php -q tests/relatorios_volume.php`.
