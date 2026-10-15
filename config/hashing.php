<?php

/*
 * NFR-19 — Argon2id. The hashes already stored were made with bcrypt; `password_verify`
 * reads either, and `rehash_on_login` upgrades each account to Argon2id the next time it
 * signs in, so nobody is locked out and nothing needs a migration.
 */
return [
    'driver' => 'argon2id',

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    'argon' => [
        'memory' => 65536,
        'threads' => 1,
        'time' => 4,
        // Off on purpose: every account was hashed with bcrypt before this driver, and a
        // strict check would refuse them all at the login screen. `password_verify` reads
        // either algorithm; `rehash_on_login` below moves each account to Argon2id as it
        // signs in. Turn it on once no bcrypt hash is left.
        'verify' => false,
    ],

    'rehash_on_login' => true,
];
