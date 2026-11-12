# WhatsApp e Jornadas Inteligentes

Módulo nativo do WeMart Sales para manter o relacionamento com o lead
mesmo quando nenhum corretor está online, usando **somente** a WhatsApp
Business Platform (Cloud API oficial da Meta).

> **Situação atual: Fase 1 concluída (estrutura).** Nenhuma mensagem é
> enviada ainda: não existe cliente da API nem webhook do WhatsApp. Nada
> neste documento deve ser lido como "integração funcionando" antes de
> ser validado com credenciais reais da Meta (ver
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
| 2 | Webhook `webhook/whatsapp.php` (mensagens + status), cliente da Cloud API com **modo de teste**, sincronização de templates, tela de conexão | a fazer |
| 3 | Central de conversas (3 colunas no computador, tela simples no celular), botão **Conversar no WhatsApp** na ficha e nos cards | a fazer |
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
