<?php
// includes/vault_helper.php
require_once __DIR__ . '/../config.php';

// Ensure encryption secret is defined
if (!defined('VAULT_MASTER_KEY')) {
    define('VAULT_MASTER_KEY', hash('sha256', 'VibesoftAgencyVaultSecretKey2026!#$'));
}

/**
 * Encrypt sensitive text using AES-256-GCM
 */
function encrypt_credential($plaintext) {
    if (empty($plaintext)) return '';
    
    $cipher = "aes-256-gcm";
    $key = substr(VAULT_MASTER_KEY, 0, 32);
    $ivlen = openssl_cipher_iv_length($cipher);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $tag = "";
    
    $ciphertext = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) {
        throw new Exception("Encryption failed");
    }
    
    // Combine IV, tag, and ciphertext encoded in base64
    return base64_encode($iv . '::' . $tag . '::' . $ciphertext);
}

/**
 * Decrypt encrypted payload
 */
function decrypt_credential($encryptedPayload) {
    if (empty($encryptedPayload)) return '';
    
    $decoded = base64_decode($encryptedPayload);
    $parts = explode('::', $decoded, 3);
    if (count($parts) !== 3) {
        return '--- [Invalid Encryption Payload] ---';
    }
    
    list($iv, $tag, $ciphertext) = $parts;
    $cipher = "aes-256-gcm";
    $key = substr(VAULT_MASTER_KEY, 0, 32);
    
    $plaintext = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) {
        return '--- [Decryption Failed] ---';
    }
    
    return $plaintext;
}

/**
 * Generate strong random password
 */
function generate_strong_password($length = 16) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}
