/**
 * แดชบอร์ดมูลนิธิ — กราฟ + insight + กรองวันที่ (ต้องมี window.FD_DONATIONS, FD_WEEK_META จาก PHP)
 */
(function () {
    const FD_DONATIONS = window.FD_DONATIONS || [];
    const FD_WEEK_META = window.FD_WEEK_META || { labels: [], keys: [] };
    const FD_STATIC = window.FD_STATIC || {};
    const CAT_LABELS = { child: 'เด็ก', project: 'โครงการ', need: 'สิ่งของ' };
    const CAT_COLORS = { child: '#4A5BA8', project: '#22c55e', need: '#f59e0b' };
    const CAT_ORDER = ['child', 'project', 'need'];

    const tabButtons = Array.from(document.querySelectorAll('[data-view-tab]'));
    const chartsView = document.getElementById('foundationDashboardChartsView');
    const listView = document.getElementById('foundationDashboardListView');
    const buttons = Array.from(document.querySelectorAll('[data-filter-cat]'));
    const featurePanels = Array.from(document.querySelectorAll('[data-feature-panel]'));
    const rows = Array.from(document.querySelectorAll('tr[data-cat]'));
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
        return FD_DONATIONS.filter((d) => {
            const catOk = activeCat === 'all' || d.cat === activeCat;
            return catOk && donationMatchesDate(d);
        });
    }

    function analyzeDonations(rows) {
        const sums = { child: 0, project: 0, need: 0 };
        const counts = { child: 0, project: 0, need: 0 };
        const weekKeys = FD_WEEK_META.keys || [];
        const weekLabels = FD_WEEK_META.labels || [];
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

    const updateListSummary = (visible, sumVisible) => {
        if (!listSummaryEl) {
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
        const listLimit = Number(FD_STATIC.list_limit || 500);
        const listLoaded = Number(FD_STATIC.list_loaded || rows.length);
        if (totalAll > listLoaded) {
            text += ' · ตารางแสดง ' + listLoaded + ' รายการล่าสุดจากทั้งหมด ' + totalAll.toLocaleString('th-TH') + ' รายการ';
        } else if (listLoaded >= listLimit) {
            text += ' · แสดงสูงสุด ' + listLimit + ' รายการล่าสุด';
        }
        listSummaryEl.innerHTML = text;
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
                dateSummaryEl.textContent = rows.length > 0 ? 'แสดงทุกวันที่ (สูงสุด ' + rows.length + ' รายการล่าสุด)' : '';
            } else {
                dateSummaryEl.textContent = period + ' · แสดง ' + visible + ' รายการ';
            }
        }
        updateListSummary(visible, sumVisible);
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
        const showCharts = view === 'charts';
        placeDateFilter(view);
        if (chartsView) {
            chartsView.style.display = showCharts ? '' : 'none';
        }
        if (listView) {
            listView.style.display = showCharts ? 'none' : '';
        }
        tabButtons.forEach((btn) => {
            const active = btn.getAttribute('data-view-tab') === view;
            btn.classList.toggle('admin-dir-btn--primary', active);
            btn.classList.toggle('admin-dir-btn--analytics', !active);
        });
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
        applyFilter();
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
                        },
                        {
                            label: 'จำนวนครั้ง',
                            data: [0, 0, 0],
                            backgroundColor: 'rgba(245,158,11,.75)',
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true },
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
            applyFilter();
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

    if (window.Chart) {
        initCharts();
    }
    updateContextKpis(FD_DONATIONS);
    applyFilter();
    setActiveView('charts');
    showFeaturePanel('child');
})();
