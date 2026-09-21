(() => {
    function requestAd(slot) {
        const unit = slot.querySelector('ins.adsbygoogle');

        // Never request a hidden mobile sidebar or a unit already initialized
        // by a previous Vue update. Vue must finish mounting before this runs.
        if (!unit || unit.dataset.adsbygoogleStatus || unit.dataset.manualRequested
            || unit.getBoundingClientRect().width === 0) {
            return;
        }

        unit.dataset.manualRequested = 'true';
        (window.adsbygoogle = window.adsbygoogle || []).push({});
    }

    function init() {
        // AdSense initializes unfilled <ins> elements in document order.
        document.querySelectorAll('[data-manual-ad]').forEach(requestAd);
    }

    document.addEventListener('amanda:content-ready', init);
    window.addEventListener('resize', init);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
