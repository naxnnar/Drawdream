<?php
declare(strict_types=1);

/**
 * คำนวณ insight / กราฟ / KPI แดชบอร์ดมูลนิธิ จากรายการบริจาคที่กรองแล้ว
 */
function foundation_dashboard_row_category(
    array $row,
    int $childCat,
    int $projCat,
    int $needCat
): string {
    $cid = (int)($row['category_id'] ?? 0);
    if ($cid === $childCat) {
        return 'child';
    }
    if ($cid === $projCat) {
        return 'project';
    }
    if ($cid === $needCat) {
        return 'need';
    }

    return 'other';
}

function foundation_dashboard_row_target_label(
    array $row,
    string $cat,
    array $childMap,
    array $projectMap
): string {
    $tid = (int)($row['target_id'] ?? 0);
    if ($cat === 'child') {
        return (string)($childMap[$tid] ?? ('เด็ก #' . $tid));
    }
    if ($cat === 'project') {
        return (string)($projectMap[$tid] ?? ('โครงการ #' . $tid));
    }
    if ($cat === 'need') {
        return 'ระดมสิ่งของ (มูลนิธิ)';
    }

    return '-';
}

function foundation_dashboard_fmt_baht_short(float $amount): string
{
    if ($amount >= 1000000) {
        return number_format($amount / 1000000, 1) . ' ล้าน';
    }

    return number_format($amount, 0, '.', ',');
}

function foundation_dashboard_short_label(string $text, int $maxLen = 52): string
{
    $text = trim($text);
    if ($text === '') {
        return 'รายการบริจาค';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') <= $maxLen) {
            return $text;
        }

        return mb_substr($text, 0, $maxLen - 1, 'UTF-8') . '…';
    }
    if (strlen($text) <= $maxLen) {
        return $text;
    }

    return substr($text, 0, $maxLen - 3) . '...';
}

/**
 * คำแนะนำ «สิ่งที่ควรทำต่อ» — ภาษาคน อิงตัวเลขจริง
 *
 * @param array<string, mixed> $ctx
 * @return list<string>
 */
