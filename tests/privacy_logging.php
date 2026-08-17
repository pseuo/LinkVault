<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

if (privacy_safe_ip('203.0.113.42') !== '203.0.113.0/24') {
    throw new RuntimeException('IPv4 authentication log redaction is incorrect.');
}
if (privacy_safe_ip('2001:db8:1234:5678::42') !== '2001:db8:1234:5678::/64') {
    throw new RuntimeException('IPv6 authentication log redaction is incorrect.');
}
if (privacy_safe_ip('not-an-ip') !== 'unknown') {
    throw new RuntimeException('Invalid authentication log IP should be unknown.');
}

$controller = (string)file_get_contents($root . '/public/controllers/AuthenticationController.php');
if (str_contains($controller, "'user_agent'")) {
    throw new RuntimeException('Authentication risk logs must not retain User-Agent.');
}

fwrite(STDOUT, "Privacy logging tests passed.\n");
