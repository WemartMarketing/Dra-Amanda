# WhatsApp e Jornadas Inteligentes

Módulo nativo do WeMart Sales para manter o relacionamento com o lead
mesmo quando nenhum corretor está online, usando **somente** a WhatsApp
Business Platform (Cloud API oficial da Meta).

> **Situação atual: Fases 1, 2 e 3 concluídas.** O envio, o recebimento e os
> status já estão programados e testados contra um **simulador** da Graph
> API, mas **ainda não foram validados com uma conta real da Meta**.
> Toda conta nova nasce em **modo de teste**: nada é enviado até alguém
> ligar o envio real conscientemente (ver
> [Dependências externas](#dependências-externas-meta)).

## Como o módulo se encaixa no que já existe

| Já existia | Como o módulo usa |
|---|---|
| Webhook da Meta Lead Ads (`webhook/meta.php`, HMAC com `META_APP_SECRET`) | Mesmo app da Meta e mesmo modelo de "caixa de entrada" (`meta_eventos` → `whatsapp_webhook_eventos`) |
| Tokens cifrados com `APP_KEY` (`includes/cripto.php`) | Token do WhatsApp de cada empresa, nunca em texto puro |
| Roleta, fila de espera e rodízio (`includes/roleta.php`) | A automação **não** distribui lead: quem distribui continua sendo a roleta, com presença e elegibilidade |
| Recuperação por 4 dias sem feedback (`includes/recuperacao.php`) | A jornada consulta e respeita essa regra (Fase 6) |
| Transferência de lead (`includes/transferencia.php`) | Conversa e jornada seguem o lead, sem recomeçar |
| Permissões por função (`funcoes`, `rbac.php`) | Acesso à conversa = acesso ao lead; permissões novas abaixo |
| Cron com trava (`cron/rotinas.php`, `GET_LOCK`) | Nova etapa "automação" executa a fila de tarefas |
| Auditoria (`auditoria_registrar`) e histórico do lead (`lead_eventos`) | Consentimento, envios e passos da jornada são registrados |

Nenhum módulo anterior foi removido ou duplicado.

## Plano em 7 fases

| Fase | Entrega | Situação |
|---|---|---|
| 1 | Banco, fila persistente, regras de envio/consentimento, horários das roletas, testes | **concluída** |
| 2 | Webhook `webhook/whatsapp.php` (mensagens + status), cliente da Cloud API com **modo de teste**, sincronização de templates, tela de conexão | **concluída** (validação real pendente) |
| 3 | Central de conversas (3 colunas no computador, tela simples no celular), botão **Conversar no WhatsApp** na ficha e nos cards | **concluída** |
| 4 | Jornada "novo lead": boas-vindas por empreendimento, oferta e envio do book (PDF), fila quando a roleta está fechada | a fazer |
| 5 | Follow-ups (esperar/condição), central de pendências do corretor ("o cliente respondeu?") | a fazer |
| 6 | Integração com recuperação/redistribuição, tela de horário por dia, pausas e feriados das roletas | a fazer |
| 7 | Editor visual de jornadas com simulação, campanhas/disparador, relatórios e exportação Excel | a fazer |

## Fase 1 — o que foi entregue

### Banco (`sql/migration_008_whatsapp_jornadas.sql`)

| Tabela | Para quê |
|---|---|
| `whatsapp_contas` | Conta/número de cada empresa. `modo_teste = 1` por padrão: **nada sai para a Meta** até alguém desligar conscientemente |
| `whatsapp_config` | Teto de mensagens automáticas (24 h) e de marketing (7 dias), horário de envio, palavras de descadastro, resposta quando ninguém pode atender |
| `whatsapp_tarifas` | Valores para **estimar** custo (vazia: o Superadmin cadastra; a cobrança real é da Meta) |
| `whatsapp_templates` | Templates aprovados + ligação das variáveis com os dados do lead |
| `whatsapp_contatos` | Um contato por empresa + telefone; janela de 24 h; descadastro |
| `whatsapp_consentimentos` | Histórico de autorizações (só acrescenta): finalidade, origem, evidência, data, quem registrou |
| `whatsapp_conversas` / `whatsapp_mensagens` | Conversa ligada ao lead; status enviada/entregue/lida/falha; deduplicação por `wamid` e por chave de idempotência |
| `whatsapp_webhook_eventos` | Avisos da Meta gravados antes de processar (repetidos são descartados) |
| `automacao_fila` | Fila persistente de tarefas para o cron |
| `jornadas`, `jornada_passos`, `jornada_execucoes`, `jornada_logs` | Jornadas, blocos, uma execução por (jornada, lead) e trilha de cada passo |
| `corretor_pendencias` | Central de pendências do corretor |
| `whatsapp_campanhas`, `whatsapp_campanha_destinatarios` | Estrutura do disparador (regras e telas na Fase 7) |
| `roleta_horarios`, `roleta_excecoes` | Horário por dia, pausa, feriado e horário especial das roletas |

Também acrescenta em `empreendimentos` o book (PDF) e o template de
boas-vindas, o tipo `jornada` no histórico do lead e quatro permissões:

| Permissão | Funções padrão que recebem |
|---|---|
| `whatsapp_atender` — conversar com clientes | Master, Superintendente, Gerente, Corretor |
| `whatsapp_configurar` — conta, templates e books | Master |
| `gerenciar_jornadas` — criar e ativar jornadas | Master |
| `gerenciar_campanhas_wa` — campanhas e disparos | Master |

### Código

- **`includes/whatsapp_regras.php`**: o único lugar que decide se uma mensagem pode sair.
  - Mensagem livre só com a janela de 24 h aberta. Fora dela, só template **aprovado**.
  - Template de utilidade exige autorização de *atendimento*. Template de marketing exige autorização de *marketing*. Preencher formulário **não** é tratado como autorização.
  - Descadastro bloqueia automação e template. O corretor só volta a poder responder se o próprio cliente escrever de novo.
  - Teto de mensagens automáticas por contato em 24 h e de marketing em 7 dias.
  - Horário de envio para follow-ups. As boas-vindas imediatas podem sair às 23h40 com `ignorar_horario`.
  - Variáveis do template validadas antes do envio: dado vazio ou fora da lista bloqueia, em vez de mandar "{{1}}" para o cliente.
  - Telefone normalizado para o padrão do WhatsApp.
  - O descadastro só acontece quando a mensagem **inteira** é a palavra-chave, como "PARAR" ou "Sair.". A frase "não quero parar de receber" não descadastra.
- **`includes/automacao_fila.php`**: a fila de tarefas.
  - Uma tarefa não entra duas vezes, graças à `chave_unica`.
  - A reivindicação é atômica: duas execuções nunca pegam a mesma tarefa.
  - Falha temporária volta para a fila com espera de 1, 5, 15, 60 e 240 minutos, até o limite de tentativas.
  - Falha definitiva para na hora.
  - "Adiar" não gasta tentativa.
  - Tarefa presa por queda do PHP volta para a fila após 15 minutos.
  - É possível cancelar as tarefas pendentes de uma execução.
- **`includes/automacao.php`**: o registro dos tipos de tarefa, vazio por enquanto. O cron já executa a etapa "automação", mas ela não faz nada até a Fase 2 registrar os primeiros tipos.
- **`includes/roleta_horarios.php`**: o expediente das roletas.
  - Exceção da data: a da roleta vence a da empresa. "Fechado" ou "horário especial".
  - Grade por dia da semana, com pausa e com horário que atravessa a meia-noite.
  - Sem grade cadastrada, vale o horário antigo da roleta.
  - Também informa **quando a roleta abre de novo** ("amanhã às 09:30"), para as mensagens ao cliente.
- **`roleta_no_horario()`** (usada pela distribuição, pela presença e pelas telas) passou a usar essa regra sem mudar sua assinatura. A tela atual de roletas grava o horário simples nas duas estruturas, mas nunca sobrescreve uma grade personalizada.

### Testes (`tests/whatsapp_fase1_test.php`)

```
php tests/whatsapp_fase1_test.php
```

- São 113 verificações e todas passam, tanto no PHP de linha de comando quanto no `php` CGI da HostGator.
- Cobrem telefone, janela de 24 h, descadastro, variáveis, toda a matriz de decisão de envio, horário de envio, expediente das roletas (pausa, feriado, exceção, meia-noite), fila (duplicidade, corrida entre duas execuções, retentativa, limite, adiamento, recuperação de travadas, cancelamento), isolamento entre empresas, consentimento, descadastro com cancelamento do que estava na fila, deduplicação de webhook e mensagem, jornada que não recomeça e sincronização das roletas.
- A empresa fictícia criada para o teste é apagada no final.
- Os testes só rodam com `define('TESTES_PERMITIDOS', true);` no `includes/config.php`. **Nunca** ligue isso em produção. A pasta `tests/` é bloqueada para o navegador.
- Regressão conferida:
  - instalação do zero com todas as migrações;
  - migrações antigas rodadas de novo depois da 008, sem desfazer nada;
  - as 20 telas existentes nos perfis corretor, gerente e gestor, com o mesmo resultado de antes;
  - salvar a roleta pela tela atual atualiza a grade nova.

## Fase 2 — o que foi entregue

### Arquivos

| Arquivo | Papel |
|---|---|
| `webhook/whatsapp.php` | Webhook público. Na verificação (GET), confere o token. Os avisos (POST) só são aceitos com assinatura HMAC válida; sem ela ou com ela errada → 403 e nada é gravado. Cada mensagem ou status vira um aviso com chave única, então um aviso repetido pela Meta é descartado. A Meta recebe 200 na hora e o processamento vem depois (o que falhar fica para o cron). |
| `includes/whatsapp_api.php` | Cliente da Cloud API: token só no cabeçalho `Authorization` (nunca na URL), TLS verificado e timeouts curtos. Monta texto, template (variáveis numeradas ou nomeadas), documento, botões e envio de arquivo. Também traduz cada erro da Meta para português e diz se vale tentar de novo. |
| `includes/whatsapp.php` | Envio pela fila, recebimento de mensagens, status, templates, teste de conexão e inscrição no webhook |
| `api/whatsapp.php` + `pages/whatsapp.php` | Tela **WhatsApp** no menu, com as abas Conexão, Templates e Atividade (só para quem tem `whatsapp_configurar`) |
| `sql/migration_009_whatsapp_fase2.sql` | Guarda quando o recebimento foi ativado e quando chegou o último aviso |

### Como uma mensagem sai

1. `wa_enfileirar()` confere as regras (consentimento, janela, template
   aprovado, descadastro, teto e horário) e valida as variáveis. Se puder,
   grava a mensagem como **na fila** e agenda a tarefa. A chave de
   idempotência impede a mesma mensagem duas vezes.
2. O cron, o webhook ou o botão "Enviar teste" executam a tarefa. **As
   regras são conferidas de novo** neste momento, porque o cliente pode
   ter se descadastrado ou revogado a autorização depois do agendamento.
3. **Modo de teste**: a mensagem é marcada como "simulada" e nada chega à
   Meta. **Envio real**: a mensagem vai para a Cloud API.
4. O resultado da Cloud API é tratado assim:
   - **Erro temporário** (limite de envio, Meta fora do ar): volta para a
     fila com espera crescente.
   - **Erro definitivo** (fora das 24 h, template pausado, número inválido):
     falha registrada, sem insistir.
   - **Efeitos automáticos**: token inválido → a conta fica "com erro" e
     nada mais sai; template pausado ou desativado pela Meta → marcado
     aqui; cliente que saiu do marketing → autorização de marketing
     revogada.

### Como uma mensagem chega

- A empresa é identificada **só** pelo número que recebeu a mensagem
  (`phone_number_id` cadastrado). Número desconhecido → aviso ignorado.
- O contato é procurado com e sem o 9 do celular, porque contas antigas
  do WhatsApp usam 8 dígitos. Ele é ligado ao lead da empresa com o mesmo
  telefone, em qualquer formato.
- A mensagem abre a janela de 24 h, aparece no histórico do lead e soma
  nas não lidas.
- A mensagem do cliente **não** conta como atividade do corretor, para não
  esconder um lead parado.
- Uma reação (👍) não abre a janela.
- "PARAR" (ou outra palavra configurada) descadastra o contato. Frases
  como "não quero parar" não descadastram.
- Status (enviada → entregue → lida) **só avança**: um aviso atrasado não
  volta o status. Se a confirmação chega antes de o envio ter sido
  gravado, o aviso é tentado de novo.
- Mudança de status de template enviada pela Meta (aprovado, pausado,
  rejeitado) é aplicada na hora.

### Passo a passo para conectar o número real

1. No `includes/config.php` do servidor: `META_APP_SECRET`,
   `META_VERIFY_TOKEN` (ou um `WA_VERIFY_TOKEN` próprio) e `APP_KEY`,
   todos já usados pelo Lead Ads.
2. No app da Meta → WhatsApp → Configuração:
   - URL do webhook = a que aparece na tela **WhatsApp → Conexão**;
   - token de verificação = o do config;
   - assinar os campos `messages` e `message_template_status_update`.
3. Na tela **WhatsApp → Conexão**:
   - **Conectar número oficial**: IDs da WABA e do número, e o token
     permanente;
   - **Testar conexão**;
   - **Ativar recebimento**.
4. **Templates → Sincronizar com a Meta**. Em cada template: para que
   serve, a qual dado cada variável se liga e o que cada botão faz.
5. **Enviar teste** para o seu próprio número, ainda em modo de teste
   (fica "simulado").
6. Só então **Ligar envio real**: exige digitar ENVIAR DE VERDADE e ter a
   conexão testada e o recebimento ativado. Faça um novo **Enviar teste**
   com um template de utilidade (ex.: `hello_world`) e confira a entrega
   na aba **Atividade**. **Esse é o primeiro teste real da integração.**

### Testes (`tests/whatsapp_fase2_test.php`)

```
php tests/whatsapp_fase2_test.php
```

- São 97 verificações e todas passam.
- O teste sobe um **simulador da Graph API** (`tests/mock_graph.php`) e o
  próprio sistema, ambos em 127.0.0.1. Nenhuma requisição sai para a Meta.
- O simulador só responde no servidor de testes do PHP (`php -S`), além de
  a pasta `tests/` ser bloqueada no `.htaccess`.
- Cobrem:
  - **Webhook:** assinatura, verificação, aviso repetido, número
    desconhecido, isolamento entre empresas, celular sem o 9, botão,
    reação, PARAR.
  - **Envio:** montagem das requisições (token só no cabeçalho, ordem das
    variáveis), idempotência, teto de frequência, regras conferidas de
    novo na hora do envio, modo de teste sem nenhuma chamada.
  - **Erros da Meta:** limite (tenta de novo e envia), fora da janela (não
    insiste), template pausado, token expirado (conta bloqueada), Meta
    fora do ar.
  - **Status:** fora de ordem e antes do envio ser gravado.
  - **Templates:** sincronização com paginação, preservando o que foi
    configurado no WeMart; template apagado na Meta; aviso de template
    pausado.
  - **Conta e cron:** teste de conexão, inscrição no webhook, cron.
- Para conferir que os testes pegam erros de verdade, três proteções foram
  removidas de propósito (assinatura, ordem dos status, nova conferência
  das regras) e os testes acusaram cada uma.
- Também foi feito o fluxo completo **pela tela**, contra o simulador:
  conectar, testar, ativar recebimento, sincronizar e configurar template,
  ligar envio real (recusando a frase errada), enviar teste, confirmações
  de entrega e leitura e resposta do cliente.

## Fase 3 — Central de conversas

### O que o usuário vê

- **Menu WhatsApp:**
  - quem atende vai direto para **Conversas**, com o número de conversas
    com mensagem nova (no celular, o número aparece em "Mais");
  - quem configura vê também as abas Templates, Conexão e Atividade.
- **Computador**, em três colunas:
  - **lista:** busca por nome ou telefone; filtros "Não lidas",
    "Aguardando você" e "Sem lead" (este só para o Master);
  - **conversa:** bolhas com quem enviou, horário e ✓ enviada, ✓✓
    entregue, ✓✓ azul lida, ⚠ falhou com o motivo;
  - **dados do cliente:** empreendimento, corretor, etapa, próximas
    atividades e autorizações (LGPD).
- **Celular:** uma tela por vez (lista → conversa → dados do cliente), com
  letras e botões grandes. O aviso de modo de teste some com a conversa
  aberta, porque cada mensagem já avisa.
- **Botão "Conversar no WhatsApp"** no card do Kanban, na lista de leads,
  no Início e na ficha: abre a conversa **dentro do WeMart**. Se ainda não
  existe conversa, ela é criada. Se a empresa ainda não ligou o WhatsApp,
  o botão continua abrindo o WhatsApp do aparelho, como antes.
- **O que dá para enviar**, conforme a situação:
  - **cliente escreveu nas últimas 24 h:** texto livre e anexos (PDF, JPG
    e PNG de até 15 MB);
  - **fora das 24 h:** só um template aprovado, com prévia já preenchida
    com os dados do lead. Template com dado faltando não aparece como
    pronto, para não enviar "{{2}}" ao cliente;
  - **sem autorização registrada:** um aviso explica, e o corretor pode
    registrar como o cliente autorizou. A evidência fica no histórico da
    LGPD;
  - **cliente pediu para sair:** nada pode ser enviado, a não ser que ele
    volte a escrever.
- **Mensagens novas aparecem sozinhas:** a tela consulta a cada 5 segundos
  enquanto está aberta e para quando a aba fica em segundo plano. Não usa
  WebSocket nem Node.js.
- **Arquivos que o cliente manda** (foto, documento) são baixados da Meta
  só na primeira vez que alguém abre, e só por quem pode ver a conversa.
  Em modo de teste não há download.

### Quem vê o quê

- **A conversa segue o lead**: quem enxerga o lead (pela função, equipe,
  superintendência ou carteira) enxerga a conversa.
- Quando o lead é **transferido**, o corretor e o gerente anteriores
  perdem o acesso na hora. O novo responsável recebe o histórico completo.
  Nada é copiado.
- Número que ainda não é lead só aparece para o **Master**, que pode
  ligá-lo a um lead.
- Se um cliente escreveu **antes** de virar lead, inclusive de um número
  antigo sem o 9, a conversa é reaproveitada quando o corretor abre pelo
  lead. Ele vê o que o cliente já tinha escrito.
- Tentativas de abrir conversa fora da carteira, pela tela ou direto pela
  API, recebem "não encontrada" (404), sem confirmar se ela existe.

### Arquivos

`includes/whatsapp_conversas.php` (escopo, lista, abrir pelo lead,
mídia), `api/conversas.php`, `api/whatsapp_midia.php`,
`pages/conversas.php`, `assets/js/conversas.js`. Botões alterados em
`includes/lead_componentes.php`, `pages/leads.php`, `pages/inicio.php` e
`pages/lead.php`. Não há migração nova.

### Testes (`tests/whatsapp_fase3_test.php`)

- São 47 verificações e todas passam. Cobrem:
  - quem vê cada conversa (corretor, outro corretor, gerente, Master,
    outra empresa);
  - filtros e busca;
  - contador de não lidas;
  - carregar só as mensagens novas;
  - resposta do corretor;
  - transferência;
  - botão pelo lead (criar, reaproveitar, lead de outro corretor, sem
    telefone, número antigo sem o 9);
  - link interno x wa.me;
  - templates prontos;
  - caminhos de arquivo.
- Para conferir que os testes pegam erros de verdade, a regra de escopo
  foi desligada de propósito, e 8 verificações acusaram o vazamento.
- Também foi feito o fluxo pela tela com as contas de demonstração:
  - o corretor responde e envia um PDF;
  - uma mensagem nova chega e aparece sem recarregar;
  - ele registra a autorização do cliente e envia um template;
  - ele tenta abrir a conversa de outro corretor (bloqueado na tela e na
    API);
  - o Master liga um número desconhecido a um lead;
  - tudo conferido no iPhone e num Android de 360 px, sem rolagem lateral.

## Dependências externas (Meta)

Tudo abaixo depende de credenciais ou aprovação da Meta e **ainda não
foi validado**. Confirme os passos no painel da Meta, porque nomes e
telas mudam com frequência.

1. **App da Meta da WeMart** (o mesmo do Lead Ads) com o produto
   *WhatsApp* adicionado. O `META_APP_SECRET` assina os webhooks.
2. Para cada empresa cliente:
   - **conta do WhatsApp Business (WABA)**;
   - **número registrado na Cloud API**, com nome de exibição aprovado;
   - **token de acesso permanente** de usuário do sistema com as
     permissões de mensagens e de gerenciamento do WhatsApp Business;
   - **forma de pagamento** na WABA (mensagens de template são cobradas
     pela Meta).
3. **Webhook** do app assinando o campo `messages` (mensagens e status),
   apontando para `webhook/whatsapp.php` (Fase 2).
4. **Templates** criados e **aprovados** no WhatsApp Manager: boas-vindas
   com botões de resposta rápida, lembrete, book etc. O sistema só usa
   template aprovado.
5. Limites de envio e qualidade do número são definidos pela Meta. O
   sistema vai registrar e respeitar esses limites, não contorná-los.
6. **Embedded Signup** (a empresa conecta o próprio número com poucos
   cliques): a estrutura já prevê `origem_conexao = 'embedded_signup'`,
   mas depende de a WeMart ser habilitada pela Meta como provedora. Isso
   **não** está assumido.

## Instalação no servidor

Depois do `git pull`, rode a migração 008 (ver `docs/DEPLOY_HOSTGATOR.md`,
passo 14). Ela só cria estrutura: nenhum comportamento visível muda para
os usuários, exceto que o horário das roletas passa a ser lido da grade
por dia, convertida automaticamente do horário atual.
