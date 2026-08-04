(function () {
    var payload = window.ADMIN_FOUNDATIONS_CHART;
    if (!payload || !Array.isArray(payload.rows) || payload.rows.length === 0) {
        return;
    }

    function whenChartReady(cb) {
        if (typeof Chart !== 'undefined') {
            cb();
            return;
        }
        var tries = 0;
        var timer = setInterval(function () {
            tries += 1;
            if (typeof Chart !== 'undefined' || tries >= 80) {
                clearInterval(timer);
                if (typeof Chart !== 'undefined') {
                    cb();
                }
            }
        }, 25);
    }

    whenChartReady(function () {
        Chart.defaults.animation = false;
        if (Chart.defaults.transitions && Chart.defaults.transitions.active) {
            Chart.defaults.transitions.active.animation.duration = 0;
        }

        var rows = payload.rows;

        function fmtMoney(n) {
            return Number(n || 0).toLocaleString('th-TH', { maximumFractionDigits: 0 });
        }

        function renderSide() {
            var side = document.getElementById('adminFoundationsPieSide');
            if (!side) return;

            var top = payload.top;
            var topByCount = payload.top_by_count;
            if (!top) {
                side.innerHTML = '<div class="admin-foundations-pie-side-card"><div class="admin-foundations-pie-side-card__label">สรุป</div><div class="admin-foundations-pie-side-card__sub">ยังไม่มียอดบริจาค</div></div>';
                return;
            }

            var html = '<div class="admin-foundations-pie-side-card">';
            html += '<div class="admin-foundations-pie-side-card__label">มูลนิธิยอดเงินสูงสุด</div>';
            html += '<div class="admin-foundations-pie-side-card__value">' + top.name + ' (' + top.pct.toFixed(1) + '%)</div>';
            html += '<div class="admin-foundations-pie-side-card__sub">' + fmtMoney(top.amount) + ' บาท · ' + top.count + ' รายการ</div></div>';

            if (topByCount && topByCount.name !== top.name) {
                html += '<div class="admin-foundations-pie-side-card">';
                html += '<div class="admin-foundations-pie-side-card__label">บริจาคบ่อยที่สุด</div>';
                html += '<div class="admin-foundations-pie-side-card__value">' + topByCount.name + '</div>';
                html += '<div class="admin-foundations-pie-side-card__sub">' + topByCount.count + ' ครั้ง · ' + fmtMoney(topByCount.amount) + ' บาท</div></div>';
            }

            html += '<div class="admin-foundations-pie-side-card"><div class="admin-foundations-pie-side-card__label">สรุปตามมูลนิธิ</div><ul class="admin-foundations-pie-breakdown">';
            rows.forEach(function (row) {
                html += '<li><span class="admin-foundations-chart-dot" style="background:' + row.color + ';"></span>';
                html += row.name + ' · ' + fmtMoney(row.amount) + ' บาท (' + row.pct.toFixed(1) + '%)</li>';
            });
            html += '</ul></div>';
            side.innerHTML = html;
        }

        var labels = rows.map(function (r) { return r.name; });
        var amounts = rows.map(function (r) { return r.amount; });
        var counts = rows.map(function (r) { return r.count; });
        var colors = rows.map(function (r) { return r.color; });

        var pieCtx = document.getElementById('adminFoundationsPieChart');
        if (pieCtx) {
            new Chart(pieCtx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: amounts,
                        backgroundColor: colors,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var value = Number(ctx.raw || 0);
                                    var total = (ctx.dataset.data || []).reduce(function (s, n) { return s + Number(n || 0); }, 0);
                                    var pct = total > 0 ? (value / total) * 100 : 0;
                                    return ctx.label + ': ' + value.toLocaleString('th-TH') + ' บาท (' + pct.toFixed(1) + '%)';
                                },
                            },
                        },
                    },
                },
            });
        }

        var barCtx = document.getElementById('adminFoundationsBarChart');
        if (barCtx) {
            var maxAmount = Math.max.apply(null, amounts.concat([0]));
            var maxCount = Math.max.apply(null, counts.concat([0]));
            new Chart(barCtx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'ยอดเงิน (บาท)',
                            data: amounts,
                            backgroundColor: 'rgba(74,91,168,.75)',
                            yAxisID: 'y',
                        },
                        {
                            label: 'จำนวนครั้ง',
                            data: counts,
                            backgroundColor: 'rgba(242,140,136,.75)',
                            yAxisID: 'y1',
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: { position: 'bottom' },
                    },
                    scales: {
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 0,
                                autoSkip: true,
                            },
                        },
                        y: {
                            type: 'linear',
                            position: 'left',
                            suggestedMax: maxAmount > 0 ? maxAmount * 1.15 : 10,
                            ticks: {
                                callback: function (v) { return Number(v).toLocaleString('th-TH'); },
                            },
                        },
                        y1: {
                            type: 'linear',
                            position: 'right',
                            grid: { drawOnChartArea: false },
                            suggestedMax: maxCount > 0 ? maxCount * 1.15 : 5,
                        },
                    },
                },
            });
        }

        renderSide();

        var channelCards = Array.isArray(payload.channel_cards) ? payload.channel_cards : [];
        var channelColors = payload.channel_colors || { child: '#4A5BA8', project: '#22c55e', need: '#f59e0b' };
        var channelLabels = payload.channel_labels || { child: 'เด็ก', project: 'โครงการ', need: 'สิ่งของ' };
        var channelOrder = ['child', 'project', 'need'];

        function buildChannelCardDom(card) {
            var wrap = document.createElement('article');
            wrap.className = 'admin-foundations-channel-card';
            wrap.setAttribute('aria-label', card.name + ' สัดส่วนช่องทางบริจาค');

            var head = document.createElement('h3');
            head.className = 'admin-foundations-channel-card__title';
            head.textContent = card.name;
            head.title = card.name;
            wrap.appendChild(head);

            var chartBox = document.createElement('div');
            chartBox.className = 'admin-foundations-channel-card__chart';
            var canvas = document.createElement('canvas');
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', 'กราฟโดนัท ' + card.name);
            chartBox.appendChild(canvas);
            wrap.appendChild(chartBox);

            var totalEl = document.createElement('p');
            totalEl.className = 'admin-foundations-channel-card__total';
            totalEl.textContent = 'รวม ' + fmtMoney(card.total) + ' บาท';
            wrap.appendChild(totalEl);

            var lines = document.createElement('ul');
            lines.className = 'admin-foundations-channel-card__lines';
            channelOrder.forEach(function (key) {
                var ch = (card.channels && card.channels[key]) ? card.channels[key] : { amount: 0, pct: 0 };
                var amt = Number(ch.amount || 0);
                if (amt <= 0) {
                    return;
                }
                var li = document.createElement('li');
                li.className = 'admin-foundations-channel-card__line';
                li.innerHTML =
                    '<span class="admin-foundations-channel-card__line-dot" style="background:' + (channelColors[key] || '#94a3b8') + ';"></span>' +
                    '<span>' + (channelLabels[key] || key) + ' ' + fmtMoney(amt) + '</span>' +
                    '<span class="admin-foundations-channel-card__line-pct">' + Number(ch.pct || 0).toFixed(1) + '%</span>';
                lines.appendChild(li);
            });
            wrap.appendChild(lines);

            if (card.foundation_id > 0) {
                var link = document.createElement('a');
                link.className = 'admin-foundations-channel-card__link';
                link.href = 'admin_foundation_totals.php?foundation_id=' + card.foundation_id;
                link.textContent = 'ดูยอดมูลนิธิ';
                wrap.appendChild(link);
            }

            return { wrap: wrap, canvas: canvas, card: card };
        }

        function initChannelChart(canvas, card) {
            var data = [];
            var bg = [];
            var lbl = [];
            channelOrder.forEach(function (key) {
                var ch = (card.channels && card.channels[key]) ? card.channels[key] : { amount: 0 };
                var amt = Number(ch.amount || 0);
                if (amt <= 0) {
                    return;
                }
                data.push(amt);
                bg.push(channelColors[key] || '#94a3b8');
                lbl.push(channelLabels[key] || key);
            });
            if (data.length === 0) {
                data.push(1);
                bg.push('#e2e8f0');
                lbl.push('ไม่มีข้อมูล');
            }

            new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: lbl,
                    datasets: [{
                        data: data,
                        backgroundColor: bg,
                        borderWidth: 0,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    cutout: '62%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var value = Number(ctx.raw || 0);
                                    var sum = (ctx.dataset.data || []).reduce(function (s, n) { return s + Number(n || 0); }, 0);
                                    var pct = sum > 0 ? (value / sum) * 100 : 0;
                                    return ctx.label + ': ' + value.toLocaleString('th-TH') + ' บาท (' + pct.toFixed(1) + '%)';
                                },
                            },
                        },
                    },
                },
            });
        }

        function renderChannelCards() {
            var grid = document.getElementById('adminFoundationsChannelGrid');
            if (!grid || channelCards.length === 0) {
                return;
            }

            grid.replaceChildren();
            var built = channelCards.map(buildChannelCardDom);
            built.forEach(function (item) {
                grid.appendChild(item.wrap);
            });

            var idx = 0;
            function paintBatch() {
                var end = Math.min(idx + 4, built.length);
                for (; idx < end; idx += 1) {
                    initChannelChart(built[idx].canvas, built[idx].card);
                }
                if (idx < built.length) {
                    requestAnimationFrame(paintBatch);
                }
            }
            requestAnimationFrame(paintBatch);
        }

        renderChannelCards();
    });
})();
