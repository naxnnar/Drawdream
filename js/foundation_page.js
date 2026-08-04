/**
 * foundation.php — carousel, need tabs, image rotators, edit-mode toggle
 */
(function () {
    function initInterestCarousel() {
        var carousel = document.querySelector('.fd-interest-carousel');
        if (!carousel) {
            return;
        }
        var viewport = carousel.querySelector('[data-interest-viewport]');
        var prevBtn = carousel.querySelector('[data-interest-prev]');
        var nextBtn = carousel.querySelector('[data-interest-next]');
        if (!viewport || !prevBtn || !nextBtn) {
            return;
        }

        function getStep() {
            var card = viewport.querySelector('.fd-interest-card');
            if (!card) {
                return 320;
            }
            var grid = viewport.querySelector('.fd-interest-grid');
            var styles = window.getComputedStyle(grid || viewport);
            var gap = parseFloat(styles.columnGap || styles.gap || '24') || 24;
            return card.getBoundingClientRect().width + gap;
        }

        function syncButtons() {
            var maxLeft = viewport.scrollWidth - viewport.clientWidth - 2;
            prevBtn.disabled = viewport.scrollLeft <= 2;
            nextBtn.disabled = viewport.scrollLeft >= maxLeft;
        }

        prevBtn.addEventListener('click', function () {
            viewport.scrollBy({ left: -getStep(), behavior: 'smooth' });
        });
        nextBtn.addEventListener('click', function () {
            viewport.scrollBy({ left: getStep(), behavior: 'smooth' });
        });
        viewport.addEventListener('scroll', syncButtons, { passive: true });
        window.addEventListener('resize', syncButtons);
        syncButtons();
    }

    function initNeedTabsAndCarousels() {
        var tabSection = document.querySelector('.fd-need-tabs-section');
        if (!tabSection) {
            return;
        }
        var carouselStates = [];

        function initCarousel(root) {
            var slides = [].slice.call(root.querySelectorAll('.foundation-slide'));
            var dots = [].slice.call(root.querySelectorAll('.foundation-hero-dot'));
            var prevBtn = root.querySelector('[data-hero-prev]');
            var nextBtn = root.querySelector('[data-hero-next]');
            var n = slides.length;
            var state = {
                root: root,
                slides: slides,
                dots: dots,
                i: 0,
                n: n,
                ms: parseInt(root.getAttribute('data-interval') || '10000', 10),
                timer: null,
            };

            function go(to) {
                if (n <= 1) {
                    return;
                }
                state.i = ((to % n) + n) % n;
                slides.forEach(function (s, j) {
                    var on = j === state.i;
                    s.classList.toggle('is-active', on);
                    s.setAttribute('aria-hidden', on ? 'false' : 'true');
                });
                dots.forEach(function (d, j) {
                    var on = j === state.i;
                    d.classList.toggle('is-active', on);
                    d.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                if (typeof window.drawdreamRestartNeedImgRotators === 'function') {
                    var activeSlide = slides[state.i];
                    if (activeSlide) {
                        window.drawdreamRestartNeedImgRotators(activeSlide);
                    }
                }
            }

            state.go = go;
            state.startAuto = function () {
                state.stopAuto();
                if (n <= 1) {
                    return;
                }
                if (!root.closest('.fd-need-tab-panel') || root.closest('.fd-need-tab-panel.is-active')) {
                    state.timer = setInterval(function () {
                        go(state.i + 1);
                    }, state.ms);
                }
            };
            state.stopAuto = function () {
                if (state.timer) {
                    clearInterval(state.timer);
                    state.timer = null;
                }
            };

            dots.forEach(function (d) {
                d.addEventListener('click', function () {
                    go(parseInt(d.getAttribute('data-go') || '0', 10));
                    state.startAuto();
                });
            });
            if (prevBtn) {
                prevBtn.addEventListener('click', function () {
                    go(state.i - 1);
                    state.startAuto();
                });
            }
            if (nextBtn) {
                nextBtn.addEventListener('click', function () {
                    go(state.i + 1);
                    state.startAuto();
                });
            }

            carouselStates.push(state);
            return state;
        }

        function startActiveCarousel() {
            carouselStates.forEach(function (s) {
                s.stopAuto();
            });
            var activePanel = document.querySelector('.fd-need-tab-panel.is-active');
            if (!activePanel) {
                return;
            }
            var activeRoot = activePanel.querySelector('.foundation-hero-carousel');
            if (!activeRoot) {
                return;
            }
            carouselStates.forEach(function (s) {
                if (s.root === activeRoot) {
                    s.startAuto();
                }
            });
        }

        var dropdown = tabSection.querySelector('[data-need-dropdown]');
        var trigger = tabSection.querySelector('.fd-need-dropdown__trigger');
        var triggerLabel = tabSection.querySelector('.fd-need-dropdown__label');
        var menu = tabSection.querySelector('.fd-need-dropdown__menu');
        var options = [].slice.call(tabSection.querySelectorAll('[data-need-tab]'));
        var panels = [].slice.call(tabSection.querySelectorAll('[data-need-panel]'));

        function setMenuOpen(open) {
            if (!dropdown || !trigger || !menu) {
                return;
            }
            dropdown.classList.toggle('is-open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            menu.hidden = !open;
        }

        function activateTab(id) {
            options.forEach(function (opt) {
                var on = opt.getAttribute('data-need-tab') === id;
                opt.classList.toggle('is-active', on);
                opt.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on && triggerLabel) {
                    triggerLabel.textContent = opt.textContent.trim();
                }
            });
            panels.forEach(function (panel) {
                var on = panel.getAttribute('data-need-panel') === id;
                panel.classList.toggle('is-active', on);
                panel.hidden = !on;
            });
            setMenuOpen(false);
            startActiveCarousel();
        }

        if (trigger && menu) {
            trigger.addEventListener('click', function () {
                setMenuOpen(!dropdown.classList.contains('is-open'));
            });
        }

        options.forEach(function (opt) {
            opt.addEventListener('click', function () {
                activateTab(opt.getAttribute('data-need-tab') || 'open');
            });
        });

        document.addEventListener('click', function (e) {
            if (!dropdown || !dropdown.classList.contains('is-open')) {
                return;
            }
            if (!dropdown.contains(e.target)) {
                setMenuOpen(false);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                setMenuOpen(false);
            }
        });

        [].slice.call(document.querySelectorAll('.foundation-hero-carousel')).forEach(initCarousel);
        startActiveCarousel();

        var hash = window.location.hash || '';
        if (hash && hash.indexOf('#f') === 0) {
            var fid = hash.replace('#f', '').replace(/-(open|done)$/, '');
            var target = document.querySelector('[data-foundation-id="' + fid + '"]');
            if (!target) {
                target = document.querySelector(hash);
            }
            if (target && target.classList.contains('foundation-slide')) {
                var panel = target.closest('[data-need-panel]');
                if (panel) {
                    activateTab(panel.getAttribute('data-need-panel') || 'open');
                }
                var root = target.closest('.foundation-hero-carousel');
                carouselStates.forEach(function (s) {
                    if (s.root === root) {
                        var idx = parseInt(target.getAttribute('data-slide-index') || '0', 10) || 0;
                        s.go(idx);
                        s.startAuto();
                    }
                });
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    function initNeedImgRotators() {
        var rotatorStates = [];

        function rotatorShouldRun(el) {
            if (!el.classList.contains('need-img-rotator--multi')) {
                return false;
            }
            var panel = el.closest('.fd-need-tab-panel');
            if (panel && panel.hidden) {
                return false;
            }
            var slide = el.closest('.foundation-slide');
            if (slide && !slide.classList.contains('is-active')) {
                return false;
            }
            if (el.classList.contains('fc-needlist-showcase-carousel') && window.matchMedia('(min-width: 769px)').matches) {
                return false;
            }
            return true;
        }

        function initNeedImgRotator(el) {
            var slides = [].slice.call(el.querySelectorAll('.need-img-rotator__slide'));
            var dots = [].slice.call(el.querySelectorAll('.need-img-rotator__dot'));
            var n = slides.length;
            if (n <= 1) {
                return;
            }
            var i = 0;
            var ms = parseInt(el.getAttribute('data-interval') || '3000', 10);
            var timer = null;
            var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            function go(to) {
                i = ((to % n) + n) % n;
                slides.forEach(function (s, j) {
                    s.classList.toggle('is-active', j === i);
                });
                dots.forEach(function (d, j) {
                    var on = j === i;
                    d.classList.toggle('is-active', on);
                    d.setAttribute('aria-selected', on ? 'true' : 'false');
                });
            }

            var state = {
                el: el,
                start: function () {
                    state.stop();
                    if (reducedMotion || !rotatorShouldRun(el)) {
                        return;
                    }
                    timer = setInterval(function () {
                        go(i + 1);
                    }, ms);
                },
                stop: function () {
                    if (timer) {
                        clearInterval(timer);
                        timer = null;
                    }
                },
            };

            dots.forEach(function (d) {
                d.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    go(parseInt(d.getAttribute('data-go') || '0', 10));
                    state.start();
                });
            });

            el.addEventListener('mouseenter', state.stop);
            el.addEventListener('mouseleave', state.start);
            el.addEventListener('touchstart', state.stop, { passive: true });
            el.addEventListener('touchend', function () {
                window.setTimeout(state.start, 2500);
            }, { passive: true });

            rotatorStates.push(state);
            state.start();
        }

        window.drawdreamRestartNeedImgRotators = function (scope) {
            rotatorStates.forEach(function (s) {
                if (scope && scope !== s.el && !(scope.contains && scope.contains(s.el))) {
                    return;
                }
                s.stop();
                s.start();
            });
        };

        [].slice.call(document.querySelectorAll('.need-img-rotator--multi')).forEach(initNeedImgRotator);
        window.addEventListener('resize', function () {
            window.drawdreamRestartNeedImgRotators();
        });
    }

    function initFoundationEditNeedToggle() {
        var btn = document.getElementById('toggleEditNeedBtn');
        var section = document.getElementById('my-needlist-section');
        if (!btn || !section) {
            return;
        }
        function setNeedEditMode(on) {
            document.body.classList.toggle('mode-edit-need', on);
            btn.classList.toggle('btn-mode-active', on);
        }
        btn.addEventListener('click', function () {
            var turnOn = !document.body.classList.contains('mode-edit-need');
            setNeedEditMode(turnOn);
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    var cfg = window.FOUNDATION_PAGE || {};
    if (cfg.interestCarousel) {
        initInterestCarousel();
    }
    if (cfg.needSlides) {
        initNeedTabsAndCarousels();
    }
    initNeedImgRotators();
    if (cfg.foundationManage) {
        initFoundationEditNeedToggle();
    }
})();
