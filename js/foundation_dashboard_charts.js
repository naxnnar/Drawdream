/**
 * แดชบอร์ดมูลนิธิ — กราฟ + insight + กรองวันที่ (ต้องมี window.FD_DONATIONS, FD_WEEK_META จาก PHP)
 */
(function () {
    let fdDonations = window.FD_DONATIONS || [];
    let fdWeekMeta = window.FD_WEEK_META || { labels: [], keys: [] };
    const FD_STATIC = window.FD_STATIC || {};
    const getDonations = () => fdDonations;
    const CAT_LABELS = { child: 'เด็ก', project: 'โครงการ', need: 'สิ่งของ' };
    const CAT_COLORS = { child: '#4A5BA8', project: '#22c55e', need: '#f59e0b' };
    const CAT_ORDER = ['child', 'project', 'need'];
    const FILTER_CAT_BUTTON_LABELS = {
        all: 'ทั้งหมด',
        child: 'เด็ก',
        project: 'โครงการ',
        need: 'รายการสิ่งของ',
    };

    const tabButtons = Array.from(document.querySelectorAll('[data-view-tab]'));
    const chartsView = document.getElementById('foundationDashboardChartsView');
    const listView = document.getElementById('foundationDashboardListView');
    const buttons = Array.from(document.querySelectorAll('[data-filter-cat]'));
    const featurePanels = Array.from(document.querySelectorAll('[data-feature-panel]'));
    const rows = [];
    let listRowsRendered = false;

    function escapeHtml(text) {
        return String(text ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatMoneyCell(n) {
        return Number(n || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderTableRows(donations) {
        const tbody = document.getElementById('fdDonationTableBody');
        if (!tbody) {
            return;
        }
        const frag = document.createDocumentFragment();
        (donations || []).forEach((d) => {
            const tr = document.createElement('tr');
            tr.setAttribute('data-cat', d.cat || '');
            tr.setAttribute('data-donate-date', d.date || '');
            tr.setAttribute('data-donate-month', d.month || '');
            tr.setAttribute('data-donate-year', d.year || '');
            tr.setAttribute('data-donate-week', d.week || '');
            tr.setAttribute('data-amount', String(d.amount ?? 0));
            tr.setAttribute('data-search', d.search || '');
            const tax = (d.tax_display || '').trim();
            tr.innerHTML =
                '<td>' + escapeHtml(d.dt_label || '-') + '</td>' +
                '<td class="fd-receipt-ref">' + escapeHtml(d.receipt_ref || '') + '</td>' +
                '<td>' + escapeHtml(d.donor_name || '') + '</td>' +
                '<td class="' + (tax === '' ? 'b--muted' : '') + '">' + escapeHtml(tax !== '' ? tax : 'ยังไม่ระบุ') + '</td>' +
                '<td>' + escapeHtml(d.channel || '') + '</td>' +
                '<td>' + escapeHtml(d.target_cell || '') + '</td>' +
                '<td>' + escapeHtml(d.plan_label || '') + '</td>' +
                '<td class="admin-dir-num">' + formatMoneyCell(d.amount) + '</td>';
            frag.appendChild(tr);
        });
        tbody.replaceChildren();
        tbody.appendChild(frag);
        rows.splice(0, rows.length, ...Array.from(document.querySelectorAll('#fdDonationTableBody tr[data-cat]')));
        listRowsRendered = true;
    }

    function ensureListRowsRendered() {
        if (listRowsRendered) {
            return;
        }
        if (chartsLoaded && FD_STATIC.table_pagination) {
            return;
        }
        const tbody = document.getElementById('fdDonationTableBody');
        if (!tbody || getDonations().length === 0) {
            listRowsRendered = true;
            return;
        }
        renderTableRows(getDonations());
    }
    const noRows = document.getElementById('foundationDashboardNoRows');
    const dateModeEl = document.getElementById('fdDateMode');
    const dateRangeWrapEl = document.getElementById('fdDateRangeWrap');
    const dateFromEl = document.getElementById('fdDateFrom');
    const dateToEl = document.getElementById('fdDateTo');
    const dateMonthEl = document.getElementById('fdDateMonth');
    const dateYearEl = document.getElementById('fdDateYear');
    const dateClearEl = document.getElementById('fdDateClear');
    const dateSummaryEl = document.getElementById('fdDateSummary');
    const listSearchEl = document.getElementById('fdListSearch');
    const listSummaryEl = document.getElementById('fdListSummary');
    const tablePaginationEl = document.getElementById('fdTablePagination');
    const tablePrevEl = document.getElementById('fdTablePrev');
    const tableNextEl = document.getElementById('fdTableNext');
    const tablePageInfoEl = document.getElementById('fdTablePageInfo');

    let activeView = 'charts';
    let chartsLoaded = !!(FD_STATIC.charts_preloaded || (fdDonations && fdDonations.length > 0));
    let tablePage = 1;
    let tablePerPage = Number(FD_STATIC.list_per_page || 50);
    let tablePagination = { page: 1, per_page: tablePerPage, total_rows: 0, total_pages: 1, filtered_sum: 0 };
    let tableLoading = false;
    let searchDebounceTimer = null;

    let activeCat = 'all';
    let searchQuery = '';
    let activeWeekKey = '';
    let dateMode = 'all';
    let dateValue = '';
    let dateFrom = '';
    let dateTo = '';

    let lineChart = null;
    let pieChart = null;
    let barChart = null;
    let chartsInitialized = false;
    let serverFilterCounts = null;

    function ensureChartsInitialized() {
        if (chartsInitialized) {
            return true;
        }
        if (!window.Chart) {
            return false;
        }
        chartsInitialized = true;
        initCharts();
        refreshChartsAndInsights();
        return true;
    }

    function waitForChartsAndInit() {
        if (ensureChartsInitialized()) {
            return;
        }
        let attempts = 0;
        const timer = setInterval(() => {
            attempts += 1;
            if (ensureChartsInitialized() || attempts >= 100) {
                clearInterval(timer);
            }
        }, 50);
    }

    window.drawdreamEnsureDashboardCharts = waitForChartsAndInit;
    window.drawdreamEnsureDashboardList = ensureListRowsRendered;

    const fmtMoney = (n) => Number(n || 0).toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

    function fmtBahtShort(amount) {
        const n = Number(amount || 0);
        if (n >= 1000000) {
            return (n / 1000000).toLocaleString('th-TH', { maximumFractionDigits: 1 }) + ' ล้าน';
        }
        return Math.round(n).toLocaleString('th-TH');
    }

    function shortLabel(text, maxLen) {
        const t = String(text || '').trim();
        if (!t) {
            return 'รายการบริจาค';
        }
        if (t.length <= maxLen) {
            return t;
        }
        return t.slice(0, maxLen - 1) + '…';
    }

    const pad2 = (n) => String(n).padStart(2, '0');
    const todayLocal = () => {
        const d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    };
    const monthLocal = () => {
        const d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1);
    };

    function donationMatchesDate(d) {
        if (activeWeekKey) {
            return (d.week || '') === activeWeekKey;
        }
        if (dateMode === 'all') {
            return true;
        }
        if (dateMode === 'day') {
            if (dateFrom === '' && dateTo === '') {
                return true;
            }
            const day = d.date || '';
            if (day === '') {
                return false;
            }
            const from = dateFrom || dateTo;
            const to = dateTo || dateFrom;
            return day >= from && day <= to;
        }
        if (dateValue === '') {
            return true;
        }
        if (dateMode === 'month') {
            return (d.month || '') === dateValue;
        }
        if (dateMode === 'year') {
            return (d.year || '') === dateValue;
        }
        return true;
    }

    function getFilteredDonations() {
        return getDonations().filter((d) => {
            const catOk = activeCat === 'all' || d.cat === activeCat;
            return catOk && donationMatchesDate(d);
        });
    }

    function analyzeDonations(rows) {
        const sums = { child: 0, project: 0, need: 0 };
        const counts = { child: 0, project: 0, need: 0 };
        const weekKeys = fdWeekMeta.keys || [];
        const weekLabels = fdWeekMeta.labels || [];
        const weeklySums = weekKeys.map(() => 0);
        const donationsByWeek = weekKeys.map(() => []);

        rows.forEach((d) => {
            const cat = d.cat;
            const amt = Number(d.amount || 0);
            if (sums[cat] !== undefined) {
                sums[cat] += amt;
                counts[cat] += 1;
            }
            const wi = weekKeys.indexOf(d.week || '');
            if (wi >= 0) {
                weeklySums[wi] += amt;
                donationsByWeek[wi].push(d);
            }
        });

        const sumTotal = sums.child + sums.project + sums.need;
        const donationCountTotal = counts.child + counts.project + counts.need;
        const avgPerDonation = donationCountTotal > 0 ? sumTotal / donationCountTotal : 0;

        const pieBreakdown = CAT_ORDER.map((key) => ({
            key,
            label: CAT_LABELS[key],
            amount: sums[key],
            count: counts[key],
            pct: sumTotal > 0 ? (sums[key] / sumTotal) * 100 : 0,
            color: CAT_COLORS[key],
        })).sort((a, b) => b.amount - a.amount);

        const pieTop = pieBreakdown[0] || { label: '-', amount: 0, pct: 0, count: 0, key: '' };
        const topByCount = [...pieBreakdown].sort((a, b) => b.count - a.count)[0] || pieTop;

        let peakIdx = 0;
        let peakSum = 0;
        weeklySums.forEach((w, i) => {
            if (w > peakSum) {
                peakSum = w;
                peakIdx = i;
            }
        });
        const peakLabel = weekLabels[peakIdx] || '-';
        const peakWeekKey = weekKeys[peakIdx] || '';
        const peakDonations = [...(donationsByWeek[peakIdx] || [])].sort((a, b) => b.amount - a.amount);
        const peakTop = peakDonations[0] || null;

        let zeroWeeks = 0;
        weeklySums.forEach((w) => {
            if (w <= 0.0001) {
                zeroWeeks += 1;
            }
        });

        let lineTitle = 'แนวโน้มยอดบริจาครายสัปดาห์ (8 สัปดาห์ล่าสุด)';
        let lineInsight = 'ยังไม่มีข้อมูลบริจาคในช่วง 8 สัปดาห์นี้';
        if (sumTotal > 0 && peakSum > 0) {
            lineTitle = 'สัปดาห์ ' + peakLabel + ' มียอดสูงสุด ' + Math.round(peakSum).toLocaleString('th-TH') + ' บาท';
            lineInsight = zeroWeeks >= 5
                ? 'มี ' + zeroWeeks + ' จาก 8 สัปดาห์ที่ยอดเป็น 0 — ยอดส่วนใหญ่มากองในสัปดาห์เดียว'
                : 'เปรียบเทียบยอดรายสัปดาห์เพื่อดูช่วงที่มีการระดมทุนเข้ามา';
            if (peakTop) {
                lineInsight += ' · รายการใหญ่สุด: ' + peakTop.target + ' (' + (CAT_LABELS[peakTop.cat] || peakTop.cat) + ') ' + Math.round(peakTop.amount).toLocaleString('th-TH') + ' บาท';
            }
        } else if (sumTotal > 0) {
            lineInsight = 'ยอดรวม ' + fmtMoney(sumTotal) + ' บาท แต่ไม่มีรายการในช่วง 8 สัปดาห์ล่าสุด';
        }

        let pieTitle = 'สัดส่วนยอดบริจาคตามประเภท';
        let pieInsight = 'ยังไม่มียอดบริจาคในช่วงที่เลือก';
        if (sumTotal > 0) {
            pieTitle = 'เงิน ' + pieTop.pct.toFixed(1) + '% อยู่ที่' + pieTop.label + ' แต่มี ' + pieTop.count + ' รายการ';
            if (topByCount.key && topByCount.key !== pieTop.key) {
                pieInsight = 'ยอดเงินสูงสุดที่' + pieTop.label + ' (' + pieTop.count + ' ครั้ง) · บริจาคบ่อยสุดที่' + topByCount.label + ' (' + topByCount.count + ' ครั้ง)';
            } else {
                pieInsight = pieTop.label + ' ทั้งมียอดและจำนวนครั้งสูงสุด (' + pieTop.count + ' ครั้ง · ' + Math.round(pieTop.amount).toLocaleString('th-TH') + ' บาท)';
            }
        }

        let kpiTrend = '';
        let kpiTrendDir = '';
        if (weeklySums.length >= 2) {
            const last = weeklySums[weeklySums.length - 1];
            const prev = weeklySums[weeklySums.length - 2];
            if (prev > 0) {
                const chg = ((last - prev) / prev) * 100;
                kpiTrend = (chg >= 0 ? '+' : '') + chg.toFixed(1) + '% จากสัปดาห์ก่อน';
                kpiTrendDir = chg >= 0 ? 'up' : 'down';
            } else if (last > 0) {
                kpiTrend = 'สัปดาห์นี้มียอดใหม่';
                kpiTrendDir = 'up';
            }
        }

        return {
            weeklyLabels: weekLabels,
            weeklySums,
            peakIdx,
            peakSum,
            peakLabel,
            peakWeekKey,
            peakTop,
            pieTop,
            topByCount,
            pieBreakdown,
            sumTotal,
            donationCountTotal,
            avgPerDonation,
            lineTitle,
            lineInsight,
            pieTitle,
            pieInsight,
            kpiTrend,
            kpiTrendDir,
            lastWeekSum: weeklySums.length ? weeklySums[weeklySums.length - 1] : 0,
        };
    }

    function formatMonthThai(ym) {
        const p = String(ym || '').split('-');
        if (p.length !== 2) {
            return ym || '';
        }
        const months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        const m = parseInt(p[1], 10);
        const y = parseInt(p[0], 10) + 543;
        return (months[m] || p[1]) + ' ' + y;
    }

    function updateContextKpis(filteredRows) {
        const set = (id, text) => {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = text;
            }
        };
        const period = FD_STATIC.period || {};
        const thisM = period.this_month || {};
        const prevM = period.prev_month || {};
        const sponsorship = FD_STATIC.sponsorship || {};
        const ops = FD_STATIC.ops || {};

        let subSum = 0;
        let subCount = 0;
        (filteredRows || []).forEach((d) => {
            if (d.is_sub) {
                subSum += Number(d.amount || 0);
                subCount += 1;
            }
        });

        const activeSubs = Number(ops.active_sponsors || sponsorship.active || 0);
        set('fdKpiActiveSponsors', activeSubs + ' คน');
        set('fdKpiActiveSponsorsSub', 'เด็กที่มีผู้อุปการะรายรอบอยู่');

        set('fdKpiSubRevenue', fmtMoney(subSum) + ' บาท');
        set('fdKpiSubRevenueSub', subCount > 0
            ? subCount + ' รายการรายรอบ · ตามตัวกรอง'
            : 'ยังไม่มีรายได้รายรอบในช่วงที่เลือก');

        const cancelPct = sponsorship.cancel_pct;
        const denom = Number(sponsorship.denom || 0);
        if (cancelPct === null || cancelPct === undefined || denom <= 0) {
            set('fdKpiCancelRate', '—');
            set('fdKpiCancelRateSub', 'ยังไม่มีข้อมูลแผนรายเดือน');
        } else {
            set('fdKpiCancelRate', String(cancelPct) + '%');
            set('fdKpiCancelRateSub', 'จาก ' + denom + ' สัญญารายเดือน');
        }

        const thisSum = Number(thisM.sum || 0);
        const prevSum = Number(prevM.sum || 0);
        const thisCnt = Number(thisM.count || 0);
        const prevCnt = Number(prevM.count || 0);
        set('fdKpiMonthCompare', fmtMoney(thisSum) + ' บาท');
        set('fdKpiMonthCompareSub', formatMonthThai(thisM.key) + ' · ' + thisCnt + ' รายการ');

        const deltaEl = document.getElementById('fdKpiMonthDelta');
        if (deltaEl) {
            let deltaText = '';
            let deltaClass = 'fd-kpi-delta fd-kpi-delta--flat';
            if (prevSum > 0) {
                const chg = ((thisSum - prevSum) / prevSum) * 100;
                const sign = chg >= 0 ? '+' : '';
                deltaText = sign + chg.toFixed(1) + '% จาก ' + formatMonthThai(prevM.key) + ' (' + fmtMoney(prevSum) + ' บาท · ' + prevCnt + ' รายการ)';
                deltaClass = 'fd-kpi-delta ' + (chg >= 0 ? 'fd-kpi-delta--up' : 'fd-kpi-delta--down');
            } else if (thisSum > 0) {
                deltaText = 'เดือนก่อนยังไม่มียอด · เดือนนี้เริ่มมีรายได้';
                deltaClass = 'fd-kpi-delta fd-kpi-delta--up';
            } else {
                deltaText = 'เดือนนี้และเดือนก่อนยังไม่มียอด';
            }
            deltaEl.textContent = deltaText;
            deltaEl.className = deltaClass;
        }

        const needUndelivered = Number(ops.need_awaiting_delivery || 0);
        set('fdKpiNeedUndelivered', needUndelivered > 0 ? needUndelivered + ' รายการ' : '—');
        set('fdKpiNeedUndeliveredSub', needUndelivered > 0
            ? 'ครบเป้าและชำระค่าบริการแล้ว รอแอดมินจัดซื้อ/จัดส่ง'
            : 'ไม่มีรายการสิ่งของที่รอจัดส่ง');

        const escrow = Number(ops.escrow_pending_baht || 0);
        set('fdKpiEscrow', escrow > 0 ? fmtMoney(escrow) + ' บาท' : '—');
        set('fdKpiEscrowSub', escrow > 0
            ? 'คือยอดบริจาคโครงการที่ชำระค่าบริการแล้ว รอแอดมินโอนให้มูลนิธิ (ไม่รวมรายการสิ่งของ)'
            : 'ไม่มียอดค้างรับ');
    }

    function updateInsightDom(a) {
        const set = (id, text) => {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = text;
            }
        };
        set('fdLineTitle', a.lineTitle);
        set('fdLineInsight', a.lineInsight);
        set('fdPieTitle', a.pieTitle);
        set('fdPieInsight', a.pieInsight);
        set('fdKpiTotal', fmtMoney(a.sumTotal) + ' บาท');
        set('fdKpiCount', String(a.donationCountTotal) + ' รายการ');
        set('fdKpiWeek', fmtMoney(a.lastWeekSum) + ' บาท');
        const weekTrendEl = document.getElementById('fdKpiWeekTrend');
        if (weekTrendEl) {
            weekTrendEl.textContent = a.kpiTrend || '8 สัปดาห์ล่าสุด';
            weekTrendEl.className = 'fd-kpi-card__sub';
            if (a.kpiTrendDir === 'up') {
                weekTrendEl.classList.add('fd-kpi-trend--up');
            } else if (a.kpiTrendDir === 'down') {
                weekTrendEl.classList.add('fd-kpi-trend--down');
            }
        }
        set('fdKpiTotalSub', activeWeekKey ? 'กรองตามสัปดาห์ยอดสูง' : 'ตามตัวกรองวันที่');

        const jumpPeak = document.getElementById('fdJumpPeakWeek');
        if (jumpPeak) {
            if (a.peakSum > 0 && a.peakWeekKey) {
                jumpPeak.style.display = '';
                jumpPeak.dataset.filterWeek = a.peakWeekKey;
                jumpPeak.dataset.filterCat = 'all';
            } else {
                jumpPeak.style.display = 'none';
            }
        }

        const side = document.getElementById('fdPieSideCards');
        if (side) {
            if (a.sumTotal <= 0) {
                side.innerHTML = '<div class="fd-pie-side-card"><div class="fd-pie-side-card__label">สรุปตามหมวด</div><div class="fd-pie-side-card__sub">ยังไม่มียอดบริจาคในช่วงที่เลือก</div></div>';
            } else {
                let html = '<div class="fd-pie-side-card">';
                html += '<div class="fd-pie-side-card__label">หมวดยอดเงินสูงสุด</div>';
                html += '<div class="fd-pie-side-card__value">' + a.pieTop.label + ' (' + a.pieTop.pct.toFixed(1) + '%)</div>';
                html += '<div class="fd-pie-side-card__sub">' + fmtMoney(a.pieTop.amount) + ' บาท · ' + a.pieTop.count + ' รายการ</div></div>';

                if (a.topByCount && a.topByCount.key && a.topByCount.count > 0) {
                    html += '<div class="fd-pie-side-card">';
                    html += '<div class="fd-pie-side-card__label">บริจาคบ่อยที่สุด</div>';
                    html += '<div class="fd-pie-side-card__value">' + a.topByCount.label + '</div>';
                    html += '<div class="fd-pie-side-card__sub">' + a.topByCount.count + ' ครั้ง · ' + fmtMoney(a.topByCount.amount) + ' บาท</div></div>';
                }

                html += '<div class="fd-pie-side-card"><div class="fd-pie-side-card__label">สรุปยอดตามหมวด</div><ul class="fd-pie-side-breakdown">';
                (a.pieBreakdown || []).forEach((row) => {
                    html += '<li><span class="fd-pie-side-breakdown__dot" style="background:' + row.color + ';"></span>';
                    html += '<span>' + row.label + ': ' + fmtMoney(row.amount) + ' บาท (' + row.count + ' ครั้ง)</span></li>';
                });
                html += '</ul></div>';
                side.innerHTML = html;
            }
        }
        updateContextKpis(getFilteredDonations());
    }

    function updateCharts(a) {
        if (!window.Chart) {
            return;
        }
        const pointRadii = a.weeklySums.map((_, i) => (i === a.peakIdx && a.peakSum > 0 ? 7 : 3));
        const pointBg = a.weeklySums.map((_, i) => (i === a.peakIdx && a.peakSum > 0 ? '#E74C3C' : '#4A5BA8'));

        if (lineChart) {
            lineChart.data.labels = a.weeklyLabels;
            lineChart.data.datasets[0].data = a.weeklySums;
            lineChart.data.datasets[0].pointRadius = pointRadii;
            lineChart.data.datasets[0].pointBackgroundColor = pointBg;
            lineChart.update();
        }

        const pieValues = CAT_ORDER.map((k) => {
            const row = a.pieBreakdown.find((b) => b.key === k);
            return row ? row.amount : 0;
        });
        const pieLabels = CAT_ORDER.map((k) => CAT_LABELS[k]);

        if (pieChart) {
            pieChart.data.datasets[0].data = pieValues;
            pieChart.update();
        }

        if (barChart) {
            barChart.data.datasets[0].data = pieValues;
            barChart.data.datasets[1].data = CAT_ORDER.map((k) => {
                const row = a.pieBreakdown.find((b) => b.key === k);
                return row ? row.count : 0;
            });
            // ยอดเงินกับจำนวนครั้งคนละสเกล — แยนแกน Y ไม่ให้แท่งจำนวนครั้งหาย
            const maxAmount = Math.max(...pieValues, 0);
            const countValues = barChart.data.datasets[1].data;
            const maxCount = Math.max(...countValues.map((v) => Number(v || 0)), 0);
            barChart.options.scales = {
                y: {
                    beginAtZero: true,
                    position: 'left',
                    ticks: {
                        callback: (v) => Number(v).toLocaleString('th-TH'),
                    },
                    suggestedMax: maxAmount > 0 ? maxAmount * 1.1 : undefined,
                },
                yCount: {
                    beginAtZero: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: {
                        stepSize: maxCount > 0 ? Math.max(1, Math.ceil(maxCount / 5)) : 1,
                        callback: (v) => String(Math.round(Number(v))),
                    },
                    suggestedMax: maxCount > 0 ? maxCount + 1 : undefined,
                },
            };
            barChart.data.datasets[0].yAxisID = 'y';
            barChart.data.datasets[1].yAxisID = 'yCount';
            barChart.update();
        }
    }

    function refreshChartsAndInsights() {
        const filtered = getFilteredDonations();
        const analysis = analyzeDonations(filtered);
        updateInsightDom(analysis);
        updateCharts(analysis);
    }

    const formatDayLabel = (ymd) => {
        const p = (ymd || '').split('-');
        if (p.length !== 3) {
            return ymd;
        }
        return p[2] + '/' + p[1] + '/' + p[0];
    };

    const matchesDateRow = (row) => {
        if (activeWeekKey) {
            return (row.getAttribute('data-donate-week') || '') === activeWeekKey;
        }
        if (dateMode === 'all') {
            return true;
        }
        if (dateMode === 'day') {
            if (dateFrom === '' && dateTo === '') {
                return true;
            }
            const d = row.getAttribute('data-donate-date') || '';
            if (d === '') {
                return false;
            }
            const from = dateFrom || dateTo;
            const to = dateTo || dateFrom;
            return d >= from && d <= to;
        }
        if (dateValue === '') {
            return true;
        }
        if (dateMode === 'month') {
            return (row.getAttribute('data-donate-month') || '') === dateValue;
        }
        if (dateMode === 'year') {
            return (row.getAttribute('data-donate-year') || '') === dateValue;
        }
        return true;
    };

    const matchesSearchRow = (row) => {
        if (searchQuery === '') {
            return true;
        }
        const blob = (row.getAttribute('data-search') || '').toLowerCase();
        return blob.includes(searchQuery.toLowerCase());
    };

    const matchesSearchDonation = (d) => {
        if (searchQuery === '') {
            return true;
        }
        const blob = String(d.search || '').toLowerCase();
        return blob.includes(searchQuery.toLowerCase());
    };

    function updateFilterCatButtons(counts) {
        if (!counts || typeof counts !== 'object') {
            return;
        }
        buttons.forEach((btn) => {
            const cat = btn.getAttribute('data-filter-cat') || '';
            const label = FILTER_CAT_BUTTON_LABELS[cat];
            if (!label) {
                return;
            }
            const n = Number(counts[cat] ?? 0);
            btn.textContent = label + ' (' + n.toLocaleString('th-TH') + ')';
        });
    }

    function computeFilterCountsFromDonations(donations) {
        const counts = { all: 0, child: 0, project: 0, need: 0 };
        (donations || []).forEach((d) => {
            counts.all += 1;
            const cat = d.cat || '';
            if (Object.prototype.hasOwnProperty.call(counts, cat)) {
                counts[cat] += 1;
            }
        });
        return counts;
    }

    function hasActiveListFilters() {
        if (activeWeekKey) {
            return true;
        }
        if (searchQuery !== '') {
            return true;
        }
        if (dateMode === 'day' && (dateFrom !== '' || dateTo !== '')) {
            return true;
        }
        if (dateMode === 'month' && dateValue !== '') {
            return true;
        }
        if (dateMode === 'year' && dateValue !== '') {
            return true;
        }
        return false;
    }

    function syncFilterCatButtonsClientSide() {
        if (activeView === 'list' && chartsLoaded && FD_STATIC.table_pagination) {
            return;
        }
        if (!hasActiveListFilters()) {
            if (serverFilterCounts) {
                updateFilterCatButtons(serverFilterCounts);
            }
            return;
        }
        const filtered = getDonations().filter((d) => donationMatchesDate(d) && matchesSearchDonation(d));
        updateFilterCatButtons(computeFilterCountsFromDonations(filtered));
    }

    const updateListSummary = (visible, sumVisible) => {
        if (!listSummaryEl) {
            return;
        }
        if (activeView === 'list' && chartsLoaded && FD_STATIC.table_pagination) {
            const pg = tablePagination;
            if (pg.total_rows === 0) {
                listSummaryEl.textContent = 'ไม่พบรายการตามเงื่อนไขที่เลือก';
                return;
            }
            const period = formatDateSummary();
            let text = 'หน้า <strong>' + pg.page + '</strong> / ' + pg.total_pages
                + ' · แสดง <strong>' + visible + '</strong> รายการในหน้านี้'
                + ' · รวมในหน้านี้ <strong>' + fmtMoney(sumVisible) + '</strong> บาท'
                + ' · ทั้งหมด <strong>' + pg.total_rows.toLocaleString('th-TH') + '</strong> รายการ';
            if (period !== '') {
                text += ' · ' + period;
            }
            listSummaryEl.innerHTML = text;
            return;
        }
        if (rows.length === 0) {
            listSummaryEl.textContent = '';
            return;
        }
        if (visible === 0) {
            listSummaryEl.innerHTML = 'ไม่พบรายการตามเงื่อนไขที่เลือก';
            return;
        }
        const period = formatDateSummary();
        let text = 'แสดง <strong>' + visible + '</strong> รายการ · รวม <strong>' + fmtMoney(sumVisible) + '</strong> บาท';
        if (period !== '') {
            text += ' · ' + period;
        }
        const totalAll = Number(FD_STATIC.total_donation_count || 0);
        const chartsLimit = Number(FD_STATIC.charts_limit || 500);
        const listLoaded = getDonations().length;
        if (totalAll > listLoaded) {
            text += ' · กราฟใช้ ' + listLoaded + ' รายการล่าสุดจากทั้งหมด ' + totalAll.toLocaleString('th-TH') + ' รายการ';
        } else if (listLoaded >= chartsLimit) {
            text += ' · กราฟใช้สูงสุด ' + chartsLimit + ' รายการล่าสุด';
        }
        listSummaryEl.innerHTML = text;
    };

    function updateTablePaginationUi() {
        if (!tablePaginationEl) {
            return;
        }
        const show = activeView === 'list' && chartsLoaded && FD_STATIC.table_pagination;
        tablePaginationEl.hidden = !show;
        if (!show) {
            return;
        }
        const pg = tablePagination;
        if (tablePageInfoEl) {
            tablePageInfoEl.textContent = 'หน้า ' + pg.page + ' / ' + pg.total_pages
                + ' (ทั้งหมด ' + pg.total_rows.toLocaleString('th-TH') + ' รายการ)';
        }
        if (tablePrevEl) {
            tablePrevEl.disabled = tableLoading || pg.page <= 1;
        }
        if (tableNextEl) {
            tableNextEl.disabled = tableLoading || pg.page >= pg.total_pages;
        }
    }

    function buildTableFilterParams() {
        const params = new URLSearchParams();
        params.set('mode', 'table');
        params.set('page', String(tablePage));
        params.set('per_page', String(tablePerPage));
        params.set('cat', activeCat || 'all');
        if (activeWeekKey) {
            params.set('week', activeWeekKey);
            return params;
        }
        params.set('date_mode', dateMode || 'all');
        if (dateMode === 'day') {
            if (dateFrom) {
                params.set('date_from', dateFrom);
            }
            if (dateTo) {
                params.set('date_to', dateTo);
            }
        } else if (dateMode === 'month' && dateValue) {
            params.set('date_month', dateValue);
        } else if (dateMode === 'year' && dateValue) {
            params.set('date_year', dateValue);
        }
        if (searchQuery) {
            params.set('q', searchQuery);
        }
        return params;
    }

    function applyTableRowsDisplay() {
        let visible = 0;
        let sumVisible = 0;
        rows.forEach((row) => {
            row.style.display = '';
            visible += 1;
            sumVisible += Number(row.getAttribute('data-amount') || 0);
        });
        if (noRows) {
            noRows.style.display = visible === 0 ? '' : 'none';
        }
        setActive(activeCat);
        if (dateSummaryEl && activeView === 'list') {
            const period = formatDateSummary();
            dateSummaryEl.textContent = period !== '' ? period : '';
        }
        updateListSummary(visible, sumVisible);
        updateTablePaginationUi();
    }

    window.drawdreamLoadDashboardTablePage = function (page) {
        if (!chartsLoaded || !FD_STATIC.table_pagination) {
            return Promise.resolve(false);
        }
        tablePage = Math.max(1, Number(page || 1));
        const base = window.FD_DATA_URL || 'foundation_dashboard_data.php';
        const params = buildTableFilterParams();
        tableLoading = true;
        updateTablePaginationUi();
        return fetch(base + '?' + params.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((res) => res.json())
            .then((data) => {
                tableLoading = false;
                if (!data || !data.ok || data.mode !== 'table') {
                    if (window.drawdreamAlert) {
                        window.drawdreamAlert('โหลดตารางบริจาคไม่สำเร็จ กรุณาลองใหม่', 'error');
                    }
                    updateTablePaginationUi();
                    return false;
                }
                tablePagination = data.pagination || tablePagination;
                tablePage = Number(tablePagination.page || tablePage);
                listRowsRendered = false;
                renderTableRows(data.donations || []);
                applyTableRowsDisplay();
                if (data.filter_counts) {
                    serverFilterCounts = data.filter_counts;
                    updateFilterCatButtons(data.filter_counts);
                }
                return true;
            })
            .catch(() => {
                tableLoading = false;
                if (window.drawdreamAlert) {
                    window.drawdreamAlert('โหลดตารางบริจาคไม่สำเร็จ กรุณาตรวจสอบอินเทอร์เน็ต', 'error');
                }
                updateTablePaginationUi();
                return false;
            });
    };

    const formatDateSummary = () => {
        if (activeWeekKey) {
            return 'สัปดาห์ยอดสูง (8 สัปดาห์ล่าสุด)';
        }
        if (dateMode === 'all') {
            return '';
        }
        if (dateMode === 'day') {
            if (dateFrom === '' && dateTo === '') {
                return '';
            }
            const from = dateFrom || dateTo;
            const to = dateTo || dateFrom;
            if (from === to) {
                return 'วันที่ ' + formatDayLabel(from);
            }
            return 'ช่วง ' + formatDayLabel(from) + ' – ' + formatDayLabel(to);
        }
        if (dateValue === '') {
            return '';
        }
        if (dateMode === 'month') {
            const p = dateValue.split('-');
            if (p.length === 2) {
                return 'เดือน ' + p[1] + '/' + p[0];
            }
        }
        if (dateMode === 'year') {
            return 'ปี ' + dateValue + ' (พ.ศ. ' + (parseInt(dateValue, 10) + 543) + ')';
        }
        return '';
    };

    const syncDateInputsVisibility = () => {
        if (!dateModeEl) {
            return;
        }
        const mode = dateModeEl.value || 'all';
        if (dateRangeWrapEl) {
            dateRangeWrapEl.style.display = mode === 'day' ? 'flex' : 'none';
        }
        if (dateMonthEl) {
            dateMonthEl.style.display = mode === 'month' ? '' : 'none';
        }
        if (dateYearEl) {
            dateYearEl.style.display = mode === 'year' ? '' : 'none';
        }
        if (dateClearEl) {
            dateClearEl.style.display = mode === 'all' && !activeWeekKey ? 'none' : '';
        }
    };

    const readDateFilter = () => {
        if (!dateModeEl) {
            dateMode = 'all';
            dateValue = '';
            return;
        }
        dateMode = dateModeEl.value || 'all';
        if (dateMode === 'day') {
            dateValue = '';
            let from = dateFromEl ? (dateFromEl.value || '') : '';
            let to = dateToEl ? (dateToEl.value || '') : '';
            if (from !== '' && to !== '' && to < from) {
                const swap = from;
                from = to;
                to = swap;
                if (dateFromEl) {
                    dateFromEl.value = from;
                }
                if (dateToEl) {
                    dateToEl.value = to;
                }
            }
            dateFrom = from;
            dateTo = to;
        } else if (dateMode === 'month' && dateMonthEl) {
            dateFrom = '';
            dateTo = '';
            dateValue = dateMonthEl.value || '';
        } else if (dateMode === 'year' && dateYearEl) {
            dateValue = dateYearEl.value || '';
        } else {
            dateValue = '';
            dateFrom = '';
            dateTo = '';
        }
    };

    const setActive = (cat) => {
        buttons.forEach((btn) => {
            const isActive = btn.getAttribute('data-filter-cat') === cat;
            btn.classList.toggle('admin-dir-btn--primary', isActive);
            btn.classList.toggle('admin-dir-btn--ghost', !isActive);
        });
    };

    const applyFilter = () => {
        if (activeView === 'list' && chartsLoaded && FD_STATIC.table_pagination) {
            tablePage = 1;
            window.drawdreamLoadDashboardTablePage(1);
            refreshChartsAndInsights();
            return;
        }
        let visible = 0;
        let sumVisible = 0;
        rows.forEach((row) => {
            const rowCat = row.getAttribute('data-cat') || '';
            const catOk = activeCat === 'all' || rowCat === activeCat;
            const dateOk = matchesDateRow(row);
            const searchOk = matchesSearchRow(row);
            const show = catOk && dateOk && searchOk;
            row.style.display = show ? '' : 'none';
            if (show) {
                visible += 1;
                sumVisible += Number(row.getAttribute('data-amount') || 0);
            }
        });
        if (noRows) {
            noRows.style.display = rows.length > 0 && visible === 0 ? '' : 'none';
        }
        setActive(activeCat);
        if (dateSummaryEl) {
            const period = formatDateSummary();
            if (period === '') {
                dateSummaryEl.textContent = rows.length > 0 ? 'แสดงทุกวันที่ (กราฟสูงสุด ' + getDonations().length + ' รายการล่าสุด)' : '';
            } else {
                dateSummaryEl.textContent = period + ' · แสดง ' + visible + ' รายการ';
            }
        }
        updateListSummary(visible, sumVisible);
        syncFilterCatButtonsClientSide();
        refreshChartsAndInsights();
    };

    const showFeaturePanel = (cat) => {
        featurePanels.forEach((panel) => {
            const pCat = panel.getAttribute('data-feature-panel') || '';
            panel.style.display = pCat === cat ? '' : 'none';
        });
    };

    const dateFilterBar = document.getElementById('fdDateFilterBar');
    const dateFilterAnchorCharts = document.getElementById('fdDateFilterAnchorCharts');
    const dateFilterAnchorList = document.getElementById('fdDateFilterAnchorList');

    const placeDateFilter = (view) => {
        if (!dateFilterBar) {
            return;
        }
        const anchor = view === 'list' ? dateFilterAnchorList : dateFilterAnchorCharts;
        if (anchor && dateFilterBar.parentElement !== anchor) {
            anchor.appendChild(dateFilterBar);
        }
    };

    const setActiveView = (view) => {
        activeView = view === 'list' ? 'list' : 'charts';
        const showCharts = activeView === 'charts';
        placeDateFilter(view);
        if (!showCharts) {
            if (chartsLoaded && FD_STATIC.table_pagination) {
                window.drawdreamLoadDashboardTablePage(tablePage);
            } else {
                ensureListRowsRendered();
                applyFilter();
            }
        }
        if (showCharts && !document.body.classList.contains('fd-dash-collapsed')) {
            waitForChartsAndInit();
        }
        if (chartsView) {
            chartsView.style.display = showCharts ? '' : 'none';
        }
        if (listView) {
            listView.style.display = showCharts ? 'none' : '';
        }
        tabButtons.forEach((btn) => {
            const active = btn.getAttribute('data-view-tab') === activeView;
            btn.classList.toggle('admin-dir-btn--primary', active);
            btn.classList.toggle('admin-dir-btn--analytics', !active);
        });
        updateTablePaginationUi();
    };

    function jumpToList(cat, weekKey) {
        activeCat = cat || 'all';
        activeWeekKey = weekKey || '';
        if (weekKey) {
            dateMode = 'all';
            if (dateModeEl) {
                dateModeEl.value = 'all';
            }
            syncDateInputsVisibility();
        }
        setActiveView('list');
        if (activeCat !== 'all' && ['child', 'project', 'need'].includes(activeCat)) {
            showFeaturePanel(activeCat);
        }
        if (listView) {
            listView.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function initCharts() {
        const weeklyCtx = document.getElementById('foundationWeeklyTrendChart');
        if (weeklyCtx) {
            lineChart = new Chart(weeklyCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'ยอดบริจาค (บาท)',
                        data: [],
                        borderColor: '#4A5BA8',
                        backgroundColor: 'rgba(74,91,168,.15)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            ticks: {
                                callback: (v) => Number(v).toLocaleString('th-TH'),
                            },
                        },
                    },
                },
            });
        }

        const pieCtx = document.getElementById('foundationCategoryPieChart');
        if (pieCtx) {
            pieChart = new Chart(pieCtx, {
                type: 'pie',
                data: {
                    labels: CAT_ORDER.map((k) => CAT_LABELS[k]),
                    datasets: [{
                        data: [0, 0, 0],
                        backgroundColor: CAT_ORDER.map((k) => CAT_COLORS[k]),
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => {
                                    const value = Number(ctx.raw || 0);
                                    const total = (ctx.dataset.data || []).reduce((s, n) => s + Number(n || 0), 0);
                                    const pct = total > 0 ? (value / total) * 100 : 0;
                                    return ctx.label + ': ' + value.toLocaleString('th-TH') + ' บาท (' + pct.toFixed(1) + '%)';
                                },
                            },
                        },
                    },
                },
            });
        }

        const barCtx = document.getElementById('foundationCategoryBarChart');
        if (barCtx) {
            barChart = new Chart(barCtx, {
                type: 'bar',
                data: {
                    labels: CAT_ORDER.map((k) => CAT_LABELS[k]),
                    datasets: [
                        {
                            label: 'ยอดเงิน (บาท)',
                            data: [0, 0, 0],
                            backgroundColor: 'rgba(74,91,168,.75)',
                            yAxisID: 'y',
                        },
                        {
                            label: 'จำนวนครั้ง',
                            data: [0, 0, 0],
                            backgroundColor: 'rgba(245,158,11,.75)',
                            yAxisID: 'yCount',
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            position: 'left',
                        },
                        yCount: {
                            beginAtZero: true,
                            position: 'right',
                            grid: { drawOnChartArea: false },
                        },
                    },
                },
            });
        }
    }

    if (dateFromEl && !dateFromEl.value) {
        dateFromEl.value = todayLocal();
    }
    if (dateMonthEl && !dateMonthEl.value) {
        dateMonthEl.value = monthLocal();
    }

    if (dateModeEl) {
        dateModeEl.addEventListener('change', () => {
            activeWeekKey = '';
            const mode = dateModeEl.value || 'all';
            if (mode === 'day' && dateFromEl && !dateFromEl.value) {
                dateFromEl.value = todayLocal();
            }
            if (mode === 'month' && dateMonthEl && !dateMonthEl.value) {
                dateMonthEl.value = monthLocal();
            }
            syncDateInputsVisibility();
            readDateFilter();
            applyFilter();
        });
    }
    [dateFromEl, dateToEl, dateMonthEl, dateYearEl].forEach((el) => {
        if (!el) {
            return;
        }
        el.addEventListener('change', () => {
            activeWeekKey = '';
            readDateFilter();
            applyFilter();
        });
    });
    if (dateClearEl) {
        dateClearEl.addEventListener('click', () => {
            activeWeekKey = '';
            if (dateModeEl) {
                dateModeEl.value = 'all';
            }
            dateMode = 'all';
            dateValue = '';
            dateFrom = '';
            dateTo = '';
            if (dateFromEl) {
                dateFromEl.value = '';
            }
            if (dateToEl) {
                dateToEl.value = '';
            }
            syncDateInputsVisibility();
            applyFilter();
        });
    }
    syncDateInputsVisibility();

    if (listSearchEl) {
        listSearchEl.addEventListener('input', () => {
            searchQuery = (listSearchEl.value || '').trim();
            if (searchDebounceTimer) {
                clearTimeout(searchDebounceTimer);
            }
            searchDebounceTimer = setTimeout(() => {
                applyFilter();
            }, 300);
        });
    }

    if (tablePrevEl) {
        tablePrevEl.addEventListener('click', () => {
            if (tablePage > 1) {
                window.drawdreamLoadDashboardTablePage(tablePage - 1);
            }
        });
    }
    if (tableNextEl) {
        tableNextEl.addEventListener('click', () => {
            if (tablePage < tablePagination.total_pages) {
                window.drawdreamLoadDashboardTablePage(tablePage + 1);
            }
        });
    }

    buttons.forEach((btn) => {
        btn.addEventListener('click', () => {
            activeCat = btn.getAttribute('data-filter-cat') || 'all';
            applyFilter();
        });
    });

    tabButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            setActiveView(btn.getAttribute('data-view-tab') || 'charts');
        });
    });

    document.querySelectorAll('[data-jump-list]').forEach((link) => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            jumpToList(link.getAttribute('data-filter-cat') || 'all', link.getAttribute('data-filter-week') || '');
        });
    });

    updateContextKpis(getDonations());
    if (window.FD_FILTER_COUNTS) {
        applyFilterCountsDom(window.FD_FILTER_COUNTS);
    }
    applyFilter();
    setActiveView('charts');
    showFeaturePanel('child');

    function formatSummaryMoney(n) {
        return Number(n || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function applySummaryDom(summary, titles) {
        if (!summary) {
            return;
        }
        const set = (id, text) => {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = text;
            }
        };
        set('fdSummaryChildAmt', formatSummaryMoney(summary.sum_child));
        set('fdSummaryProjectAmt', formatSummaryMoney(summary.sum_project));
        set('fdSummaryNeedAmt', formatSummaryMoney(summary.sum_need));
        set('fdSummaryTotalAmt', formatSummaryMoney(summary.sum_total));
        set('fdSummaryDonationCnt', String(summary.donation_count ?? 0));
        set('fdFeatureChildCnt', String(summary.row_count_child ?? 0));
        set('fdFeatureChildAmt', formatSummaryMoney(summary.sum_child));
        if (titles) {
            const pt = document.getElementById('fdPieTitle');
            const pi = document.getElementById('fdPieInsight');
            if (pt && titles.pie_title) {
                pt.textContent = titles.pie_title;
            }
            if (pi && titles.pie_insight) {
                pi.textContent = titles.pie_insight;
            }
        }
        const yearSel = document.getElementById('fdDateYear');
        if (yearSel && Array.isArray(window.FD_DONATION_YEARS) && window.FD_DONATION_YEARS.length > 0) {
            const cur = yearSel.value;
            yearSel.replaceChildren();
            window.FD_DONATION_YEARS.forEach((y) => {
                const opt = document.createElement('option');
                const yr = Number(y);
                opt.value = String(yr);
                opt.textContent = String(yr + 543) + ' (' + yr + ')';
                yearSel.appendChild(opt);
            });
            if (cur) {
                yearSel.value = cur;
            }
        }
    }

    function applyFilterCountsDom(filterCounts) {
        if (filterCounts) {
            serverFilterCounts = filterCounts;
            updateFilterCatButtons(filterCounts);
        }
    }

    function renderOpsPanel(groups) {
        const panel = document.getElementById('fdOpsDynamic');
        const loading = document.getElementById('fdOpsLoading');
        if (!panel) {
            return;
        }
        if (loading) {
            loading.hidden = true;
            loading.setAttribute('aria-hidden', 'true');
        }
        panel.setAttribute('aria-busy', 'false');
        panel.replaceChildren();
        (groups || []).forEach((group) => {
            const gKey = String(group.key || '');
            const wrap = document.createElement('div');
            wrap.className = 'fd-ops-group fd-ops-group--' + gKey;
            const head = document.createElement('div');
            head.className = 'fd-ops-group__head';
            head.innerHTML = '<span class="fd-ops-group__dot" aria-hidden="true"></span>' + escapeHtml(group.title || '');
            wrap.appendChild(head);
            const chips = document.createElement('div');
            chips.className = 'fd-ops-chips';
            (group.items || []).forEach((item) => {
                const cnt = Number(item.count || 0);
                let chipClass = 'fd-ops-chip';
                if (cnt <= 0) {
                    chipClass += ' fd-ops-chip--idle';
                } else if (item.urgent) {
                    chipClass += ' fd-ops-chip--warn';
                } else {
                    chipClass += ' fd-ops-chip--ok';
                }
                const a = document.createElement('a');
                a.className = chipClass;
                a.href = String(item.href || '#');
                a.innerHTML = '<span class="fd-ops-chip__num">' + cnt + '</span><span>' + escapeHtml(item.label || '') + '</span>';
                chips.appendChild(a);
            });
            wrap.appendChild(chips);
            panel.appendChild(wrap);
        });
    }

    function applyBootstrapDom(data) {
        if (!data) {
            return;
        }
        const counts = data.counts || {};
        const set = (id, text) => {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = text;
            }
        };
        if (typeof counts.children === 'number') {
            set('fdCountChildren', String(counts.children));
            set('fdCountChildrenFeature', String(counts.children));
        }
        if (typeof counts.projects === 'number') {
            set('fdCountProjects', String(counts.projects));
        }
        if (typeof counts.need_items === 'number') {
            set('fdCountNeedItems', String(counts.need_items));
        }
        if (typeof counts.active_sponsors === 'number') {
            set('fdCountActiveSponsors', String(counts.active_sponsors));
        }
        if (data.ops && data.ops.groups) {
            renderOpsPanel(data.ops.groups);
            FD_STATIC.ops = {
                active_sponsors: Number(data.ops.active_sponsors || counts.active_sponsors || 0),
                escrow_pending_baht: Number(data.ops.escrow_pending_baht || 0),
                need_awaiting_delivery: Number(data.ops.need_awaiting_delivery || 0),
            };
            window.FD_STATIC = FD_STATIC;
            updateContextKpis(getDonations());
        }
        const todo = data.next_todo;
        const nextSec = document.getElementById('fdNextActionSection');
        if (todo && nextSec) {
            const textEl = document.getElementById('fdNextActionText');
            const btnEl = document.getElementById('fdNextActionBtn');
            if (textEl) {
                textEl.textContent = String(todo.text || '');
            }
            if (btnEl) {
                btnEl.textContent = String(todo.action_label || 'ทำเลย');
                btnEl.href = String(todo.href || '#');
            }
            nextSec.hidden = false;
        }
        const pause = data.pause;
        const pauseText = document.getElementById('fdPauseBannerText');
        const pauseBtn = document.getElementById('fdPauseBannerBtn');
        if (pause && pauseText && pause.summary_text) {
            const base = pauseText.textContent.split('(')[0].trim();
            pauseText.textContent = base + ' (' + pause.summary_text + ') — มูลนิธิจะไม่แสดงต่อสาธารณะและไม่สามารถเพิ่มเด็ก/โครงการ/สิ่งของใหม่ได้จนกว่าจะอัปเดตครบ';
        }
        if (pause && pauseBtn && pause.bulk_href) {
            pauseBtn.href = pause.bulk_href;
            pauseBtn.hidden = false;
        }
        if (data.account_paused) {
            const banner = document.getElementById('fdPauseBanner');
            if (banner) {
                banner.hidden = false;
            }
        }
    }

    window.drawdreamLoadDashboardBootstrap = function () {
        if (!FD_STATIC.bootstrap_via_ajax) {
            return Promise.resolve(true);
        }
        const loading = document.getElementById('fdOpsLoading');
        if (loading) {
            loading.hidden = false;
            loading.setAttribute('aria-hidden', 'false');
        }
        const url = (window.FD_DATA_URL || 'foundation_dashboard_data.php') + '?mode=bootstrap';
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((res) => res.json())
            .then((data) => {
                if (!data || !data.ok || data.mode !== 'bootstrap') {
                    const loading = document.getElementById('fdOpsLoading');
                    const panel = document.getElementById('fdOpsDynamic');
                    if (loading) {
                        loading.hidden = false;
                        loading.textContent = 'อัปเดตตัวเลขไม่สำเร็จ';
                    } else if (panel) {
                        panel.innerHTML = '<div class="fd-ops-loading">อัปเดตตัวเลขไม่สำเร็จ</div>';
                    }
                    return false;
                }
                applyBootstrapDom(data);
                if (loading) {
                    loading.hidden = true;
                    loading.setAttribute('aria-hidden', 'true');
                }
                return true;
            })
            .catch(() => {
                const loading = document.getElementById('fdOpsLoading');
                if (loading) {
                    loading.hidden = false;
                    loading.textContent = 'อัปเดตตัวเลขไม่สำเร็จ';
                }
                return false;
            });
    };

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof window.drawdreamLoadDashboardBootstrap === 'function') {
            window.drawdreamLoadDashboardBootstrap();
        }
        if (FD_STATIC.charts_preloaded || (fdDonations && fdDonations.length > 0)) {
            chartsLoaded = true;
            window.FD_FULL_LOADED = true;
            waitForChartsAndInit();
            return;
        }
        if (FD_STATIC.donations_via_ajax && !window.FD_PRELOAD_PROMISE) {
            window.FD_PRELOAD_PROMISE = window.drawdreamLoadDashboardDonations().then(function (ok) {
                if (ok) {
                    window.FD_FULL_LOADED = true;
                    if (typeof window.drawdreamEnsureDashboardCharts === 'function') {
                        window.drawdreamEnsureDashboardCharts();
                    }
                }
                return ok;
            });
        }
    });

    window.drawdreamLoadDashboardDonations = function () {
        const url = (window.FD_DATA_URL || 'foundation_dashboard_data.php') + '?mode=charts';
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((res) => res.json())
            .then((data) => {
                if (!data || !data.ok) {
                    if (window.drawdreamAlert) {
                        window.drawdreamAlert('โหลดข้อมูลแดชบอร์ดไม่สำเร็จ กรุณาลองใหม่', 'error');
                    } else {
                        alert('โหลดข้อมูลแดชบอร์ดไม่สำเร็จ กรุณาลองใหม่');
                    }
                    return false;
                }
                chartsLoaded = true;
                fdDonations = data.donations || [];
                window.FD_DONATIONS = fdDonations;
                fdWeekMeta = data.week_meta || { labels: [], keys: [] };
                window.FD_WEEK_META = fdWeekMeta;
                window.FD_DONATION_YEARS = data.donation_years || [];
                listRowsRendered = false;
                const tbody = document.getElementById('fdDonationTableBody');
                if (tbody) {
                    tbody.replaceChildren();
                }
                rows.splice(0, rows.length);
                applySummaryDom(data.summary, data.analysis_titles);
                applyFilterCountsDom(data.filter_counts);
                if (data.period_meta) {
                    FD_STATIC.period = data.period_meta;
                    FD_STATIC.total_donation_count = Number(data.period_meta.total_donation_count || 0);
                    window.FD_STATIC = FD_STATIC;
                }
                if (data.sponsorship) {
                    FD_STATIC.sponsorship = data.sponsorship;
                    window.FD_STATIC = FD_STATIC;
                }
                chartsInitialized = false;
                if (lineChart) {
                    lineChart.destroy();
                    lineChart = null;
                }
                if (pieChart) {
                    pieChart.destroy();
                    pieChart = null;
                }
                if (barChart) {
                    barChart.destroy();
                    barChart = null;
                }
                if (typeof window.drawdreamEnsureDashboardCharts === 'function') {
                    window.drawdreamEnsureDashboardCharts();
                }
                updateContextKpis(getDonations());
                applyFilter();
                if (activeView === 'list' && FD_STATIC.table_pagination) {
                    window.drawdreamLoadDashboardTablePage(1);
                }
                return true;
            })
            .catch(() => {
                if (window.drawdreamAlert) {
                    window.drawdreamAlert('โหลดข้อมูลแดชบอร์ดไม่สำเร็จ กรุณาตรวจสอบอินเทอร์เน็ต', 'error');
                } else {
                    alert('โหลดข้อมูลแดชบอร์ดไม่สำเร็จ');
                }
                return false;
            });
    };
})();
