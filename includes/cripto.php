<?php
/**
 * Cifragem de segredos guardados no banco (tokens de página do Facebook).
 *
 * Chave: APP_KEY em includes/config.php (32 bytes em base64, não versionada).
 * Usa sodium (XSalsa20-Poly1305) quando disponível; senão AES-256-GCM do
 * OpenSSL. Cada valor recebe um nonce aleatório próprio e é autenticado —
 * qualquer alteração no texto cifrado faz a leitura falhar (retorna null).
 * Formato gravado: "s1:" ou "g1:" + base64(nonce [+ tag] + cifra).
 */

function cripto_chave(): ?string
{
    if (!defined('APP_KEY') || APP_KEY === '') {
        return null;
    }
    $chave = base64_decode((string) APP_KEY, true);
    return ($chave !== false && strlen($chave) === 32) ? $chave : null;
}

function cripto_disponivel(): bool
{
    return cripto_chave() !== null;
}

function cripto_cifrar(string $texto): string
{
    $chave = cripto_chave();
    if ($chave === null) {
        throw new RuntimeException('APP_KEY ausente ou inválida em includes/config.php.');
    }
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($texto, $nonce, $chave));
    }
    $nonce = random_bytes(12);
    $tag = '';
    $cifra = openssl_encrypt($texto, 'aes-256-gcm', $chave, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cifra === false) {
        throw new RuntimeException('Falha ao cifrar.');
    }
    return 'g1:' . base64_encode($nonce . $tag . $cifra);
}

/** Texto original, ou null se a chave faltar ou o valor tiver sido alterado. */
function cripto_decifrar(?string $valor): ?string
{
    $chave = cripto_chave();
    if ($chave === null || !$valor || strlen($valor) < 4) {
        return null;
    }
    $bruto = base64_decode(substr($valor, 3), true);
    if ($bruto === false) {
        return null;
    }
    $prefixo = substr($valor, 0, 3);
    if ($prefixo === 's1:' && function_exists('sodium_crypto_secretbox_open')) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($bruto) <= $n) {
            return null;
        }
        $texto = sodium_crypto_secretbox_open(substr($bruto, $n), substr($bruto, 0, $n), $chave);
        return $texto === false ? null : $texto;
    }
    if ($prefixo === 'g1:') {
        if (strlen($bruto) <= 28) {
            return null;
        }
        $texto = openssl_decrypt(substr($bruto, 28), 'aes-256-gcm', $chave, OPENSSL_RAW_DATA, substr($bruto, 0, 12), substr($bruto, 12, 16));
        return $texto === false ? null : $texto;
    }
    return null;
}
