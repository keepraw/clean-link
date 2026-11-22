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
        if ($error->errorCode === $code) {
            return;
        }
        fwrite(STDERR, "FAIL: {$label} threw {$error->errorCode}\n");
        exit(1);
    }
    fwrite(STDERR, "FAIL: {$label} did not throw\n");
    exit(1);
}

/** @return array{status: int, location: ?string, contentType: string, body: string, bodyComplete: bool} */
function response(int $status = 200, ?string $location = null, string $contentType = '', string $body = '', bool $complete = true): array
{
    return [
        'status' => $status,
        'location' => $location,
        'contentType' => $contentType,
        'body' => $body,
        'bodyComplete' => $complete,
    ];
}

/** @param array<string, mixed> $responses */
function fakeResolver(NetSafety $net, array $responses, &$requests = null): Resolver
{
    $requests = [];
    return new Resolver($net, function (array $target, int $timeoutMs) use ($responses, &$requests): array {
        $requests[] = ['url' => $target['url'], 'timeout' => $timeoutMs];
        if (!array_key_exists($target['url'], $responses)) {
            throw new AppError('RESOLUTION_FAILED', 'No fake response configured.');
        }
        $result = $responses[$target['url']];
        if ($result instanceof AppError) {
            throw new AppError($result->errorCode, $result->getMessage(), $result->httpStatus);
        }
        return $result;
    });
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
$trackingParameters = [
    'irpid', 'sharedid', 'awc', 'sscid', 'cjevent', 'cjdata', 'ranmid', 'raneaid',
    'ransiteid', 'wmlspartner', 'veh', 'sourceid', 'affiliates_ad_id', 'campaign_id',
    'afid', 'clkid', 'cpng', 'lnm', 'gbraid', 'wbraid', 'ttclid', 'yclid', 'li_fat_id',
    'epik', 'rdt_cid', '_branch_match_id', 's_kwcid', 'ef_id', 'affid', 'aff_sub',
    'subid', 'sub_id', 'click_id',
];
$trackingQuery = [];
foreach ($trackingParameters as $index => $name) {
    $trackingQuery[] = strtoupper($name) . '=' . $index;
}
expectSame(
    'https://shop.example/item?variant=blue',
    $sanitizer->sanitize('https://shop.example/item?' . implode('&', $trackingQuery) . '&variant=blue'),
    'Generic affiliate parameters are stripped case-insensitively'
);
expectSame(
    'https://shop.example/item?variant=blue',
    $sanitizer->sanitize('https://shop.example/item?mtm_campaign=spring&pk_source=mail&variant=blue'),
    'Additional tracking prefixes are stripped'
);
foreach ([
    ['Walmart', 'wmlspartner=abc&veh=1&sourceid=paid'],
    ['Target', 'afid=abc&clkid=1&lnm=offer'],
    ['BestBuy', 'awc=abc&sscid=1&cpng=spring'],
    ['Rakuten', 'ranmid=abc&raneaid=1&ransiteid=2&sharedid=3'],
    ['Awin', 'irpid=abc&aff_sub=1&subid=2&click_id=3'],
] as $affiliateExample) {
    expectSame(
        'https://shop.example/item?variant=blue',
        $sanitizer->sanitize('https://shop.example/item?' . $affiliateExample[1] . '&variant=blue'),
        $affiliateExample[0] . ' tracking parameters are removed'
    );
}

$passwordHash = password_hash('correct horse battery staple', PASSWORD_DEFAULT);
$auth = new Auth($passwordHash, 'test-secret', 86400);
expectSame(true, $auth->passwordMatches('correct horse battery staple'), 'Password matches');
expectSame(false, $auth->passwordMatches('wrong'), 'Wrong password does not match');
$token = $auth->createToken(1000);
expectSame(true, $auth->tokenIsValid($token, 1001), 'Fresh session token is valid');
expectSame(false, $auth->tokenIsValid($token, 1000 + 86401), 'Expired session token is invalid');

$net = new NetSafety();
expectSame(true, $net->isPublicIp('8.8.8.8'), 'Public IPv4 is accepted');
expectSame(true, $net->isPublicIp('2606:4700:4700::1111'), 'Public IPv6 is accepted');
expectSame(true, $net->isPublicIp('::ffff:8.8.8.8'), 'Public IPv4-mapped IPv6 is accepted');
foreach ([
    '127.0.0.1', '169.254.169.254', '10.0.0.1',
    '100.64.0.1', '192.0.0.1', '198.18.0.1', '224.0.0.1',
    '::1', 'fc00::1', '64:ff9b::7f00:1', '2002::1', 'ff02::1',
    '::ffff:127.0.0.1', '::ffff:100.64.0.1',
] as $blockedAddress) {
    expectSame(false, $net->isPublicIp($blockedAddress), $blockedAddress . ' is blocked');
}
expectThrows(function () use ($net) { $net->validateUrl('file:///etc/passwd'); }, 'INVALID_URL', 'Non-HTTP schemes are blocked');
expectThrows(function () use ($net) { $net->validateUrl('http://localhost/admin'); }, 'UNSAFE_ADDRESS', 'localhost is blocked');
expectThrows(function () use ($net) { $net->validateUrl('http://127.0.0.1/admin'); }, 'UNSAFE_ADDRESS', 'Private literal IPs are blocked during URL validation');
expectThrows(function () use ($net) { $net->validateUrl('http://user:pass@example.com'); }, 'UNSAFE_ADDRESS', 'URL credentials are blocked');
expectSame(
    'https://example.com/path',
    $net->validateUrl('https://EXAMPLE.com./path')['url'],
    'Hostnames are normalized before pinned requests'
);
expectSame(
    'https://example.com/Pok%C3%A9mon',
    $net->validateUrl('https://example.com/Pokémon')['url'],
    'Unicode URL paths are normalized to percent encoding'
);

$resolver = new Resolver($net, function (array $target, int $timeoutMs): array {
    throw new AppError('RESOLUTION_FAILED', 'Unexpected fake HTTP request.');
});
$merchant = 'https://www.example-store.com/product?id=7';
foreach ([
    ['https://awin.example/click?ued=' . rawurlencode($merchant), 'Awin ued destination'],
    ['https://rakuten.example/track?murl=' . rawurlencode($merchant), 'Rakuten murl destination'],
    ['https://skimlinks.example/click?url=' . rawurlencode($merchant), 'Skimlinks url destination'],
    ['https://walmart.example/goto?u=' . rawurlencode($merchant), 'Walmart u destination'],
    ['https://prf.hn/click/camref:1/destination:' . rawurlencode($merchant), 'Partnerize path destination'],
] as $test) {
    expectSame($merchant, $resolver->embeddedDestination($test[0]), $test[1]);
}
expectSame(
    null,
    $resolver->embeddedDestination('https://example.com/login?next=https%3A%2F%2Fexample.com%2Fx'),
    'Same-host query destinations are not unwrapped'
);
expectSame(
    'https://www.example-store.com/product?id=7',
    $resolver->embeddedDestination('https://unknown.example/redirect?anything=' . rawurlencode($merchant)),
    'Unknown hosts with cross-site destination parameters are unwrapped'
);
expectSame(
    'https://preferred.example/item',
    $resolver->embeddedDestination('https://tracker.example/?q=' . rawurlencode('https://first.example/')
        . '&url=' . rawurlencode('https://preferred.example/item')),
    'Preferred destination keys win over earlier lower-priority candidates'
);
expectSame(
    'https://clcktrck.com/US/s/red_u_plain.php?t=direct&s=22243&d=https%3A%2F%2Fwww.amazon.com%2Fdp%2FB0HF1CV5X4',
    $resolver->embeddedDestination('https://bigoffers.us/redirect.html?store_url=https%3A%2F%2Fclcktrck.com%2FUS%2Fs%2Fred_u_plain.php%3Ft%3Ddirect%26s%3D22243%26d%3Dhttps%253A%252F%252Fwww.amazon.com%252Fdp%252FB0HF1CV5X4'),
    'BigOffers store URLs preserve nested encoded query strings'
);
expectSame(
    'https://www.amazon.com/dp/B0HF1CV5X4',
    $resolver->embeddedDestination('https://clcktrck.com/US/s/red_u_plain.php?t=direct&d=https%253A%252F%252Fwww.amazon.com%252Fdp%252FB0HF1CV5X4'),
    'Doubly encoded click-tracker destinations are extracted'
);

$samsClubUrl = 'https://www.samsclub.com/ip/Sony-PlayStation-5-Console-Slim-Digital-Marvel-s-Wolverine-Battle-Yellow-Limited-Edition-Bundle-825GB/20856500647?classType=REGULAR&from=/search';
expectSame(
    $samsClubUrl,
    $resolver->embeddedDestination('https://www.samsclub.com/are-you-human?url=' . rawurlencode(base64_encode(parse_url($samsClubUrl, PHP_URL_PATH) . '?' . parse_url($samsClubUrl, PHP_URL_QUERY))) . '&uuid=test'),
    'Sam\'s Club bot-check URLs restore the original same-origin path'
);

$fatCoupon = 'https://redirect.fatcoupon.com/go?referrer=abc&url=' . rawurlencode($merchant);
$fatCouponResolver = fakeResolver($net, [$merchant => response()], $fatCouponRequests);
$fatCouponResult = $fatCouponResolver->resolveWithHttp($fatCoupon);
expectSame($merchant, $fatCouponResult['finalUrl'], 'Static unwrap returns a merchant destination');
expectSame(null, $fatCouponResult['warning'], 'Static unwrap finishes without a warning');
expectSame([$merchant], array_column($fatCouponRequests, 'url'), 'Static redirector is skipped while merchant is fetched');

$awin = 'https://awin.example/click?ued=' . rawurlencode($merchant);
$chainResolver = fakeResolver($net, [$merchant => response()], $chainRequests);
$chainResult = $chainResolver->resolveWithHttp('https://redirect.fatcoupon.com/go?url=' . rawurlencode($awin));
expectSame($merchant, $chainResult['finalUrl'], 'FatCoupon to Awin to merchant chain resolves');
expectSame([$merchant], array_column($chainRequests, 'url'), 'Static redirectors are never fetched');

$amazonUrl = 'https://www.amazon.com/dp/B0HF1CV5X4';
$bigOffers = 'https://bigoffers.us/redirect.html?store_url=' . rawurlencode(
    'https://clcktrck.com/US/s/red_u_plain.php?t=direct&s=22243&d=' . rawurlencode($amazonUrl)
);
$bigOffersResult = fakeResolver($net, [$amazonUrl => response()])->resolveWithHttp($bigOffers);
expectSame($amazonUrl, $bigOffersResult['finalUrl'], 'BigOffers to clcktrck to doubly encoded Amazon destination resolves');
$fatCouponBigOffers = 'https://redirect.fatcoupon.com/go?url=' . rawurlencode($bigOffers);
$fatCouponBigOffersResult = fakeResolver($net, [$amazonUrl => response()])->resolveWithHttp($fatCouponBigOffers);
expectSame($amazonUrl, $fatCouponBigOffersResult['finalUrl'], 'FatCoupon to BigOffers to clcktrck to Amazon resolves');
expectSame(
    'https://www.amazon.com/dp/B0H78BB9TY',
    $sanitizer->sanitize(fakeResolver($net, [
        'https://www.amazon.com/Pok%C3%A9mon-TCG/dp/B0H78BB9TY?tag=affiliate' => response(),
    ])->resolveWithHttp('https://redirect.fatcoupon.com/go?url=' . rawurlencode('https://www.amazon.com/Pok%C3%A9mon-TCG/dp/B0H78BB9TY?tag=affiliate'))['finalUrl']),
    'Unwrapped Unicode Amazon product URLs canonicalize'
);

$tracker = 'https://tracker.example/redirect';
$merchantRoot = 'https://shop.example/product';
$redirectResolver = fakeResolver($net, [
    $tracker => response(302, $merchantRoot),
    $merchantRoot => response(302, '/blocked'),
]);
$redirectResult = $redirectResolver->resolveWithHttp($tracker);
expectSame($merchantRoot, $redirectResult['finalUrl'], 'Same-site post-tracker redirect stops at merchant');
expectSame(null, $redirectResult['warning'], 'Same-site bot-check stop is not a warning');

$httpUrl = 'http://example.com/start';
$httpsUrl = 'https://example.com/start';
$schemeResolver = fakeResolver($net, [
    $httpUrl => response(301, $httpsUrl),
    $httpsUrl => response(),
], $schemeRequests);
$schemeResult = $schemeResolver->resolveWithHttp($httpUrl);
expectSame($httpsUrl, $schemeResult['finalUrl'], 'Same-host HTTP to HTTPS redirect is followed on the input host');
expectSame(2, count($schemeRequests), 'Both same-host scheme requests are made');

$metaBase = 'https://tracker.example/interstitial';
$metaTarget = 'https://merchant.example/product?one=1&two=2';
$metaBodies = [
    '<script>location="https://ignored.example/path";</script><meta http-equiv="refresh" content="0; url=https://merchant.example/product?one=1&amp;two=2">',
    '<meta content="0; URL=https://merchant.example/product?one=1&amp;two=2" charset="utf-8" HTTP-EQUIV="Refresh">',
];
foreach ($metaBodies as $index => $metaBody) {
    $metaResolver = fakeResolver($net, [
        $metaBase => response(200, null, 'text/html; charset=utf-8', $metaBody),
        $metaTarget => response(),
    ]);
    $metaResult = $metaResolver->resolveWithHttp($metaBase);
    expectSame($metaTarget, $metaResult['finalUrl'], 'Meta refresh attribute order and HTML entities are handled (' . $index . ')');
}

$jsBase = 'https://tracker.example/js';
$jsTarget = 'https://merchant.example/item?x=1&y=2';
$jsBody = '<script>window.location.href = "https:\\/\\/merchant.example\\/item?x=1\\u0026y=2";</script>';
$jsResolver = fakeResolver($net, [
    $jsBase => response(200, null, 'text/html', $jsBody),
    $jsTarget => response(),
]);
expectSame($jsTarget, $jsResolver->resolveWithHttp($jsBase)['finalUrl'], 'Small-page JavaScript redirects are followed');

$onclickTarget = 'https://merchant.example/onclick';
$onclickRequests = [];
$onclickResolver = fakeResolver($net, [
    $jsBase => response(200, null, 'text/html', '<a onclick="location.href=\'https://merchant.example/onclick\'">open</a>'),
    $onclickTarget => response(),
], $onclickRequests);
$onclickResult = $onclickResolver->resolveWithHttp($jsBase);
expectSame($jsBase, $onclickResult['finalUrl'], 'Inline onclick handlers are not treated as JavaScript redirects');
expectSame([$jsBase], array_column($onclickRequests, 'url'), 'Inline onclick handlers do not trigger another fetch');

$largeBody = str_repeat('x', 16385) . '<script>location="https://merchant.example/should-not-follow";</script>';
$largeResolver = fakeResolver($net, [
    $jsBase => response(200, null, 'text/html', $largeBody),
]);
expectSame($jsBase, $largeResolver->resolveWithHttp($jsBase)['finalUrl'], 'Large JavaScript pages are ignored');
$incompleteResolver = fakeResolver($net, [
    $jsBase => response(200, null, 'text/html', '<script>location="https://merchant.example/should-not-follow";</script>', false),
]);
expectSame($jsBase, $incompleteResolver->resolveWithHttp($jsBase)['finalUrl'], 'Incomplete JavaScript pages are ignored');

$replaceBase = 'https://tracker.example/replace';
$replaceTarget = 'https://merchant.example/replaced';
$replaceResolver = fakeResolver($net, [
    $replaceBase => response(200, null, 'text/html', '<script>location.replace("https://merchant.example/replaced");</script>'),
    $replaceTarget => response(),
]);
expectSame($replaceTarget, $replaceResolver->resolveWithHttp($replaceBase)['finalUrl'], 'JavaScript location.replace redirects are followed');

$timeoutMerchant = 'https://merchant.example/product';
$timeoutTracker = 'https://tracker.example/timeout?url=' . rawurlencode($timeoutMerchant);
$timeoutResolver = fakeResolver($net, [
    $timeoutMerchant => new AppError('REQUEST_TIMEOUT', 'Request timed out.', 504),
], $timeoutRequests);
$timeoutResult = $timeoutResolver->resolveWithHttp($timeoutTracker);
expectSame($timeoutMerchant, $timeoutResult['finalUrl'], 'Merchant timeout retains the best known destination');
expectSame('Stopped early: request timed out.', $timeoutResult['warning'], 'Merchant timeout reports a warning');
expectSame(4000, $timeoutRequests[0]['timeout'], 'Requests after a static unwrap use the shorter timeout');

$firstTimeout = 'https://tracker.example/slow';
$firstTimeoutResult = fakeResolver($net, [
    $firstTimeout => new AppError('REQUEST_TIMEOUT', 'Request timed out.', 504),
])->resolveWithHttp($firstTimeout);
expectSame($firstTimeout, $firstTimeoutResult['finalUrl'], 'Initial timeout returns the cleaned input URL');
expectSame('Stopped early: request timed out.', $firstTimeoutResult['warning'], 'Initial timeout reports a warning');
expectThrows(function () use ($net, $firstTimeout): void {
    fakeResolver($net, [
        $firstTimeout => new AppError('UNSAFE_ADDRESS', 'Blocked unsafe address.', 400),
    ])->resolveWithHttp($firstTimeout);
}, 'UNSAFE_ADDRESS', 'Unsafe address on the first request is rethrown');

$blockedResolver = fakeResolver($net, [
    $tracker => response(302, 'http://127.0.0.1/private'),
]);
$blockedResult = $blockedResolver->resolveWithHttp($tracker);
expectSame($tracker, $blockedResult['finalUrl'], 'Redirect to a private address retains the current URL');
expectSame('Stopped at a blocked or invalid redirect.', $blockedResult['warning'], 'Blocked redirect reports a warning');

$limitStart = 'https://tracker.example/one';
$limitNext = 'https://merchant.example/two';
$limitFinal = 'https://third.example/three';
$limitResolver = fakeResolver($net, [
    $limitStart => response(302, $limitNext),
    $limitNext => response(302, $limitFinal),
]);
$limitResult = $limitResolver->resolveWithHttp($limitStart, 1);
expectSame($limitNext, $limitResult['finalUrl'], 'Redirect limit returns the latest known destination');
expectSame('Stopped after 1 redirects.', $limitResult['warning'], 'Redirect limit reports a warning');

echo "All {$assertions} assertions passed.\n";
