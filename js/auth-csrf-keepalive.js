/**
 * Keep auth CSRF token in sync before submit (cookie-based token on server).
 */
(function () {
    var script = document.currentScript;
    var refreshUrl = (script && script.getAttribute('data-csrf-url')) || 'auth/csrf_refresh.php';
    var keepaliveMs = 90000;
    var refreshing = false;

    function getForms() {
        return Array.prototype.slice.call(
            document.querySelectorAll('form[method="POST"], form[method="post"]')
        );
    }

    function setCsrf(form, token) {
        var input = form.querySelector('input[name="csrf"]');
        if (input && token) {
            input.value = token;
        }
    }

    function refreshCsrf(targetForm) {
        return fetch(refreshUrl, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (res) {
                return res.ok ? res.json() : null;
            })
            .then(function (data) {
                if (!data || !data.csrf) {
                    return null;
                }
                if (targetForm) {
                    setCsrf(targetForm, data.csrf);
                } else {
                    getForms().forEach(function (form) {
                        setCsrf(form, data.csrf);
                    });
                }
                return data.csrf;
            })
            .catch(function () {
                return null;
            });
    }

    function submitForm(form, submitter) {
        form.dataset.csrfSubmitOk = '1';
        if (typeof form.requestSubmit === 'function') {
            if (submitter) {
                form.requestSubmit(submitter);
            } else {
                form.requestSubmit();
            }
        } else {
            form.submit();
        }
    }

    function bindForm(form) {
        if (form.dataset.csrfBound === '1') {
            return;
        }
        if (!form.querySelector('input[name="csrf"]')) {
            return;
        }
        form.dataset.csrfBound = '1';

        form.addEventListener('submit', function (ev) {
            if (form.dataset.csrfSubmitOk === '1') {
                form.dataset.csrfSubmitOk = '0';
                return;
            }

            ev.preventDefault();
            if (refreshing) {
                return;
            }
            refreshing = true;

            var submitter = ev.submitter;

            refreshCsrf(form).then(function () {
                refreshing = false;
                submitForm(form, submitter);
            });
        });
    }

    function bindAll() {
        getForms().forEach(bindForm);
    }

    function refreshAll() {
        refreshCsrf(null);
    }

    bindAll();
    refreshAll();

    document.addEventListener('DOMContentLoaded', function () {
        bindAll();
        refreshAll();
    });

    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) {
            refreshAll();
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            refreshAll();
        }
    });

    window.addEventListener('focus', refreshAll);

    setInterval(refreshAll, keepaliveMs);
})();
