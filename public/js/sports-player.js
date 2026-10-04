(() => {
    const player = document.getElementById('sports-player');
    if (!player) return;
    const frame = document.getElementById('sports-embed');
    const overlay = document.getElementById('player-overlay');
    const message = document.getElementById('player-message');
    const state = document.getElementById('player-state');
    const feedback = document.getElementById('player-feedback');
    const start = document.getElementById('player-start');
    const stop = document.getElementById('player-stop');
    const theater = document.getElementById('player-theater');
    const fullscreen = document.getElementById('player-fullscreen');
    let timeout;
    let previousOverflow = '';
    const connect = () => {
        clearTimeout(timeout);
        feedback.textContent = '';
        // This URL was validated by the server. Never request a local media proxy.
        const url = new URL(player.dataset.embedUrl);
        if (url.protocol !== 'https:' || url.origin === location.origin) {
            message.textContent = '此來源無法載入，請返回賽事切換來源。';
            return;
        }
        state.textContent = '正在載入原站播放器';
        overlay.hidden = true;
        frame.hidden = false;
        stop.disabled = false;
        frame.src = url.href;
        timeout = setTimeout(() => {
            feedback.textContent = '若播放器沒有出現，請切換來源或使用「原連結」。';
        }, 20000);
    };
    const disconnect = () => {
        clearTimeout(timeout);
        frame.removeAttribute('src');
        frame.hidden = true;
        stop.disabled = true;
        overlay.hidden = false;
        feedback.textContent = '';
        message.textContent = '已停止播放，點選播放可重新載入原站播放器。';
        state.textContent = '已停止';
    };
    frame.addEventListener('load', () => {
        if (!frame.hasAttribute('src')) return;
        clearTimeout(timeout);
        // Cross-origin iframe loading does not prove that the stream is playing.
        state.textContent = '原站播放器';
    });
    start.addEventListener('click', connect);
    document.getElementById('player-retry').addEventListener('click', connect);
    stop.addEventListener('click', () => { disconnect(); start.focus(); });

    const setTheater = (enabled) => {
        if (enabled) {
            previousOverflow = document.body.style.overflow;
            feedback.textContent = '';
        }
        document.body.style.overflow = enabled ? 'hidden' : previousOverflow;
        player.classList.toggle('is-web-fullscreen', enabled);
        theater.setAttribute('aria-pressed', String(enabled));
        theater.textContent = enabled ? '離開網頁全螢幕' : '網頁全螢幕';
        if (enabled) {
            player.setAttribute('role', 'dialog');
            player.setAttribute('aria-modal', 'true');
        } else {
            player.removeAttribute('role');
            player.removeAttribute('aria-modal');
        }
        theater.focus();
    };
    theater.addEventListener('click', () => setTheater(!player.classList.contains('is-web-fullscreen')));
    document.addEventListener('keydown', (event) => {
        if (!player.classList.contains('is-web-fullscreen')) return;
        if (event.key === 'Escape' && !document.fullscreenElement) { event.preventDefault(); setTheater(false); }
        if (event.key === 'Tab') {
            const controls = [...player.querySelectorAll('a, button, iframe')].filter((element) => !element.hidden && !element.disabled && element.getClientRects().length);
            if (event.shiftKey && document.activeElement === controls[0]) { event.preventDefault(); controls.at(-1).focus(); }
            else if (!event.shiftKey && document.activeElement === controls.at(-1)) { event.preventDefault(); controls[0].focus(); }
        }
    });
    if (!player.requestFullscreen) fullscreen.hidden = true;
    fullscreen.addEventListener('click', async () => {
        feedback.textContent = '';
        try {
            if (document.fullscreenElement) await document.exitFullscreen();
            else await player.requestFullscreen();
        } catch { feedback.textContent = '目前無法啟用螢幕全螢幕，可改用「網頁全螢幕」。'; }
    });
    document.addEventListener('fullscreenchange', () => {
        fullscreen.textContent = document.fullscreenElement ? '離開螢幕全螢幕' : '螢幕全螢幕';
        fullscreen.setAttribute('aria-pressed', String(Boolean(document.fullscreenElement)));
    });
    window.addEventListener('pagehide', disconnect);
})();
