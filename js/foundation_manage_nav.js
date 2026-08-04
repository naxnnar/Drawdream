/**
 * มูลนิธิ — ปุ่มย้อนกลับตาม history จริง + ข้ามหน้าแก้ไขหลังบันทึกเมื่อกดย้อนกลับ
 */
(function () {
    'use strict';

    var SKIP_ADD_NEED_KEY = 'drawdream_skip_add_need_on_back';

    function canHistoryBack() {
        return window.history.length > 1;
    }

    function isBackForwardNavigation() {
        try {
            var nav = performance.getEntriesByType('navigation')[0];
            return nav && nav.type === 'back_forward';
        } catch (e) {
            return false;
        }
    }

    function isAddNeedPage() {
        return (window.location.pathname || '').indexOf('foundation_add_need.php') !== -1;
    }

    function bindFoundationBackLinks() {
        document.querySelectorAll('[data-foundation-back]').forEach(function (link) {
            if (link.dataset.foundationBackBound === '1') {
                return;
            }
            link.dataset.foundationBackBound = '1';
            link.addEventListener('click', function (e) {
                if (!canHistoryBack()) {
                    return;
                }
                e.preventDefault();
                window.history.back();
            });
        });
    }

    function cleanLegacyFlashQueryParams() {
        if (!window.history.replaceState) {
            return;
        }
        try {
            var url = new URL(window.location.href);
            var changed = false;
            ['need_created', 'need_updated', 'need_resubmitted'].forEach(function (key) {
                if (url.searchParams.has(key)) {
                    url.searchParams.delete(key);
                    changed = true;
                }
            });
            if (changed) {
                var next = url.pathname
                    + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '')
                    + url.hash;
                window.history.replaceState(null, '', next);
            }
        } catch (e) {
            /* ignore */
        }
    }

    function setupNeedFormSubmitFlag() {
        var form = document.getElementById('needMainForm');
        if (!form || form.dataset.foundationNavBound === '1') {
            return;
        }
        form.dataset.foundationNavBound = '1';
        form.addEventListener('submit', function () {
            try {
                sessionStorage.setItem(SKIP_ADD_NEED_KEY, '1');
            } catch (e) {
                /* ignore */
            }
        });
    }

    function syncAddNeedSkipFlag() {
        if (!isAddNeedPage()) {
            return;
        }
        try {
            if (isBackForwardNavigation()) {
                if (sessionStorage.getItem(SKIP_ADD_NEED_KEY) === '1') {
                    sessionStorage.removeItem(SKIP_ADD_NEED_KEY);
                    if (canHistoryBack()) {
                        window.history.back();
                    }
                }
                return;
            }
            sessionStorage.removeItem(SKIP_ADD_NEED_KEY);
        } catch (e) {
            /* ignore */
        }
    }

    function initFoundationNav() {
        bindFoundationBackLinks();
        cleanLegacyFlashQueryParams();
        setupNeedFormSubmitFlag();
        syncAddNeedSkipFlag();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFoundationNav);
    } else {
        initFoundationNav();
    }

    window.addEventListener('pageshow', function () {
        bindFoundationBackLinks();
        syncAddNeedSkipFlag();
    });
})();
