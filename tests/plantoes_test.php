<?php
/**
 * Testes — Plantões de vendas: links de rota (Google Maps / Waze), endereço,
 * validações do cadastro, lista com busca e "Meus plantões", permissões e
 * isolamento entre empresas. Empresas fictícias, apagadas no final.
 *
 *   php tests/plantoes_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/plantoes.php';
require_once __DIR__ . '/../includes/funcoes.php';

function v(string $sql)
{
    return db()->query($sql)->fetchColumn();
}

grupo('Links de rota (padrão oficial, coordenadas codificadas)');
$p = ['latitude' => '-23.5262100', 'longitude' => '-46.6766900', 'endereco' => 'Av. Francisco Matarazzo, 1500', 'cidade' => 'São Paulo', 'estado' => 'SP'];
$l = plantao_links_navegacao($p);
igual($l['google'], 'https://www.google.com/maps/dir/?api=1&destination=-23.5262100%2C-46.6766900&travelmode=driving&dir_action=navigate', 'Google Maps: rota de carro com as coordenadas');
igual($l['waze'], 'https://www.waze.com/ul?ll=-23.5262100%2C-46.6766900&navigate=yes', 'Waze: navegação com as coordenadas');
ok($l['por_coordenada'], 'rota pelas coordenadas quando existem');
ok(filter_var($l['google'], FILTER_VALIDATE_URL) && filter_var($l['waze'], FILTER_VALIDATE_URL), 'URLs válidas');
$l = plantao_links_navegacao(['latitude' => '-22,89367', 'longitude' => '-47,04985']);
ok(str_contains($l['google'], 'destination=-22.8936700%2C-47.0498500'), 'vírgula decimal (padrão brasileiro) vira ponto');
$l = plantao_links_navegacao(['latitude' => null, 'longitude' => null, 'endereco' => 'Rua São João, 10 & 12', 'bairro' => 'Centro', 'cidade' => 'São Paulo', 'estado' => 'SP', 'cep' => '01036-000']);
ok(!$l['por_coordenada'] && str_starts_with($l['google'], 'https://www.google.com/maps/dir/?api=1&destination=Rua%20S%C3%A3o%20Jo%C3%A3o%2C%2010%20%26%2012'), 'sem coordenadas: busca pelo endereço, codificado (acentos, vírgula, &)');
ok(str_starts_with($l['waze'], 'https://www.waze.com/ul?q=Rua%20S%C3%A3o') && str_ends_with($l['waze'], '&navigate=yes'), 'Waze busca pelo endereço codificado');
ok(!str_contains($l['google'], ' ') && !str_contains($l['google'], '&12'), 'nenhum caractere do endereço "quebra" a URL');
igual(plantao_links_navegacao(['latitude' => null, 'longitude' => null, 'endereco' => '', 'cidade' => '']), null, 'sem coordenadas e sem endereço: nenhum link (nada de URL inválida)');
ok(!plantao_links_navegacao(['latitude' => '0', 'longitude' => '0', 'endereco' => 'Av. Paulista, 1000'])['por_coordenada'], 'coordenadas 0,0 são tratadas como ausentes');
ok(!plantao_links_navegacao(['latitude' => '-23.5" onclick="x', 'longitude' => '-46.6', 'endereco' => 'Av. Paulista, 1000'])['por_coordenada'], 'coordenada adulterada no banco não entra na URL');
igual(plantao_coordenada('95', 90), null, 'latitude fora da faixa recusada');
igual(plantao_cep('05001100'), '05001-100', 'CEP formatado');
igual(plantao_cep('123'), false, 'CEP incompleto recusado');
igual(plantao_endereco_completo(['endereco' => 'Av. X, 1', 'complemento' => 'Stand', 'bairro' => 'Lapa', 'cidade' => 'São Paulo', 'estado' => 'SP', 'cep' => '05001-100']),
      'Av. X, 1 — Stand · Lapa · São Paulo/SP · CEP 05001-100', 'endereço completo para a tela');

$emp = criar_empresa_teste('Plantoes');
$emp2 = criar_empresa_teste('Plantoes Outra');
try {
    $pdo = db();
    $f = funcoes_seed_padrao($emp);
    $f2 = funcoes_seed_padrao($emp2);
    $novoUsuario = function (int $e, int $funcao, string $nome, string $escopo, array $perm) use ($pdo) {
        $pdo->prepare("INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, 'x', 'corretor')")
            ->execute(['e' => $e, 'f' => $funcao, 'n' => $nome, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid']);
        return ['id' => (int) $pdo->lastInsertId(), 'nome' => $nome, 'empresa_id' => $e, 'empresa_ativa_id' => $e, 'eh_superadmin' => false, 'escopo' => $escopo, 'permissoes' => $perm];
    };
    $master = $novoUsuario($emp, $f['master_empresa'], 'Master', 'toda_empresa', ['gerenciar_plantoes', 'participar_roleta']);
    $cor = $novoUsuario($emp, $f['corretor'], 'Corretora', 'propria_carteira', ['participar_roleta']);
    $cor2 = $novoUsuario($emp, $f['corretor'], 'Corretor Dois', 'propria_carteira', ['participar_roleta']);
    $outro = $novoUsuario($emp2, $f2['master_empresa'], 'Master Outra', 'toda_empresa', ['gerenciar_plantoes']);
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Residencial Jardim Teste')")->execute(['e' => $emp]);
    $empr = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Empreendimento da Outra')")->execute(['e' => $emp2]);
    $emprOutra = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO roletas (empresa_id, nome, horario_inicio, horario_fim, dias_semana, ativa) VALUES (:e, 'Roleta Lapa', '09:30:00', '18:00:00', '1,2,3,4,5,6', 1)")->execute(['e' => $emp]);
    $rol = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO roletas (empresa_id, nome, ativa) VALUES (:e, 'Roleta da Outra', 1)")->execute(['e' => $emp2]);
    $rolOutra = (int) $pdo->lastInsertId();
    $base = ['nome' => 'Stand Lapa', 'endereco' => 'Rua Guaicurus, 1000', 'cep' => '05033002', 'complemento' => 'Stand de vendas', 'bairro' => 'Lapa',
             'cidade' => 'São Paulo', 'estado' => 'SP', 'latitude' => '-23.5235', 'longitude' => '-46.7001', 'raio_metros' => 300];

    grupo('Cadastro: validações');
    $erro = fn (array $mud) => plantao_salvar($master, null, $mud + $base)['erro'] ?? null;
    ok(str_contains((string) $erro(['nome' => 'x']), 'nome'), 'nome obrigatório');
    ok(str_contains((string) $erro(['estado' => 'XX']), 'Estado'), 'estado inválido recusado');
    ok(str_contains((string) $erro(['cep' => '1234']), 'CEP'), 'CEP inválido recusado');
    ok(str_contains((string) $erro(['latitude' => '-123']), 'Coordenadas'), 'latitude fora da faixa recusada');
    ok(str_contains((string) $erro(['latitude' => 'abc']), 'Coordenadas'), 'coordenada não numérica recusada');
    ok(str_contains((string) $erro(['longitude' => '']), 'juntas'), 'latitude sem longitude recusada');
    ok(str_contains((string) $erro(['latitude' => '0', 'longitude' => '0']), '0,0'), 'coordenadas 0,0 recusadas');
    ok(str_contains((string) $erro(['raio_metros' => 10]), 'raio'), 'raio fora do limite recusado');

    grupo('Cadastro completo (empresa, empreendimentos, roleta, corretores)');
    $r = plantao_salvar($master, null, $base + ['empreendimentos' => [$empr, $emprOutra], 'roletas' => [$rol, $rolOutra], 'usuarios' => [$cor['id'], $outro['id']],
                                                'observacoes' => 'Estacionamento pela rua lateral']);
    ok($r['ok'], 'plantão criado');
    $pid = $r['id'];
    $pl = $pdo->query("SELECT * FROM plantoes WHERE id = $pid")->fetch();
    ok((int) $pl['empresa_id'] === $emp && $pl['cep'] === '05033-002' && $pl['latitude'] === '-23.5235000' && (int) $pl['ativo'] === 1, 'gravado na empresa certa, com CEP e coordenadas normalizados');
    igual((int) v("SELECT COUNT(*) FROM plantao_empreendimentos WHERE plantao_id = $pid"), 1, 'empreendimento de outra empresa ignorado');
    ok((int) v("SELECT plantao_id FROM roletas WHERE id = $rol") === $pid && v("SELECT plantao_id FROM roletas WHERE id = $rolOutra") === null, 'roleta da empresa vinculada; roleta de outra empresa intocada');
    igual(plantao_usuarios($pid), [$cor['id']], 'corretor de outra empresa não é autorizado');
    ok(!plantao_salvar($outro, $pid, $base)['ok'], 'outra empresa não edita este plantão');
    $r2 = plantao_salvar($master, $pid, $base + ['nome' => 'Stand Lapa (reformado)', 'roletas' => [], 'ativo' => 1]);
    ok($r2['ok'] && v("SELECT plantao_id FROM roletas WHERE id = $rol") === null, 'desmarcar a roleta tira o vínculo dela');
    plantao_salvar($master, $pid, $base + ['roletas' => [$rol], 'empreendimentos' => [$empr], 'usuarios' => [$cor['id']], 'ativo' => 1]);

    grupo('Lista, busca e "Meus plantões"');
    $pdo->prepare("INSERT INTO plantoes (empresa_id, nome, endereco, bairro, cidade, estado, ativo) VALUES (:e, 'Stand Antigo', 'Rua Velha, 1', 'Centro', 'Campinas', 'SP', 0)")->execute(['e' => $emp]);
    $inativo = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO plantoes (empresa_id, nome, endereco, cidade, ativo) VALUES (:e, 'Stand da Outra Empresa', 'Rua Secreta, 9', 'São Paulo', 1)")->execute(['e' => $emp2]);
    $daOutra = (int) $pdo->lastInsertId();
    $nomes = fn (array $l) => array_column($l, 'nome');
    $lc = plantoes_listar($cor);
    ok(in_array('Stand Lapa', $nomes($lc), true) && !in_array('Stand Antigo', $nomes($lc), true), 'corretor vê os ativos, não os inativos');
    ok(in_array('Stand Antigo', $nomes(plantoes_listar($master)), true), 'quem gerencia vê também os inativos');
    ok(!in_array('Stand da Outra Empresa', $nomes(plantoes_listar($master)), true), 'plantão de outra empresa nunca aparece');
    igual($nomes(plantoes_listar($cor, 'Jardim')), ['Stand Lapa'], 'busca pelo nome do empreendimento');
    igual($nomes(plantoes_listar($cor, 'lapa')), ['Stand Lapa'], 'busca pelo bairro (sem diferenciar maiúsculas)');
    igual(count(plantoes_listar($cor, '%')), 0, 'curinga "%" é tratado como texto (não lista tudo)');
    igual($nomes(plantoes_listar($cor, '', 'meus')), ['Stand Lapa'], '"Meus plantões": autorizado no plantão');
    igual(plantoes_listar($cor2, '', 'meus'), [], 'quem não está no plantão não o vê em "Meus plantões"');
    $pdo->prepare('INSERT INTO roleta_corretores (roleta_id, usuario_id) VALUES (:r, :u)')->execute(['r' => $rol, 'u' => $cor2['id']]);
    igual($nomes(plantoes_listar($cor2, '', 'meus')), ['Stand Lapa'], '"Meus plantões": também quem está na roleta do plantão');
    $lp = plantoes_listar($cor)[0];
    ok(str_contains((string) $lp['horario'], '09:30') && $lp['empreendimentos'][0]['nome'] === 'Residencial Jardim Teste', 'card traz o horário da roleta e os empreendimentos');

    grupo('Detalhes e permissões');
    ok(plantao_carregar($cor, $pid) !== null, 'corretor abre o plantão ativo');
    igual(plantao_carregar($cor, $inativo), null, 'corretor não abre plantão inativo');
    ok(plantao_carregar($master, $inativo) !== null, 'gestor abre o inativo');
    igual(plantao_carregar($master, $daOutra), null, 'id de outra empresa → não encontrado');
    igual(plantao_carregar(['eh_superadmin' => true] + $master, $pid), null, 'superadmin não entra nos dados operacionais da empresa');
    ok(plantao_pode_gerenciar($master) && !plantao_pode_gerenciar($cor), 'só quem tem "gerenciar plantões" cadastra');
    ok(plantao_usuario_vinculado($pid, $cor['id']) && plantao_usuario_vinculado($pid, $cor2['id']), 'vínculo pelo cadastro e pela roleta');
    $pdo->exec("UPDATE roletas SET ativa = 0 WHERE id = $rol");
    $pdo->exec("UPDATE plantoes SET horario_descricao = 'Sáb e dom, 10h às 17h' WHERE id = $pid");
    igual(plantoes_listar($cor, 'Lapa')[0]['horario'], 'Sáb e dom, 10h às 17h', 'sem roleta ativa: usa o horário do cadastro');
    plantao_salvar($master, $pid, $base + ['ativo' => 0, 'roletas' => [$rol]]);
    ok(plantao_carregar($cor, $pid) === null && !in_array('Stand Lapa', $nomes(plantoes_listar($cor)), true), 'desativado some da tela do corretor');
} finally {
    apagar_empresa_teste($emp);
    apagar_empresa_teste($emp2);
    grupo('Limpeza');
    igual((int) db()->query("SELECT COUNT(*) FROM empresas WHERE nome LIKE 'Teste automatizado%'")->fetchColumn(), 0, 'nenhum dado de teste ficou no banco');
}
resumo_e_sair();
