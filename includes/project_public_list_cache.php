<?php
declare(strict_types=1);

/** ล้าง cache รายการโครงการสาธารณะ (เรียกหลังบริจาคสำเร็จ) */
function drawdream_project_public_list_cache_bust(): void
{
    $cacheFile = dirname(__DIR__) . '/config/project_public_list_cache.json';
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
}

/**
 * @return array{projects: list<array<string,mixed>>, latestProjects: list<array<string,mixed>>}|null
 */
function drawdream_project_public_list_cache_get(): ?array
{
    $cacheFile = dirname(__DIR__) . '/config/project_public_list_cache.json';
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
        || !isset($cached['ts'], $cached['projects'], $cached['latestProjects'])
        || !is_array($cached['projects'])
        || !is_array($cached['latestProjects'])
        || (time() - (int)$cached['ts']) >= $ttl) {
        return null;
    }

    return [
        'projects' => $cached['projects'],
        'latestProjects' => $cached['latestProjects'],
    ];
}

/**
 * @param list<array<string,mixed>> $projects
 * @param list<array<string,mixed>> $latestProjects
 */
function drawdream_project_public_list_cache_set(array $projects, array $latestProjects): void
{
    $cacheFile = dirname(__DIR__) . '/config/project_public_list_cache.json';
    @file_put_contents(
        $cacheFile,
        json_encode(
            ['ts' => time(), 'projects' => $projects, 'latestProjects' => $latestProjects],
            JSON_UNESCAPED_UNICODE
        )
    );
}
