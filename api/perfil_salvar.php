<?php
/**
 * POST /api/perfil_salvar.php — "Meu perfil".
 *   { nome, email, senha_atual, nova_senha?, confirmar_senha? }
 * Trocar e-mail ou senha exige a senha atual. Erros de senha atual contam
 * no mesmo limite de tentativas do login (5 em 15 minutos).
 */
require_once __DIR__ . '/../includes/api.php';

$usuario = api_iniciar(null);
$uid = (int) $usuario['id'];

$nome = api_texto($_POST['nome'] ?? null);
$email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
$senhaAtual = (string) ($_POST['senha_atual'] ?? '');
$novaSenha = (string) ($_POST['nova_senha'] ?? '');
$confirmar = (string) ($_POST['confirmar_senha'] ?? '');

if (!$nome) {
    api_erro('Informe seu nome.', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_erro('E-mail inválido.', 422);
}

$stmt = db()->prepare('SELECT email, senha_hash FROM usuarios WHERE id = :id');
$stmt->execute(['id' => $uid]);
$atual = $stmt->fetch();
$trocaEmail = $email !== mb_strtolower($atual['email']);
$trocaSenha = $novaSenha !== '';

if ($trocaEmail || $trocaSenha) {
    if (auth_tentativas_recentes($atual['email']) >= LOGIN_MAX_TENTATIVAS) {
        api_erro('Muitas tentativas. Tente novamente em alguns minutos.', 429);
    }
    if (!password_verify($senhaAtual, $atual['senha_hash'])) {
        auth_registrar_tentativa($atual['email'], false);
        api_erro('Senha atual incorreta.', 422);
    }
}
if ($trocaSenha) {
    if (mb_strlen($novaSenha) < 8) {
        api_erro('A nova senha precisa ter pelo menos 8 caracteres.', 422);
    }
    if ($novaSenha !== $confirmar) {
        api_erro('A confirmação não confere com a nova senha.', 422);
    }
}
if ($trocaEmail) {
    $stmt = db()->prepare('SELECT 1 FROM usuarios WHERE email = :email AND id <> :id');
    $stmt->execute(['email' => $email, 'id' => $uid]);
    if ($stmt->fetchColumn()) {
        api_erro('Este e-mail já está em uso.', 422);
    }
}

db()->prepare('UPDATE usuarios SET nome = :nome, email = :email WHERE id = :id')->execute(['nome' => $nome, 'email' => $email, 'id' => $uid]);
if ($trocaSenha) {
    db()->prepare('UPDATE usuarios SET senha_hash = :hash WHERE id = :id')->execute(['hash' => password_hash($novaSenha, PASSWORD_DEFAULT), 'id' => $uid]);
    session_regenerate_id(true);
}
auditoria_registrar((int) $usuario['empresa_ativa_id'], $uid, 'usuario.perfil_alterado', 'usuario', $uid, ['email_alterado' => $trocaEmail, 'senha_alterada' => $trocaSenha]);
api_responder(['ok' => true, 'mensagem' => $trocaSenha ? 'Perfil e senha atualizados.' : 'Perfil atualizado.']);