function foundation_dashboard_build_actions(array $ctx): array
{
    $sumTotal = (float)($ctx['sum_total'] ?? 0);
    $donationCount = (int)($ctx['donation_count_total'] ?? 0);
    $avgPer = (float)($ctx['avg_per_donation'] ?? 0);
    $pieTop = is_array($ctx['pie_top'] ?? null) ? $ctx['pie_top'] : [];
    $topByCount = is_array($ctx['top_by_count'] ?? null) ? $ctx['top_by_count'] : [];
    $peakLabel = (string)($ctx['peak_week_label'] ?? '');
    $peakSum = (float)($ctx['peak_week_sum'] ?? 0);
    $peakTop = is_array($ctx['peak_top'] ?? null) ? $ctx['peak_top'] : null;
    $zeroWeeks = (int)($ctx['zero_weeks'] ?? 0);
    $kpiTrend = (string)($ctx['kpi_trend'] ?? '');

    $catLabels = ['child' => 'อุปการะเด็ก', 'project' => 'โครงการ', 'need' => 'บริจาคสิ่งของ'];

    if ($sumTotal <= 0 || $donationCount <= 0) {
        return [
            'ช่วงเวลาที่เลือกยังไม่มีบริจาคที่โอนสำเร็จ — ลองเช็กว่าโครงการ เด็กในระบบ และรายการสิ่งของยังเปิดรับอยู่ และช่วยเล่าให้ผู้บริจาคเห็นชัดว่าต้องการอะไร',
            'ถ้าพึ่งเปิดรับไม่นาน อาจใช้โพสต์หรือข้อความสั้น ๆ ชวนบริจาคครั้งแรก แล้วกลับมาดูกราฟอีกครั้งในสัปดาห์หน้า',
        ];
    }

    $actions = [];
    $totalFmt = foundation_dashboard_fmt_baht_short($sumTotal);
    $avgFmt = foundation_dashboard_fmt_baht_short($avgPer);

    if ($peakSum > 0 && $peakTop !== null && $peakSum >= $sumTotal * 0.35) {
        $catTh = $catLabels[$peakTop['cat'] ?? ''] ?? 'บริจาค';
        $target = foundation_dashboard_short_label((string)($peakTop['target'] ?? ''));
        $peakFmt = foundation_dashboard_fmt_baht_short((float)($peakTop['amount'] ?? 0));
        $actions[] = sprintf(
            'สัปดาห์ %s มียอดเข้ามาชัดที่สุด — รายการใหญ่คือ「%s» (%s %s บาท) แนะนำให้ทีมดูว่าทำอะไรถึงได้ยอดนี้ แล้วคิดว่าจะเล่าเรื่องหรือทำกิจกรรมซ้ำเมื่อไหร่ได้',
            $peakLabel,
            $target,
            $catTh,
            $peakFmt
        );
    }

    $topKey = (string)($pieTop['key'] ?? '');
    $topCount = (int)($pieTop['count'] ?? 0);
    $topPct = (float)($pieTop['pct'] ?? 0);
    $topAmount = (float)($pieTop['amount'] ?? 0);
    $topLabel = (string)($pieTop['label'] ?? '');

    if ($topKey === 'project' && $topCount <= 2 && $topPct >= 45) {
        $actions[] = sprintf(
            'เงินก้อนใหญ่มาจากโครงการแค่ %d ครั้ง (รวม %s บาท คิดเป็น %.0f%% ของยอดทั้งหมด) — ถ้าอยากให้รายได้ไหลสม่ำเสมอ ควรดูแลช่องอุปการะเด็กและสิ่งของควบคู่กัน ไม่รอแค่โครงการครั้งเดียว',
            $topCount,
            foundation_dashboard_fmt_baht_short($topAmount),
            $topPct
        );
    }

    $freqKey = (string)($topByCount['key'] ?? '');
    $freqCount = (int)($topByCount['count'] ?? 0);
    $freqLabel = (string)($topByCount['label'] ?? '');

    if ($freqCount >= 2 && $freqKey !== '' && $freqKey !== $topKey) {
        $freqAmount = 0.0;
        foreach ($ctx['pie_breakdown'] ?? [] as $row) {
            if (is_array($row) && ($row['key'] ?? '') === $freqKey) {
                $freqAmount = (float)($row['amount'] ?? 0);
                break;
            }
        }
        if ($freqKey === 'need') {
            $actions[] = sprintf(
                'มีคนบริจาคสิ่งของบ่อย (%d ครั้ง รวม %s บาท) แม้ยอดรวมจะน้อยกว่า%s — ควรอัปเดตรายการของที่ต้องการให้ตรงจริง และโพสต์รูปหรือผลจัดส่งให้ผู้บริจาคเห็น จะได้รู้สึกว่าช่วยได้จริง',
                $freqCount,
                foundation_dashboard_fmt_baht_short($freqAmount),
                $topLabel !== '' ? 'ช่อง' . $topLabel : 'ช่องอื่น'
            );
        } elseif ($freqKey === 'child') {
            $actions[] = sprintf(
                'มีผู้บริจาคผ่านช่องเด็กหลายครั้ง (%d ครั้ง) แต่ยอดรวมยังไม่สูงเท่า%s — ลองเล่าเรื่องเด็กให้ชัดขึ้น หรือแจ้งความคืบหน้าอุปการะเป็นประจำ',
                $freqCount,
                $topLabel !== '' ? 'ช่อง' . $topLabel : 'ช่องอื่น'
            );
        }
    }

    $sumChild = (float)($ctx['sum_child'] ?? 0);
    $rowChild = (int)($ctx['row_count_child'] ?? 0);
    $childPct = $sumTotal > 0 ? ($sumChild / $sumTotal) * 100 : 0.0;
    if ($rowChild === 0 && $donationCount >= 3) {
        $actions[] = 'ยังไม่มีบริจาคผ่านช่องอุปการะเด็กในช่วงนี้ — ถ้าต้องการรายได้ที่ต่อเนื่อง อาจช่วยเล่าเรื่องเด็กหรือเปิดรับอุปการะให้เห็นชัดในหน้าเว็บ';
    } elseif ($rowChild > 0 && $childPct < 15 && $topKey === 'project') {
        $actions[] = sprintf(
            'อุปการะเด็กมี %d ครั้ง รวม %s บาท (ประมาณ %.0f%% ของยอดทั้งหมด) — ถ้าต้องการกระจายรายได้ ลองชวนบริจาครายเดือนหรือรายรอบควบคู่กับโครงการใหญ่',
            $rowChild,
            foundation_dashboard_fmt_baht_short($sumChild),
            $childPct
        );
    }

    if ($zeroWeeks >= 4 && $donationCount >= 2) {
        $actions[] = sprintf(
            'ยอดบริจาคไม่ค่อยต่อเนื่อง — ใน 8 สัปดาห์ล่าสุดมี %d สัปดาห์ที่ไม่มียอดเลย อาจช่วยแจ้งความคืบหน้าสั้น ๆ หรือตั้งเป้าระดมเล็ก ๆ รายสัปดาห์ ให้ผู้บริจาครู้สึกว่าร่วมทำต่อได้',
            $zeroWeeks
        );
    }

    if ($kpiTrend !== '' && str_contains($kpiTrend, '-')) {
        $actions[] = 'สัปดาห์ล่าสุดยอดลดลงจากสัปดาห์ก่อน — ลองดูว่ามีโครงการปิดรับ หรือช่วงที่ยังไม่ได้โปรโมต แล้ววางแผนชวนบริจาคใหม่';
    } elseif ($kpiTrend !== '' && str_contains($kpiTrend, '+') && $donationCount >= 2) {
        $actions[] = 'สัปดาห์ล่าสุดยอดดีขึ้นจากสัปดาห์ก่อน — เก็บบันทึกว่าทำอะไรถึงได้ผล แล้วทำแบบเดียวกันต่อได้';
    }

    if (count($actions) < 2) {
        $actions[] = sprintf(
            'ภาพรวมช่วงนี้: บริจาค %d ครั้ง รวม %s บาท (เฉลี่ยครั้งละประมาณ %s บาท) — ช่องที่มียอดสูงสุดตอนนี้คือ%s',
            $donationCount,
            $totalFmt,
            $avgFmt,
            $topLabel !== '' ? $topLabel : 'บริจาค'
        );
    }

    $actions[] = sprintf(
        'ทุกสัปดาห์จันทร์ เปิดดูยอดรวมกับจำนวนครั้งอีกครั้ง (ตอนนี้รวม %s บาท จาก %d ครั้ง) — ถ้าตัวเลขดูแปลก ให้กดไปที่แท็บ「รายการบริจาค」เช็ครายการจริงทีละรายการ',
        $totalFmt,
        $donationCount
    );

    return array_slice(array_values(array_unique($actions)), 0, 5);
}

