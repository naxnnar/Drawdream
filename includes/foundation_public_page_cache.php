<?php
declare(strict_types=1);

/** ล้าง cache หน้ามูลนิธิสาธารณะ (เรียกหลังบริจาค needlist สำเร็จ) */
function drawdream_foundation_public_page_cache_bust(): void
{
    $cacheFile = dirname(__DIR__) . '/config/foundation_public_page_cache.json';
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
}

/**
 * @return array{
 *   foundationRows: list<array<string,mixed>>,
 *   donationTotals: array<int,float>,
 *   goalTotals: array<int,float>,
 *   donationTotalsSlideTrack: array<int,float>,
 *   goalTotalsSlideTrack: array<int,float>,
 *   needOutcomeFoundations: array<int,bool>,
 *   needDoneFoundations: array<int,bool>,
 *   needPurchasingFoundations: array<int,bool>,
 *   needlistByFoundation: array<int,list<array<string,mixed>>>
 * }|null
 */
function drawdream_foundation_public_page_cache_get(): ?array
{
    $cacheFile = dirname(__DIR__) . '/config/foundation_public_page_cache.json';
    $ttl = 90;
    if (!is_file($cacheFile)) {
        return null;
    }
    $raw = @file_get_contents($cacheFile);
    if ($raw === false || $raw === '') {
        return null;
    }
    $cached = json_decode($raw, true);
    if (!is_array($cached) || !isset($cached['ts']) || (time() - (int)$cached['ts']) >= $ttl) {
        return null;
    }

    $required = [
        'foundationRows',
        'donationTotals',
        'goalTotals',
        'donationTotalsSlideTrack',
        'goalTotalsSlideTrack',
        'needOutcomeFoundations',
        'needDoneFoundations',
        'needPurchasingFoundations',
        'needlistByFoundation',
    ];
    foreach ($required as $key) {
        if (!isset($cached[$key]) || !is_array($cached[$key])) {
            return null;
        }
    }

    $intFloatMap = static function (array $src): array {
        $out = [];
        foreach ($src as $k => $v) {
            $out[(int)$k] = (float)$v;
        }
        return $out;
    };
    $intBoolMap = static function (array $src): array {
        $out = [];
        foreach ($src as $k => $v) {
            $out[(int)$k] = (bool)$v;
        }
        return $out;
    };
    $needlistMap = static function (array $src): array {
        $out = [];
        foreach ($src as $k => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            $out[(int)$k] = $rows;
        }
        return $out;
    };

    return [
        'foundationRows' => $cached['foundationRows'],
        'donationTotals' => $intFloatMap($cached['donationTotals']),
        'goalTotals' => $intFloatMap($cached['goalTotals']),
        'donationTotalsSlideTrack' => $intFloatMap($cached['donationTotalsSlideTrack']),
        'goalTotalsSlideTrack' => $intFloatMap($cached['goalTotalsSlideTrack']),
        'needOutcomeFoundations' => $intBoolMap($cached['needOutcomeFoundations']),
        'needDoneFoundations' => $intBoolMap($cached['needDoneFoundations']),
        'needPurchasingFoundations' => $intBoolMap($cached['needPurchasingFoundations']),
        'needlistByFoundation' => $needlistMap($cached['needlistByFoundation']),
    ];
}

/**
 * @param list<array<string,mixed>> $foundationRows
 * @param array<int,float> $donationTotals
 * @param array<int,float> $goalTotals
 * @param array<int,float> $donationTotalsSlideTrack
 * @param array<int,float> $goalTotalsSlideTrack
 * @param array<int,bool> $needOutcomeFoundations
 * @param array<int,bool> $needDoneFoundations
 * @param array<int,bool> $needPurchasingFoundations
 * @param array<int,list<array<string,mixed>>> $needlistByFoundation
 */
function drawdream_foundation_public_page_cache_set(
    array $foundationRows,
    array $donationTotals,
    array $goalTotals,
    array $donationTotalsSlideTrack,
    array $goalTotalsSlideTrack,
    array $needOutcomeFoundations,
    array $needDoneFoundations,
    array $needPurchasingFoundations,
    array $needlistByFoundation
): void {
    $cacheFile = dirname(__DIR__) . '/config/foundation_public_page_cache.json';
    @file_put_contents(
        $cacheFile,
        json_encode(
            [
                'ts' => time(),
                'foundationRows' => $foundationRows,
                'donationTotals' => $donationTotals,
                'goalTotals' => $goalTotals,
                'donationTotalsSlideTrack' => $donationTotalsSlideTrack,
                'goalTotalsSlideTrack' => $goalTotalsSlideTrack,
                'needOutcomeFoundations' => $needOutcomeFoundations,
                'needDoneFoundations' => $needDoneFoundations,
                'needPurchasingFoundations' => $needPurchasingFoundations,
                'needlistByFoundation' => $needlistByFoundation,
            ],
            JSON_UNESCAPED_UNICODE
        )
    );
}
