<?php
/**
 * WeMart Sales — configuração do ambiente.
 *
 * Copie este arquivo para includes/config.php e preencha com as
 * credenciais reais do banco MySQL (cPanel → MySQL® Databases).
 * includes/config.php NUNCA deve ser commitado com segredos reais —
 * está listado no .gitignore.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'troque_pelo_nome_do_banco');
define('DB_USER', 'troque_pelo_usuario');
define('DB_PASS', 'troque_pela_senha');

// Nome da aplicação exibido na interface.
define('APP_NAME', 'WeMart Sales');

// Mostra na tela de login as contas de demonstração (senha pública
// demo123). Deixe false em produção; true só em ambiente de testes.
define('MOSTRAR_CONTAS_DEMO', false);

// Libera os testes automatizados (php tests/…) que criam e apagam uma
// empresa fictícia. NUNCA defina como true no servidor de produção.
define('TESTES_PERMITIDOS', false);

// Fuso horário usado para exibir datas/horas.
date_default_timezone_set('America/Sao_Paulo');

// ----------------------------------------------------------------------
// Chave de cifragem da aplicação (Etapa 5) — usada para guardar no banco,
// cifrados, os tokens das páginas do Facebook. Gere UMA vez por servidor:
//   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
// e nunca troque depois (tokens já salvos deixariam de abrir). Sem ela, a
// tela Integrações recusa salvar tokens.
// ----------------------------------------------------------------------
define('APP_KEY', '');

// ----------------------------------------------------------------------
// Meta Lead Ads (Etapa 5) — credenciais do APLICATIVO da WeMart no
// developers.facebook.com (ver docs/INTEGRACAO_META.md). Os tokens de cada
// página ficam no banco (cifrados), cadastrados pela tela Integrações.
// ----------------------------------------------------------------------
define('META_APP_SECRET', '');     // Configurações do app → Básico → Chave secreta do aplicativo
define('META_VERIFY_TOKEN', '');   // texto livre e longo que você inventa e cola no painel de Webhooks
define('META_GRAPH_VERSION', 'v21.0'); // versão da Graph API; atualize quando a Meta descontinuar a atual
define('META_GRAPH_BASE_URL', 'https://graph.facebook.com');
