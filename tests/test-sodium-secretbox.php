<?php

require_once dirname(__DIR__) . '/includes/class-oauth-handler.php';

$key   = robotwealth_derive_key();
$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$ciph  = sodium_crypto_secretbox('test', $nonce, $key);
assert(sodium_crypto_secretbox_open($ciph, $nonce, $key) === 'test');

echo "Sodium secretbox test passed.\n";
