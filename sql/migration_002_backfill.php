<?php
/**
 * Backfill da migração 002 — roda uma vez, depois de importar
 * sql/migration_002_funcoes_permissoes.sql, em qualquer ambiente
 * (dev local ou produção) que já tinha empresas/usuários cadastrados
 * antes desta etapa.
 *
 * Para cada empresa: cria as 7 funções padrão (se ainda não existirem)
 * e aponta usuarios.funcao_id a partir do antigo usuarios.cargo. Para
 * qualquer usuário com cargo = 'master_wemart', converte para
 * Superadmin (eh_superadmin = 1, empresa_id/funcao_id = NULL). Se não
 * existir nenhum superadmin depois disso, cria um de demonstração.
 *
 * Uso: php sql/migration_002_backfill.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/funcoes.php';

$pdo = db();

echo "== Migração 002: backfill ==\n";

// 1) Converte usuários com cargo legado 'master_wemart' em Superadmin.
$stmt = $pdo->query("SELECT id, nome, email FROM usuarios WHERE cargo = 'master_wemart'");
$antigosMasterWemart = $stmt->fetchAll();
foreach ($antigosMasterWemart as $u) {
    $pdo->prepare('UPDATE usuarios SET eh_superadmin = 1, empresa_id = NULL, funcao_id = NULL, equipe_id = NULL WHERE id = :id')
        ->execute(['id' => $u['id']]);
    echo "  Convertido para Superadmin: {$u['nome']} <{$u['email']}>\n";
}

// 2) Para cada empresa, semeia as 7 funções padrão e aponta funcao_id
//    de cada usuário a partir do cargo legado.
$empresas = $pdo->query('SELECT id, nome FROM empresas')->fetchAll();
foreach ($empresas as $empresa) {
    $mapaCargoFuncao = funcoes_seed_padrao((int) $empresa['id']);
    echo "  Empresa '{$empresa['nome']}': funções padrão prontas.\n";

    $stmt = $pdo->prepare("SELECT id, cargo FROM usuarios WHERE empresa_id = :empresa_id AND eh_superadmin = 0 AND funcao_id IS NULL");
    $stmt->execute(['empresa_id' => $empresa['id']]);
    $usuarios = $stmt->fetchAll();
    foreach ($usuarios as $u) {
        $funcaoId = $mapaCargoFuncao[$u['cargo']] ?? $mapaCargoFuncao['corretor'];
        $pdo->prepare('UPDATE usuarios SET funcao_id = :funcao_id WHERE id = :id')
            ->execute(['funcao_id' => $funcaoId, 'id' => $u['id']]);
    }
    echo "  " . count($usuarios) . " usuário(s) migrado(s) para função.\n";
}

// 3) Garante ao menos um Superadmin para testar a tela dedicada.
$temSuperadmin = (int) $pdo->query('SELECT COUNT(*) FROM usuarios WHERE eh_superadmin = 1')->fetchColumn();
if (!$temSuperadmin) {
    $email = 'superadmin@wemart.demo';
    $senha = 'demo123';
    $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, eh_superadmin, nome, email, senha_hash, cargo, ativo) VALUES (NULL, NULL, 1, :nome, :email, :hash, :cargo, 1)')
        ->execute([
            'nome' => 'Superadmin WeMart',
            'email' => $email,
            'hash' => password_hash($senha, PASSWORD_DEFAULT),
            'cargo' => 'master_wemart',
        ]);
    echo "  Criado Superadmin de demonstração: {$email} / {$senha}\n";
} else {
    echo "  Já existe Superadmin — nenhum criado.\n";
}

// 4) Confirma que não sobrou nenhum usuário órfão (sem função e sem ser superadmin).
$orfaos = (int) $pdo->query('SELECT COUNT(*) FROM usuarios WHERE eh_superadmin = 0 AND funcao_id IS NULL')->fetchColumn();
echo $orfaos === 0
    ? "== OK: nenhum usuário órfão (todos têm função ou são Superadmin). ==\n"
    : "== ATENÇÃO: {$orfaos} usuário(s) sem função! ==\n";
