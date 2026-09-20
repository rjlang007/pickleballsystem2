<?php
function totpBase32Decode(string $value): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value));
    $bits = '';
    foreach (str_split($value) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $output .= chr(bindec($byte));
    return $output;
}

function totpGenerateSecret(int $bytes = 20): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $raw = random_bytes($bytes);
    $bits = '';
    foreach (str_split($raw) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    $secret = '';
    foreach (str_split($bits, 5) as $chunk) $secret .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $secret;
}

function totpCode(string $secret, ?int $timestamp = null): string {
    $counter = intdiv($timestamp ?? time(), 30);
    $binary = pack('N2', 0, $counter);
    $hash = hash_hmac('sha1', $binary, totpBase32Decode($secret), true);
    $offset = ord($hash[19]) & 15;
    $value = ((ord($hash[$offset]) & 127) << 24) | ((ord($hash[$offset + 1]) & 255) << 16) | ((ord($hash[$offset + 2]) & 255) << 8) | (ord($hash[$offset + 3]) & 255);
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function totpVerify(string $secret, string $code): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    foreach ([-1, 0, 1] as $step) if (hash_equals(totpCode($secret, time() + ($step * 30)), $code)) return true;
    return false;
}