function foundation_dashboard_build_week_buckets(): array
{
    $now = new DateTimeImmutable('now');
    $baseWeekStart = $now->modify('monday this week');
    $labels = [];
    $sums = [];
    $keys = [];
    $keyToIndex = [];
    for ($i = 7; $i >= 0; $i--) {
        $start = $baseWeekStart->modify('-' . $i . ' week');
        $end = $start->modify('+6 day');
        $key = $start->format('o-W');
        $labels[] = $start->format('d/m') . ' - ' . $end->format('d/m');
        $sums[] = 0.0;
        $keys[] = $key;
        $keyToIndex[$key] = count($labels) - 1;
    }

    return [
        'labels' => $labels,
        'sums' => $sums,
        'keys' => $keys,
        'key_to_index' => $keyToIndex,
    ];
}

/** @return array<string, mixed> */
function foundation_dashboard_analyze_donations(
    array $rows,
    int $childCat,
    int $projCat,
    int $needCat,
    array $childMap,
    array $projectMap
): array {
    $sumChild = 0.0;
    $sumProject = 0.0;
    $sumNeed = 0.0;
    $rowCountChild = 0;
    $rowCountProject = 0;
    $rowCountNeed = 0;

    $weeks = foundation_dashboard_build_week_buckets();
    $weeklySums = $weeks['sums'];
    $weekKeyToIndex = $weeks['key_to_index'];
    $donationsByWeekIndex = [];

    foreach ($rows as $r) {
        $cat = foundation_dashboard_row_category($r, $childCat, $projCat, $needCat);
        $amt = (float)($r['amount'] ?? 0);
        if ($cat === 'child') {
            $sumChild += $amt;
            $rowCountChild++;
        } elseif ($cat === 'project') {
            $sumProject += $amt;
            $rowCountProject++;
        } elseif ($cat === 'need') {
            $sumNeed += $amt;
            $rowCountNeed++;
        }

        $tsRaw = trim((string)($r['transfer_datetime'] ?? ''));
        if ($tsRaw === '') {
            continue;
        }
        $ts = strtotime($tsRaw);
        if ($ts === false) {
            continue;
        }
        $key = date('o-W', $ts);
        if (!array_key_exists($key, $weekKeyToIndex)) {
            continue;
        }
        $idx = $weekKeyToIndex[$key];
        $weeklySums[$idx] += $amt;
        if (!isset($donationsByWeekIndex[$idx])) {
            $donationsByWeekIndex[$idx] = [];
        }
        $donationsByWeekIndex[$idx][] = [
            'amount' => $amt,
            'cat' => $cat,
            'target' => foundation_dashboard_row_target_label($r, $cat, $childMap, $projectMap),
            'datetime' => $tsRaw,
        ];
    }

    $sumTotal = $sumChild + $sumProject + $sumNeed;
    $donationCountTotal = $rowCountChild + $rowCountProject + $rowCountNeed;
    $avgPerDonation = $donationCountTotal > 0 ? $sumTotal / $donationCountTotal : 0.0;

    $pieBreakdown = [
        ['key' => 'child', 'label' => 'เด็ก', 'amount' => $sumChild, 'count' => $rowCountChild, 'color' => '#4A5BA8'],
        ['key' => 'project', 'label' => 'โครงการ', 'amount' => $sumProject, 'count' => $rowCountProject, 'color' => '#22c55e'],
        ['key' => 'need', 'label' => 'สิ่งของ', 'amount' => $sumNeed, 'count' => $rowCountNeed, 'color' => '#f59e0b'],
    ];
    foreach ($pieBreakdown as &$b) {
        $b['pct'] = $sumTotal > 0 ? ($b['amount'] / $sumTotal) * 100 : 0.0;
    }
    unset($b);
    usort($pieBreakdown, static fn (array $a, array $b): int => ($b['amount'] <=> $a['amount']));

    $pieTop = $pieBreakdown[0] ?? ['label' => '-', 'amount' => 0.0, 'pct' => 0.0, 'count' => 0, 'key' => ''];

    $byCount = $pieBreakdown;
    usort($byCount, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']));
    $topByCount = $byCount[0] ?? ['label' => '-', 'count' => 0, 'pct' => 0.0, 'key' => ''];

    $peakIdx = 0;
    $peakSum = 0.0;
    foreach ($weeklySums as $i => $wSum) {
        if ($wSum > $peakSum) {
            $peakSum = $wSum;
            $peakIdx = $i;
        }
    }
    $peakLabel = $weeks['labels'][$peakIdx] ?? '-';
    $peakDonations = $donationsByWeekIndex[$peakIdx] ?? [];
    usort($peakDonations, static fn (array $a, array $b): int => ($b['amount'] <=> $a['amount']));
    $peakTop = $peakDonations[0] ?? null;

    $zeroWeeks = 0;
    foreach ($weeklySums as $wSum) {
        if ($wSum <= 0.0001) {
            $zeroWeeks++;
        }
    }

    $lineTitle = 'แนวโน้มยอดบริจาครายสัปดาห์ (8 สัปดาห์ล่าสุด)';
    $lineInsight = 'ยังไม่มีข้อมูลบริจาคในช่วง 8 สัปดาห์นี้';
    if ($sumTotal > 0 && $peakSum > 0) {
        $lineTitle = sprintf(
            'สัปดาห์ %s มียอดสูงสุด %.0f บาท',
            $peakLabel,
            $peakSum
        );
        if ($zeroWeeks >= 5) {
            $lineInsight = sprintf(
                'มี %d จาก 8 สัปดาห์ที่ยอดเป็น 0 — ยอดส่วนใหญ่มากองในสัปดาห์เดียว ไม่ใช่ไหลเข้าสม่ำเสมอ',
                $zeroWeeks
            );
        } else {
            $lineInsight = 'เปรียบเทียบยอดรายสัปดาห์เพื่อดูว่าช่วงไหนมีการระดมทุนเข้ามา';
        }
        if ($peakTop !== null) {
            $catLabels = ['child' => 'เด็ก', 'project' => 'โครงการ', 'need' => 'สิ่งของ'];
            $catTh = $catLabels[$peakTop['cat']] ?? $peakTop['cat'];
            $lineInsight .= sprintf(
                ' · รายการใหญ่สุด: %s (%s) %.0f บาท',
                $peakTop['target'],
                $catTh,
                (float)$peakTop['amount']
            );
        }
    } elseif ($sumTotal > 0) {
        $lineInsight = sprintf('ยอดรวม %.2f บาท แต่ไม่มีรายการในช่วง 8 สัปดาห์ล่าสุด', $sumTotal);
    }

    $pieTitle = 'สัดส่วนยอดบริจาคตามประเภท';
    $pieInsight = 'ยังไม่มียอดบริจาคในช่วงที่เลือก';
    if ($sumTotal > 0) {
        $pieTitle = sprintf(
            'เงิน %.1f%% อยู่ที่%s แต่มี %d รายการ',
            (float)$pieTop['pct'],
            (string)$pieTop['label'],
            (int)$pieTop['count']
        );
        if ($topByCount['key'] !== '' && $topByCount['key'] !== ($pieTop['key'] ?? '')) {
            $pieInsight = sprintf(
                'ยอดเงินสูงสุดที่%s (%d ครั้ง) · บริจาคบ่อยสุดที่%s (%d ครั้ง) — ดูทั้งยอดเงินและจำนวนครั้ง',
                (string)$pieTop['label'],
                (int)$pieTop['count'],
                (string)$topByCount['label'],
                (int)$topByCount['count']
            );
        } else {
            $pieInsight = sprintf(
                '%s ทั้งมียอดและจำนวนครั้งสูงสุด (%d ครั้ง · %.0f บาท)',
                (string)$pieTop['label'],
                (int)$pieTop['count'],
                (float)$pieTop['amount']
            );
        }
    }

    $kpiTrend = '';
    if (count($weeklySums) >= 2) {
        $last = (float)$weeklySums[count($weeklySums) - 1];
        $prev = (float)$weeklySums[count($weeklySums) - 2];
        if ($prev > 0) {
            $chg = (($last - $prev) / $prev) * 100;
            $kpiTrend = ($chg >= 0 ? '+' : '') . number_format($chg, 1) . '% จากสัปดาห์ก่อน';
        } elseif ($last > 0) {
            $kpiTrend = 'สัปดาห์นี้มียอดใหม่';
        }
    }

    $actions = foundation_dashboard_build_actions([
        'sum_total' => $sumTotal,
        'donation_count_total' => $donationCountTotal,
        'avg_per_donation' => $avgPerDonation,
        'pie_top' => $pieTop,
        'top_by_count' => $topByCount,
        'pie_breakdown' => $pieBreakdown,
        'peak_week_label' => $peakLabel,
        'peak_week_sum' => $peakSum,
        'peak_top' => $peakTop,
        'zero_weeks' => $zeroWeeks,
        'sum_child' => $sumChild,
        'row_count_child' => $rowCountChild,
        'kpi_trend' => $kpiTrend,
    ]);

    return [
        'weekly_labels' => $weeks['labels'],
        'weekly_sums' => $weeklySums,
        'weekly_keys' => $weeks['keys'],
        'peak_week_index' => $peakIdx,
        'peak_week_sum' => $peakSum,
        'peak_week_label' => $peakLabel,
        'peak_week_key' => $weeks['keys'][$peakIdx] ?? '',
        'peak_top' => $peakTop,
        'sum_child' => $sumChild,
        'sum_project' => $sumProject,
        'sum_need' => $sumNeed,
        'sum_total' => $sumTotal,
        'row_count_child' => $rowCountChild,
        'row_count_project' => $rowCountProject,
        'row_count_need' => $rowCountNeed,
        'donation_count_total' => $donationCountTotal,
        'avg_per_donation' => $avgPerDonation,
        'pie_breakdown' => $pieBreakdown,
        'pie_top' => $pieTop,
        'top_by_count' => $topByCount,
        'line_title' => $lineTitle,
        'line_insight' => $lineInsight,
        'pie_title' => $pieTitle,
        'pie_insight' => $pieInsight,
        'actions' => $actions,
        'kpi_trend' => $kpiTrend,
    ];
}

/** @return list<array<string, mixed>> */
function foundation_dashboard_donations_json_payload(
    array $rows,
    int $childCat,
    int $projCat,
    int $needCat,
    array $childMap,
    array $projectMap
): array {
    $out = [];
    foreach ($rows as $r) {
        $cat = foundation_dashboard_row_category($r, $childCat, $projCat, $needCat);
        if ($cat === 'other') {
            continue;
        }
        $tsRaw = trim((string)($r['transfer_datetime'] ?? ''));
        $ts = $tsRaw !== '' ? strtotime($tsRaw) : false;
        $dt = strtolower(trim((string)($r['donate_type'] ?? '')));
        $isSub = in_array($dt, ['child_subscription', 'child_subscription_charge'], true);
        $out[] = [
            'amount' => (float)($r['amount'] ?? 0),
            'cat' => $cat,
            'target' => foundation_dashboard_row_target_label($r, $cat, $childMap, $projectMap),
            'date' => $ts !== false ? date('Y-m-d', $ts) : '',
            'month' => $ts !== false ? date('Y-m', $ts) : '',
            'year' => $ts !== false ? date('Y', $ts) : '',
            'week' => $ts !== false ? date('o-W', $ts) : '',
            'is_sub' => $isSub,
        ];
    }

    return $out;
}
