<?php
declare(strict_types=1);

/** ล้าง cache รายการเด็กสาธารณะ (เรียกหลังอนุมัติ/บริจาคสำเร็จถ้าต้องการข้อมูลทันที) */
function drawdream_children_public_list_cache_bust(): void
{
    $cacheFile = dirname(__DIR__) . '/config/children_public_list_cache.json';
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
}

/**
 * @return array{waiting: list<array<string,mixed>>, sponsored: list<array<string,mixed>>}|null
 */
function drawdream_children_public_list_cache_get(): ?array
{
    $cacheFile = dirname(__DIR__) . '/config/children_public_list_cache.json';
    $ttl = 90;
    if (!is_file($cacheFile)) {
        return null;
    }
    $raw = @file_get_contents($cacheFile);
    if ($raw === false || $raw === '') {
        return null;
    }
    $cached = json_decode($raw, true);
    if (!is_array($cached)
        || !isset($cached['ts'], $cached['waiting'], $cached['sponsored'])
        || !is_array($cached['waiting'])
        || !is_array($cached['sponsored'])
        || (time() - (int)$cached['ts']) >= $ttl) {
        return null;
    }

    return [
        'waiting' => $cached['waiting'],
        'sponsored' => $cached['sponsored'],
    ];
}

/**
 * @param list<array<string,mixed>> $waiting
 * @param list<array<string,mixed>> $sponsored
 */
function drawdream_children_public_list_cache_set(array $waiting, array $sponsored): void
{
    $cacheFile = dirname(__DIR__) . '/config/children_public_list_cache.json';
    @file_put_contents(
        $cacheFile,
        json_encode(
            ['ts' => time(), 'waiting' => $waiting, 'sponsored' => $sponsored],
            JSON_UNESCAPED_UNICODE
        )
    );
}
