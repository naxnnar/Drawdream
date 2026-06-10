/**
 * ผู้อุปการะ: เปิดซองจดหมายจากเด็ก → แสดงกระดาษ airmail ในหน้าเดียวกัน (children_donate.php?letter=1)
 */
(function () {
    const experience = document.getElementById('childMailExperience');
    const scene = document.getElementById('childMailEnvelopeScene');
    if (!experience || !scene || experience.classList.contains('is-opened')) {
        return;
    }

    const envelope = scene.querySelector('.child-mail-envelope');
    const btn = document.getElementById('childMailOpenBtn');
    const cta = document.getElementById('childMailEnvelopeCta');
    const paper = document.getElementById('childMailLetterPaper');

    function setLetterOpenUrl() {
        try {
            const params = new URLSearchParams(window.location.search);
            params.set('view', 'outcome');
            params.set('letter', 'open');
            const next = window.location.pathname + '?' + params.toString();
            window.history.replaceState(null, '', next);
        } catch (e) {
            /* ignore */
        }
    }

    function openLetter() {
        scene.classList.add('is-opening');
        window.setTimeout(function () {
            experience.classList.add('is-opened');
            scene.classList.remove('is-opening');
            if (cta) {
                cta.hidden = true;
            }
            if (paper) {
                paper.hidden = false;
                paper.classList.add('is-visible');
            }
            setLetterOpenUrl();
            if (paper && typeof paper.scrollIntoView === 'function') {
                paper.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }, 520);
    }

    function onKey(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openLetter();
        }
    }

    if (envelope) {
        envelope.addEventListener('click', openLetter);
        envelope.addEventListener('keydown', onKey);
    }
    if (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            openLetter();
        });
    }
})();
