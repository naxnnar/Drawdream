<?php

// includes/address_helpers.php — แปลง/รวมข้อความที่อยู่ไทยจาก POST
// สรุปสั้น: แปลงข้อมูลที่อยู่ไทยระหว่างรูปแบบฟอร์มกับข้อความที่เก็บในฐานข้อมูล
/**
 * รูปแบบที่ระบบใช้เก็บ address string:
 * "[เลขที่ …] [ซ.…] [ถ.…] ต.<ตำบล> อ.<อำเภอ> จ.<จังหวัด> <รหัสไปรษณีย์>"
 *
 * ไฟล์นี้ช่วยแปลงไป-กลับระหว่าง:
 * - string เดียวในฐานข้อมูล
 * - field แยกในฟอร์ม (เลขที่/ซอย/ถนน + province/amphoe/tambon/zip)
 */
/**
 * @return array{house_no: string, soi: string, road: string}
 */
function drawdream_parse_address_line_prefix(string $prefix): array
{
    $house = '';
    $soi = '';
    $road = '';
    $s = trim($prefix);
    if ($s === '') {
        return ['house_no' => '', 'soi' => '', 'road' => ''];
    }
    if (preg_match('/^เลขที่\s+(.+?)(?=\s+ซ\.|\s+ถ\.|\s*$)/u', $s, $m)) {
        $house = trim($m[1]);
        $s = trim((string)preg_replace('/^เลขที่\s+.+?(?=\s+ซ\.|\s+ถ\.|\s*$)/u', '', $s, 1));
    }
    if (preg_match('/^ซ\.(.+?)(?=\s+ถ\.|\s*$)/u', $s, $m)) {
        $soi = trim($m[1]);
        $s = trim((string)preg_replace('/^ซ\..+?(?=\s+ถ\.|\s*$)/u', '', $s, 1));
    }
    if (preg_match('/^ถ\.(.+)$/u', $s, $m)) {
        $road = trim($m[1]);
    }

    return ['house_no' => $house, 'soi' => $soi, 'road' => $road];
}

/**
 * @return array{house_no: string, soi: string, road: string, tambon: string, amphoe: string, province: string, zip: string}|null
 */
function drawdream_parse_saved_thai_address(?string $s): ?array
{
    $s = trim((string)$s);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(.*?)\s*ต\.\s*(.+?)\s+อ\.\s*(.+?)\s+จ\.\s*(.+?)\s+(\d{5})\s*$/u', $s, $m)) {
        $line = drawdream_parse_address_line_prefix(trim($m[1]));

        return array_merge($line, [
            'tambon'   => trim($m[2]),
            'amphoe'   => trim($m[3]),
            'province' => trim($m[4]),
            'zip'      => $m[5],
        ]);
    }
    if (preg_match('/ต\.\s*(.+?)\s+อ\.\s*(.+?)\s+จ\.\s*(.+?)\s+(\d{5})\s*$/u', $s, $m)) {
        return array_merge(
            ['house_no' => '', 'soi' => '', 'road' => ''],
            [
                'tambon'   => trim($m[1]),
                'amphoe'   => trim($m[2]),
                'province' => trim($m[3]),
                'zip'      => $m[4],
            ]
        );
    }

    return null;
}

/** รวมเลขที่ ซอย ถนน จาก POST */
function drawdream_build_address_line_from_post(array $post): string
{
    $parts = [];
    $house = trim((string)($post['addr_house_no'] ?? ''));
    $soi = trim((string)($post['addr_soi'] ?? ''));
    $road = trim((string)($post['addr_road'] ?? ''));
    if ($house !== '') {
        $parts[] = 'เลขที่ ' . $house;
    }
    if ($soi !== '') {
        $parts[] = 'ซ.' . $soi;
    }
    if ($road !== '') {
        $parts[] = 'ถ.' . $road;
    }

    return implode(' ', $parts);
}

function drawdream_merge_foundation_address_from_post(array $post): string
{
    $p = trim((string)($post['addr_province'] ?? ''));
    $a = trim((string)($post['addr_amphoe'] ?? ''));
    $tRaw = trim((string)($post['addr_tambon'] ?? ''));
    $z = trim((string)($post['addr_zip'] ?? ''));

    if ($tRaw !== '' && strpos($tRaw, "\x1E") !== false) {
        // บางฟอร์มส่งค่า tambon มาแบบ "<zip><RS><tambon>"
        // (ASCII RS = 0x1E) จึงต้องแยกก่อนประกอบ address
        $parts = explode("\x1E", $tRaw, 2);
        if ($z === '' && $parts[0] !== '') {
            $z = $parts[0];
        }
        $t = $parts[1] ?? '';
    } else {
        $t = $tRaw;
    }

    if ($p === '' && $a === '' && $t === '' && $z === '') {
        return drawdream_build_address_line_from_post($post);
    }

    $geo = 'ต.' . $t . ' อ.' . $a . ' จ.' . $p . ' ' . $z;
    $line = drawdream_build_address_line_from_post($post);

    return $line !== '' ? $line . ' ' . $geo : $geo;
}
