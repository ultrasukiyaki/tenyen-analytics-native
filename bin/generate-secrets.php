#!/usr/bin/env php
<?php

declare(strict_types=1);

function secret(): string
{
    return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
}

$adminUsername = 'admin';
$adminPassword = bin2hex(random_bytes(10));

echo "site_token=" . secret() . PHP_EOL;
echo "encryption_secret=" . secret() . PHP_EOL;
echo "hash_secret=" . secret() . PHP_EOL;
echo "admin_username=" . $adminUsername . PHP_EOL;
echo "admin_password=" . $adminPassword . PHP_EOL;
echo "admin_password_hash=" . password_hash($adminPassword, PASSWORD_DEFAULT) . PHP_EOL;
echo PHP_EOL;
echo "IMPORTANT: Save admin_password now. It cannot be restored from admin_password_hash." . PHP_EOL;
