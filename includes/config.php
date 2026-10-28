<?php
/**
 * AURÉA — Configuração central do projeto.
 * Edite este arquivo para reutilizar o site com outra clínica/profissional.
 * Nenhuma informação de contato, formação ou registro profissional foi
 * inventada: os campos abaixo marcados como PENDENTE devem ser preenchidos
 * pela profissional responsável antes da publicação.
 */

// ----------------------------------------------------------------------
// Identidade
// ----------------------------------------------------------------------
define('SITE_NAME', 'AURÉA');
define('SITE_TAGLINE', 'Harmonização Facial');
define('SITE_LOGO', 'assets/images/logo.svg'); // PENDENTE: logo próprio opcional (texto é usado por padrão)

// ----------------------------------------------------------------------
// Paleta (também definida em assets/css/variables.css — mantenha em sincronia
// caso precise consultar os valores a partir do PHP)
// ----------------------------------------------------------------------
define('COLOR_GOLD_PRIMARY', '#9B7A46');
define('COLOR_GOLD_DARK', '#806339');
define('COLOR_GOLD_SOFT', '#AD9369');
define('COLOR_TEXT_PRIMARY', '#342E29');
define('COLOR_TEXT_SECONDARY', '#50483E');
// Fundo branco puro — os quadros têm o fundo de estúdio removido
// (transparência real), então a página inteira é o "ambiente" do rosto.
define('COLOR_BACKGROUND_VIDEO', '#FFFFFF');

// ----------------------------------------------------------------------
// WhatsApp / Agendamento
// ----------------------------------------------------------------------
// PENDENTE: número da clínica não foi fornecido. Preencha no formato
// internacional sem símbolos, ex.: "5511999999999". Enquanto estiver vazio,
// o botão de agendamento exibe um aviso de configuração pendente em vez de
// um link falso.
define('WHATSAPP_NUMBER', '');
define('WHATSAPP_DEFAULT_MESSAGE', 'Olá! Gostaria de agendar uma avaliação de harmonização facial.');

// ----------------------------------------------------------------------
// Profissional / Clínica — "Sobre nós"
// PENDENTE: nenhum dado foi fornecido pelo cliente. Não foram inventadas
// formação, especialidade, registro profissional, biografia, endereço ou
// foto. Preencha os campos abaixo antes de publicar a seção "Sobre nós".
// ----------------------------------------------------------------------
return [
    'professional' => [
        'name'        => '', // PENDENTE
        'specialty'   => '', // PENDENTE
        'license'     => '', // PENDENTE — registro profissional (ex.: CRM/CRO + UF)
        'bio'         => '', // PENDENTE
        'photo'       => '', // PENDENTE — caminho para assets/images/
        'address'     => '', // PENDENTE
        'clinic_name' => '', // PENDENTE
    ],
    'contact' => [
        'whatsapp_display' => '', // PENDENTE — número formatado para exibição
        'instagram'         => '', // PENDENTE — @usuario
        'address'           => '', // PENDENTE
        'hours'             => '', // PENDENTE
    ],
];
