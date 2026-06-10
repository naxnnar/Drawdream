<?php
declare(strict_types=1);

require_once __DIR__ . '/child_sponsorship.php';

/**
 * ประวัติข้อความ/ผลลัพธ์เด็ก — เก็บเป็นไฟล์ JSON ต่อเด็ก (ไม่เพิ่มตาราง/ฟิลด์)
 */

function drawdream_child_outcome_history_base_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'child_outcome_history';
}

function drawdream_child_outcome_history_child_dir(int $childId): string
{
    return drawdream_child_outcome_history_base_dir() . DIRECTORY_SEPARATOR . (int)$childId;
}

function drawdream_child_outcome_snapshot_has_content(string $text, array $imageBasenames): bool
{
    if (trim($text) !== '') {
        return true;
    }

    return $imageBasenames !== [];
}

/**
 * ย้ายข้อความ/รูปปัจจุบันไปไฟล์ประวัติก่อนบันทึกชุดใหม่
 */
function drawdream_child_outcome_archive_snapshot(
    int $childId,
    string $text,
    ?string $imagesJson,
    ?string $postedAt
): bool {
    if ($childId <= 0) {
        return false;
    }
    $images = drawdream_child_outcome_images_parse($imagesJson);
    if (!drawdream_child_outcome_snapshot_has_content($text, $images)) {
        return false;
    }

    $dir = drawdream_child_outcome_history_child_dir($childId);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }

    $posted = trim((string)$postedAt);
    if ($posted === '') {
        $posted = date('Y-m-d H:i:s');
    }

    $payload = [
        'child_id' => $childId,
        'text' => $text,
        'images' => $images,
        'posted_at' => $posted,
        'archived_at' => date('Y-m-d H:i:s'),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return false;
    }

    $name = date('Ymd-His') . '_' . bin2hex(random_bytes(4)) . '.json';
    $path = $dir . DIRECTORY_SEPARATOR . $name;

    return @file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * @return list<array{text:string,images:list<string>,posted_at:string,archived_at:string}>
 */
function drawdream_child_outcome_history_list(int $childId, int $limit = 40): array
{
    if ($childId <= 0 || $limit <= 0) {
        return [];
    }
    $dir = drawdream_child_outcome_history_child_dir($childId);
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
    if ($files === false || $files === []) {
        return [];
    }

    $rows = [];
    foreach ($files as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }
        $text = trim((string)($data['text'] ?? ''));
        $imgs = [];
        if (!empty($data['images']) && is_array($data['images'])) {
            foreach ($data['images'] as $img) {
                $b = basename((string)$img);
                if ($b !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $b) === 1) {
                    $imgs[] = $b;
                }
            }
        }
        if (!drawdream_child_outcome_snapshot_has_content($text, $imgs)) {
            continue;
        }
        $postedAt = trim((string)($data['posted_at'] ?? ''));
        $rows[] = [
            'text' => $text,
            'images' => array_values(array_unique($imgs)),
            'posted_at' => $postedAt,
            'archived_at' => trim((string)($data['archived_at'] ?? '')),
            '_sort' => $postedAt !== '' ? strtotime($postedAt) : 0,
        ];
    }

    usort($rows, static fn (array $a, array $b): int => ($b['_sort'] <=> $a['_sort']));
    $out = [];
    foreach (array_slice($rows, 0, $limit) as $r) {
        unset($r['_sort']);
        $out[] = $r;
    }

    return $out;
}

function drawdream_child_outcome_history_purge_child(int $childId): void
{
    if ($childId <= 0) {
        return;
    }
    $dir = drawdream_child_outcome_history_child_dir($childId);
    if (!is_dir($dir)) {
        return;
    }
    $files = glob($dir . DIRECTORY_SEPARATOR . '*');
    if ($files === false) {
        return;
    }
    foreach ($files as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * HTML การ์ดประวัติ (เรียงจากเก่า → ใหม่ ใต้ข้อความล่าสุด)
 *
 * @param list<array{text:string,images:list<string>,posted_at:string}> $entries
 */
function drawdream_child_outcome_history_html(
    array $entries,
    string $childName,
    string $educationLabel = ''
): string {
    if ($entries === []) {
        return '';
    }

    $reversed = array_reverse($entries);
    $html = '<div class="child-outcome-history">';
    $html .= '<h2 class="child-outcome-history__title">ข้อความก่อนหน้า</h2>';
    foreach ($reversed as $entry) {
        $text = trim((string)($entry['text'] ?? ''));
        $images = is_array($entry['images'] ?? null) ? $entry['images'] : [];
        $postedRaw = trim((string)($entry['posted_at'] ?? ''));
        $postedFmt = '';
        if ($postedRaw !== '') {
            $ts = strtotime($postedRaw);
            if ($ts !== false) {
                $postedFmt = date('d/m/Y H:i', $ts);
            }
        }
        $mainImg = '';
        if ($images !== []) {
            $url = drawdream_child_outcome_image_url((string)$images[0]);
            if ($url !== '') {
                $mainImg = '<div class="child-outcome-history__media"><img src="'
                    . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                    . '" alt="" class="child-outcome-history__img" loading="lazy" decoding="async"></div>';
            }
        }
        $html .= '<article class="child-outcome-history__card">';
        $html .= '<div class="child-outcome-history__label"><i class="bi bi-clock-history" aria-hidden="true"></i> อัปเดตจากมูลนิธิ</div>';
        $html .= '<div class="child-outcome-history__layout">';
        $html .= $mainImg;
        $html .= '<div class="child-outcome-history__body">';
        if ($text !== '') {
            $html .= '<div class="child-outcome-history__text">' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</div>';
        }
        $attr = 'น้อง' . htmlspecialchars($childName, ENT_QUOTES, 'UTF-8');
        if ($educationLabel !== '') {
            $attr .= ' · นักเรียนชั้น' . htmlspecialchars($educationLabel, ENT_QUOTES, 'UTF-8');
        }
        $html .= '<p class="child-outcome-history__attribution">' . $attr . '</p>';
        if ($postedFmt !== '') {
            $html .= '<p class="child-outcome-history__meta">โพสต์เมื่อ ' . htmlspecialchars($postedFmt, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        if (count($images) > 1) {
            $html .= drawdream_child_outcome_images_html(array_slice($images, 1));
        }
        $html .= '</div></div></article>';
    }
    $html .= '</div>';

    return $html;
}
