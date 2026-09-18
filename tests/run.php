<?php

declare(strict_types=1);

use CleanLink\AppError;
use CleanLink\Auth;
use CleanLink\NetSafety;
use CleanLink\Resolver;
use CleanLink\Sanitizer;

require_once __DIR__ . '/../app/AppError.php';
require_once __DIR__ . '/../app/Auth.php';
require_once __DIR__ . '/../app/NetSafety.php';
require_once __DIR__ . '/../app/Resolver.php';
require_once __DIR__ . '/../app/Sanitizer.php';

$assertions = 0;

function expectSame($expected, $actual, string $label): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf("FAIL: %s\nExpected: %s\nActual:   %s\n", $label, var_export($expected, true), var_export($actual, true)));
        exit(1);
    }
}

function expectThrows(callable $callback, string $code, string $label): void
{
    global $assertions;
    $assertions++;
    try {
        $callback();
    } catch (AppError $error) {
        if ($error->errorCode === $code) return;
        fwrite(STDERR, "FAIL: {$label} threw {$error->errorCode}\n");
        exit(1);
    }
    fwrite(STDERR, "FAIL: {$label} did not throw\n");
    exit(1);
}

$sanitizer = new Sanitizer();
expectSame(
    'https://www.amazon.com/dp/B0ABCDEFGH',
    $sanitizer->sanitize('https://www.amazon.com/Some-Product/dp/B0ABCDEFGH/ref=sr_1_1?tag=test-20&keywords=xxx'),
    'Amazon product URLs are canonicalized'
);
expectSame(
    'https://shop.example/item?variant=blue&size=m#details',
    $sanitizer->sanitize('https://shop.example/item?utm_source=news&variant=blue&fbclid=abc&size=m#details'),
    'Functional parameters and fragments are preserved'
);
expectSame(
    'https://www.amazon.co.uk/dp/B012345678',
    $sanitizer->sanitize('https://smile.amazon.co.uk/gp/product/B012345678?creative=123&tag=affiliate'),
    'Regional Amazon host is preserved and normalized'
);

$auth = new Auth('correct horse battery staple', 'test-secret');
expectSame(true, $auth->passwordMatches('correct horse battery staple'), 'Password matches');
expectSame(false, $auth->passwordMatches('wrong'), 'Wrong password does not match');
$token = $auth->createToken(1000);
expectSame(true, $auth->tokenIsValid($token, 1001), 'Fresh session token is valid');
expectSame(false, $auth->tokenIsValid($token, 1000 + 604801), 'Expired session token is invalid');

$net = new NetSafety();
expectSame(true, $net->isPublicIp('8.8.8.8'), 'Public IPv4 is accepted');
expectSame(false, $net->isPublicIp('127.0.0.1'), 'Loopback IPv4 is blocked');
expectSame(false, $net->isPublicIp('169.254.169.254'), 'Metadata IPv4 is blocked');
expectSame(false, $net->isPublicIp('10.0.0.1'), 'Private IPv4 is blocked');
expectSame(false, $net->isPublicIp('::1'), 'Loopback IPv6 is blocked');
expectSame(false, $net->isPublicIp('fc00::1'), 'Private IPv6 is blocked');
expectThrows(function () use ($net) { $net->validateUrl('file:///etc/passwd'); }, 'INVALID_URL', 'Non-HTTP schemes are blocked');
expectThrows(function () use ($net) { $net->validateUrl('http://localhost/admin'); }, 'UNSAFE_ADDRESS', 'localhost is blocked');
expectThrows(function () use ($net) { $net->validateUrl('http://user:pass@example.com'); }, 'UNSAFE_ADDRESS', 'URL credentials are blocked');
expectSame(
    'https://example.com/path',
    $net->validateUrl('https://EXAMPLE.com./path')['url'],
    'Hostnames are normalized before pinned requests'
);

$resolver = new Resolver($net);
expectSame(
    'https://www.amazon.com/dp/B0HF1CV5X4',
    $resolver->embeddedDestination('https://redirect.fatcoupon.com/go?referrer=abc&url=https%3A%2F%2Fwww.amazon.com%2Fdp%2FB0HF1CV5X4'),
    'FatCoupon destination URLs are extracted'
);
expectSame(
    'https://clcktrck.com/US/s/red_u_plain.php?t=direct&s=22243&d=https%3A%2F%2Fwww.amazon.com%2Fdp%2FB0HF1CV5X4',
    $resolver->embeddedDestination('https://bigoffers.us/redirect.html?store_url=https%3A%2F%2Fclcktrck.com%2FUS%2Fs%2Fred_u_plain.php%3Ft%3Ddirect%26s%3D22243%26d%3Dhttps%253A%252F%252Fwww.amazon.com%252Fdp%252FB0HF1CV5X4'),
    'BigOffers store URLs are extracted without losing their nested query'
);
expectSame(
    'https://www.amazon.com/dp/B0HF1CV5X4',
    $resolver->embeddedDestination('https://clcktrck.com/US/s/red_u_plain.php?t=direct&d=https%253A%252F%252Fwww.amazon.com%252Fdp%252FB0HF1CV5X4'),
    'Doubly encoded click-tracker destinations are extracted'
);
expectSame(
    null,
    $resolver->embeddedDestination('https://example.com/?url=https%3A%2F%2Fwww.amazon.com%2Fdp%2FB0HF1CV5X4'),
    'Destination parameters on unknown hosts are not trusted'
);

echo "All {$assertions} assertions passed.\n";
