<?php
/**
 * Cria ou atualiza o acesso principal (Superadmin WeMart ou Master de uma
 * empresa) direto no banco — roda SOMENTE pelo Terminal do servidor:
 *
 *   php -q ferramentas/definir_acesso.php
 *
 * A senha é digitada na hora (sem aparecer na tela) e só o hash vai para
 * o banco: nenhuma senha fica no código, no GitHub ou no histórico do
 * terminal. Também oferece desativar as contas de demonstração (*.demo),
 * cuja senha é pública.
 */
require_once __DIR__ . '/../includes/terminal.php';
terminal_exigir();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/auditoria.php';

function perguntar(string $texto, string $padrao = ''): string
{
    echo $texto . ($padrao !== '' ? " [$padrao]" : '') . ': ';
    $linha = fgets(STDIN);
    $resposta = trim($linha === false ? '' : $linha);
    return $resposta === '' ? $padrao : $resposta;
}

function perguntar_senha(string $texto): string
{
    echo $texto . ': ';
    $temTty = function_exists('posix_isatty') ? posix_isatty(STDIN) : true;
    if ($temTty) {
        @shell_exec('stty -echo 2>/dev/null');
    }
    $linha = fgets(STDIN);
    if ($temTty) {
        @shell_exec('stty echo 2>/dev/null');
    }
    echo PHP_EOL;
    return rtrim($linha === false ? '' : $linha, "\r\n");
}

function sair(string $msg, int $codigo = 1): void
{
    fwrite($codigo ? STDERR : STDOUT, $msg . PHP_EOL);
    exit($codigo);
}

$pdo = db();
echo PHP_EOL . "WeMart Sales — definir acesso principal" . PHP_EOL . str_repeat('-', 40) . PHP_EOL;

$email = mb_strtolower(perguntar('E-mail de login'));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sair('E-mail inválido.');
}

$stmt = $pdo->prepare('SELECT id, nome, empresa_id, eh_superadmin FROM usuarios WHERE LOWER(email) = :email');
$stmt->execute(['email' => $email]);
$existente = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
echo $existente ? "Usuário já existe ({$existente['nome']}): vou atualizar." . PHP_EOL : 'Usuário novo: vou criar.' . PHP_EOL;

$nome = perguntar('Nome', $existente['nome'] ?? 'WeMart');

echo PHP_EOL . 'Tipo de acesso:' . PHP_EOL
   . '  1) Superadmin WeMart — dono da plataforma: cria empresas e Masters (não vê leads)' . PHP_EOL
   . '  2) Master de uma empresa — vê e administra tudo dentro da empresa' . PHP_EOL;
$tipo = perguntar('Escolha 1 ou 2', '1');
if (!in_array($tipo, ['1', '2'], true)) {
    sair('Opção inválida.');
}

$empresaId = null;
$funcaoId = null;
if ($tipo === '2') {
    $empresas = $pdo->query('SELECT id, nome FROM empresas ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if (!$empresas) {
        sair('Nenhuma empresa cadastrada. Crie como Superadmin (opção 1) e cadastre a empresa pela tela.');
    }
    echo PHP_EOL . 'Empresas:' . PHP_EOL;
    foreach ($empresas as $e) {
        echo "  {$e['id']}) {$e['nome']}" . PHP_EOL;
    }
    $empresaId = (int) perguntar('Número da empresa', (string) $empresas[0]['id']);
    if (!in_array($empresaId, array_map('intval', array_column($empresas, 'id')), true)) {
        sair('Empresa inválida.');
    }
    $funcaoId = funcoes_seed_padrao($empresaId)['master_empresa'];
}

$senha = perguntar_senha(PHP_EOL . 'Senha (não aparece enquanto digita)');
if (mb_strlen($senha) < 8) {
    sair('A senha precisa ter pelo menos 8 caracteres.');
}
if (perguntar_senha('Repita a senha') !== $senha) {
    sair('As senhas não conferem. Nada foi alterado.');
}
$hash = password_hash($senha, PASSWORD_DEFAULT);
unset($senha);

$dados = [
    'nome' => $nome,
    'email' => $email,
    'hash' => $hash,
    'empresa_id' => $empresaId,
    'funcao_id' => $funcaoId,
    'super' => $tipo === '1' ? 1 : 0,
    'cargo' => $tipo === '1' ? 'master_wemart' : 'master_empresa',
];

$pdo->beginTransaction();
if ($existente) {
    $pdo->prepare('UPDATE usuarios SET nome = :nome, email = :email, senha_hash = :hash, empresa_id = :empresa_id, funcao_id = :funcao_id,
                   equipe_id = NULL, eh_superadmin = :super, cargo = :cargo, ativo = 1 WHERE id = :id')
        ->execute($dados + ['id' => $existente['id']]);
    $usuarioId = (int) $existente['id'];
} else {
    $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, eh_superadmin, nome, email, senha_hash, cargo, ativo)
                   VALUES (:empresa_id, :funcao_id, :super, :nome, :email, :hash, :cargo, 1)')
        ->execute($dados);
    $usuarioId = (int) $pdo->lastInsertId();
}
// Libera o login caso o e-mail tenha sido bloqueado por tentativas erradas.
try {
    $pdo->prepare('DELETE FROM login_tentativas WHERE LOWER(email) = :email')->execute(['email' => $email]);
} catch (PDOException $e) {
    // tabela de tentativas ausente em instalações antigas: nada a liberar
}
$pdo->commit();

auditoria_registrar($empresaId, $usuarioId, 'usuario.acesso_definido_terminal', 'usuario', $usuarioId, ['tipo' => $dados['cargo']]);

echo PHP_EOL . '✔ Pronto: ' . $email . ' agora é ' . ($tipo === '1' ? 'Superadmin WeMart' : 'Master da empresa') . '.' . PHP_EOL;

$demos = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE email LIKE '%.demo' AND ativo = 1")->fetchColumn();
if ($demos > 0) {
    echo PHP_EOL . "Há $demos conta(s) de demonstração ativas (e-mails terminados em .demo, senha pública demo123)." . PHP_EOL;
    if (strtolower(perguntar('Desativar todas agora? (s/n)', 's')) === 's') {
        $n = $pdo->exec("UPDATE usuarios SET ativo = 0 WHERE email LIKE '%.demo'");
        echo "✔ $n conta(s) de demonstração desativada(s)." . PHP_EOL;
    }
}
echo PHP_EOL;
