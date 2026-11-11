# Checklist de testes — WeMart Sales

Roteiro manual para conferir uma instalação (local ou HostGator) depois de importar o banco na ordem de `docs/DEPLOY_HOSTGATOR.md`. Senha de todas as contas de demonstração: `demo123`. Marque cada item.

## 1. Acesso e segurança
- [ ] `login.php` abre; 5 senhas erradas seguidas bloqueiam por alguns minutos.
- [ ] `/uploads/`, `/storage/`, `/storage/lead_arquivos/`, `/storage/backups/`, `/includes/`, `/sql/`, `/cron/rotinas.php`, `/cron/backup.php` → **403** (só em Apache; o `php -S` ignora `.htaccess`).
- [ ] `/webhook/meta.php` sem assinatura → **403**.
- [ ] Rodapé e login têm o link "privacidade e uso dos dados".

## 2. Corretor (`carlos@hmvendas.demo`)
- [ ] Entra no **Início** (não no Dashboard); menu sem Dashboard, Perdas, Auditoria e Exportar.
- [ ] `/pages/dashboard.php`, `/pages/relatorios.php`, `/pages/auditoria.php` → "Acesso restrito".
- [ ] Meu Kanban: mover um lead de etapa; ficha → Atendimento → salvar; Minha agenda → marcar realizado.
- [ ] Minha roleta: verificar localização (HTTPS no celular).

## 3. Gerente (`ana.gerente@hmvendas.demo`)
- [ ] Entra no **Dashboard**; só aparecem corretores da Equipe Zona Oeste nos filtros e na Produtividade.
- [ ] Sem botão Exportar (a função Gerente não tem *Exportar relatórios* por padrão).
- [ ] Perdas e transferências mostra só as saídas da própria equipe.

## 4. Superintendente / GP (`camila.super@…`, `patricia.produto@…`)
- [ ] Camila vê só a Zona Sul; agrupamento inicial por equipe.
- [ ] Patrícia vê só Smart Marquês e Flow Itaim (empreendimentos vinculados).

## 5. Master (`fernanda.gestora@hmvendas.demo`)
- [ ] Dashboard: trocar o período (Hoje, 7/30/90 dias, Mês atual, Mês anterior, Personalizado); os indicadores mostram a variação contra o período anterior.
- [ ] Tocar em cada **?** mostra a explicação.
- [ ] Produtividade: mudar o agrupamento e ordenar clicando nos títulos; a linha **Total** bate com "Leads recebidos".
- [ ] "Ver como tabela" abaixo do gráfico de leads por dia.
- [ ] **Exportar** → baixa .xlsx que abre no Excel/LibreOffice com cabeçalho em negrito e números somáveis.
- [ ] **Leads → Exportar**: o botão só baixa depois de marcar a confirmação da LGPD.
- [ ] **Auditoria**: as exportações aparecem, com filtros e paginação funcionando.
- [ ] Ficha de um lead → *Mais → Exportar dados do titular*: arquivo JSON só daquele lead.
- [ ] Ficha → *Mais → Anonimizar lead*: sem digitar ANONIMIZAR não deixa; depois, nome/telefone/e-mail somem e o Dashboard continua com os mesmos números.
- [ ] Cadastrar lead com campanha; editar a campanha na ficha; ela aparece em "Por campanha".
- [ ] Transferir um lead para outro corretor: no Dashboard ele volta a contar como "sem atendimento" até o novo corretor registrar contato.

## 6. Rotinas
- [ ] `php cron/rotinas.php` termina sem "ERRO".
- [ ] `php cron/backup.php` cria `storage/backups/wemart-….sql.gz`; rodando 8 vezes, ficam só 7 arquivos.
- [ ] Restaurar o backup num banco vazio (`gunzip -c arquivo.sql.gz | mysql -u U -p BANCO_TESTE`) e conferir que as tabelas têm as mesmas contagens.

## 7. Celular (390 px de largura)
- [ ] Nenhuma tela rola para o lado; Dashboard, Perdas e Auditoria viram cartões.
- [ ] Textos de operação (nomes, datas, botões, tabelas) com letra de pelo menos 16 px.
- [ ] Barra inferior: gestor vê Dashboard no primeiro botão; corretor vê Início.
