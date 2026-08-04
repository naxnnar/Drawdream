<?php
// foundation_profile_finance_partial.php — HTML สรุปยอดบริจาคมูลนิธิ

/**
 * @param array{
 *   finance_child_total: float,
 *   finance_project_total: float,
 *   finance_need_total: float,
 *   finance_grand_total: float,
 *   foundation_finance_rows: list<array<string, mixed>>
 * } $data
 */
function foundation_profile_finance_render_html(array $data): string
{
    $finance_child_total = (float)($data['finance_child_total'] ?? 0);
    $finance_project_total = (float)($data['finance_project_total'] ?? 0);
    $finance_need_total = (float)($data['finance_need_total'] ?? 0);
    $finance_grand_total = (float)($data['finance_grand_total'] ?? 0);
    $foundation_finance_rows = $data['foundation_finance_rows'] ?? [];

    $foundation_cat_labels = [
        'child' => ['label' => 'เด็ก', 'short' => 'เด็ก'],
        'project' => ['label' => 'โครงการ', 'short' => 'โครงการ'],
        'need' => ['label' => 'สิ่งของ', 'short' => 'สิ่งของ'],
    ];

    ob_start();
    ?>
    <div class="foundation-finance-summary">
        <div class="foundation-finance-card foundation-finance-card--child">
            <span class="foundation-finance-card__cat"><?= htmlspecialchars($foundation_cat_labels['child']['label']) ?></span>
            <span class="foundation-finance-card__amount"><?= number_format($finance_child_total, 2) ?> <small>บาท</small></span>
        </div>
        <div class="foundation-finance-card foundation-finance-card--project">
            <span class="foundation-finance-card__cat"><?= htmlspecialchars($foundation_cat_labels['project']['label']) ?></span>
            <span class="foundation-finance-card__amount"><?= number_format($finance_project_total, 2) ?> <small>บาท</small></span>
        </div>
        <div class="foundation-finance-card foundation-finance-card--need">
            <span class="foundation-finance-card__cat"><?= htmlspecialchars($foundation_cat_labels['need']['label']) ?></span>
            <span class="foundation-finance-card__amount"><?= number_format($finance_need_total, 2) ?> <small>บาท</small></span>
        </div>
    </div>
    <div class="foundation-finance-total-row">
        รวมทั้งหมด <strong><?= number_format($finance_grand_total, 2) ?> บาท</strong>
    </div>
    <h3 class="foundation-finance-subhead">รายการล่าสุด</h3>
    <?php if (!empty($foundation_finance_rows)): ?>
        <div class="foundation-finance-list">
            <?php foreach ($foundation_finance_rows as $idx => $fr): ?>
                <?php
                $is_extra_fin = $idx >= 5;
                $ck = $fr['cat_key'] ?? 'project';
                $meta = $foundation_cat_labels[$ck] ?? $foundation_cat_labels['project'];
                $ts = $fr['ts'] ?? '';
                $title = trim((string)($fr['title'] ?? ''));
                if ($title === '') {
                    $title = '—';
                }
                ?>
                <div class="foundation-finance-row<?= $is_extra_fin ? ' foundation-finance-row--extra' : '' ?>"<?= $is_extra_fin ? ' style="display:none;"' : '' ?>>
                    <span class="foundation-finance-badge foundation-finance-badge--<?= htmlspecialchars($ck) ?>"><?= htmlspecialchars($meta['short']) ?></span>
                    <div class="foundation-finance-row__body">
                        <div class="foundation-finance-row__title"><?= htmlspecialchars($title) ?></div>
                        <div class="foundation-finance-row__time"><?= $ts ? date('d/m/Y H:i', strtotime((string)$ts)) : '—' ?></div>
                    </div>
                    <span class="foundation-finance-row__amount"><?= number_format((float)($fr['amount'] ?? 0), 2) ?> ฿</span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($foundation_finance_rows) > 5): ?>
        <div class="donation-more-wrap">
            <button type="button" class="btn-donation-more" id="btn-foundation-finance-more">ดูเพิ่มเติม</button>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="foundation-empty-projects foundation-finance-empty">
            <?php if ($finance_grand_total > 0): ?>
                ยังไม่มีรายการแยกรายครั้ง (เช่น บริจาคเฉพาะสิ่งของจะแสดงเฉพาะในช่องสรุปด้านบน)
            <?php else: ?>
                ยังไม่มียอดบริจาคที่บันทึกในระบบ
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}
