(() => {
    'use strict';
    const player = document.getElementById('sports-player');
    if (!player) return;
    const el = id => document.getElementById(id);
    const frame = el('sports-embed'), video = el('sports-video');
    const overlay = el('player-overlay'), message = el('player-message'), state = el('player-state'), feedback = el('player-feedback');
    const start = el('player-start'), stop = el('player-stop'), theater = el('player-theater'), fullscreen = el('player-fullscreen');
    const quality = el('player-quality'), mode = el('player-latency'), live = el('player-live'), metrics = el('player-metrics');
    let hls, library, timeout, recoveryTimer, previousOverflow = '';
    let generation = 0, active = false, hasPlayed = false, recoveries = 0, lastMetric = 0;

    const disconnect = (label = '已停止') => {
        active = false;
        generation++;
        clearTimeout(timeout);
        clearTimeout(recoveryTimer);
        if (hls) { hls.destroy(); hls = null; }
        if (video) {
            video.pause();
            video.removeAttribute('src');
            video.load();
            video.hidden = true;
            quality.replaceChildren(new Option('自動', '-1'));
            quality.disabled = true;
            mode.disabled = false;
            live.disabled = true;
            metrics.textContent = '等待影片資訊';
        }
        if (frame) { frame.removeAttribute('src'); frame.hidden = true; }
        stop.disabled = true;
        overlay.hidden = false;
        feedback.textContent = '';
        message.textContent = '點選播放直播，重新連接來源。';
        state.textContent = label;
    };
    const fail = text => { disconnect('無法播放'); message.textContent = text; };
    const playVideo = () => {
        const attempt = generation;
        return video.play().catch(() => {
            if (active && attempt === generation) { disconnect('等待播放'); message.textContent = '瀏覽器未允許播放，請再點一次播放直播。'; }
        });
    };
    const loadLibrary = () => {
        if (window.Hls) return Promise.resolve(window.Hls);
        if (!library) library = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = player.dataset.hlsLibrary;
            script.onload = () => window.Hls ? resolve(window.Hls) : reject(new Error('HLS unavailable'));
            script.onerror = () => { script.remove(); library = null; reject(new Error('HLS unavailable')); };
            document.head.append(script);
        });
        return library;
    };
    const applyMode = () => {
        if (!hls) return;
        hls.config.liveSyncDurationCount = mode.value === 'live' ? 2 : 3;
        hls.config.liveMaxLatencyDurationCount = mode.value === 'live' ? 5 : 6;
        const limit = mode.value === 'save' ? 720 : 1080;
        const eligible = hls.levels.map((level, index) => ({level, index})).filter(({level}) => level.height > 0 && level.height <= limit);
        hls.autoLevelCapping = eligible.length ? eligible.at(-1).index : -1;
        if (mode.value === 'save') { quality.value = '-1'; hls.nextLevel = -1; }
    };
    const goLive = () => {
        const position = hls?.liveSyncPosition ?? (video.seekable.length ? video.seekable.end(video.seekable.length - 1) - 2 : NaN);
        if (Number.isFinite(position)) video.currentTime = Math.max(0, position);
    };
    const updateMetrics = () => {
        if (!active || performance.now() - lastMetric < 1000) return;
        lastMetric = performance.now();
        const resolution = video.videoHeight ? video.videoWidth + ' × ' + video.videoHeight : '等待影片資訊';
        const latency = hls?.latency;
        if (hls?.levels.length === 1 && video.videoHeight) quality.options[1].textContent = video.videoHeight + 'p（唯一畫質）';
        metrics.textContent = resolution + (Number.isFinite(latency) && latency > 0 ? ' · 距直播端點約 ' + Math.round(latency) + ' 秒' : '');
    };
    const connect = async () => {
        disconnect();
        active = true;
        hasPlayed = false;
        recoveries = 0;
        const attempt = generation;
        state.textContent = '正在連接來源';
        stop.disabled = false;
        overlay.hidden = true;
        if (!video) {
            try {
                const url = new URL(player.dataset.embedUrl);
                if (!frame || url.protocol !== 'https:' || url.origin === location.origin) throw new Error('Invalid embed');
                frame.hidden = false;
                frame.src = url.href;
                timeout = setTimeout(() => { feedback.textContent = '若播放器未出現，請切換來源或使用原連結。'; }, 20000);
            } catch { fail('此來源無法載入，請返回賽事切換來源。'); }
            return;
        }
        video.hidden = false;
        video.defaultMuted = false;
        video.muted = false;
        video.volume = 0.7;
        try {
            // Prefer MSE for consistent controls and TS demuxing; native HLS is the fallback.
            if (!window.MediaSource && video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = player.dataset.sourceUrl;
                quality.replaceChildren(new Option('系統自動', '-1'));
                mode.disabled = true;
                feedback.textContent = '此瀏覽器使用系統播放器，自動管理畫質與延遲。';
                playVideo();
                return;
            }
            const Hls = await loadLibrary();
            if (!active || attempt !== generation) return;
            if (!Hls.isSupported()) { fail('這個瀏覽器不支援此直播格式，請使用原連結。'); return; }
            hls = new Hls({
                enableWorker: true, lowLatencyMode: true,
                liveSyncDurationCount: mode.value === 'live' ? 2 : 3,
                liveMaxLatencyDurationCount: mode.value === 'live' ? 5 : 6,
                maxLiveSyncPlaybackRate: 1.05,
                maxBufferLength: 12, maxMaxBufferLength: 24, backBufferLength: 6,
                maxBufferSize: 24 * 1024 * 1024,
                capLevelToPlayerSize: true, capLevelOnFPSDrop: true, maxDevicePixelRatio: 1.5
            });
            const current = hls;
            const valid = () => active && hls === current && attempt === generation;
            current.on(Hls.Events.MANIFEST_PARSED, () => {
                if (!valid()) return;
                quality.replaceChildren(new Option('自動', '-1'));
                current.levels.forEach((level, index) => quality.add(new Option(level.height ? level.height + 'p' : '來源 ' + (index + 1), String(index))));
                quality.disabled = current.levels.length <= 1;
                if (current.levels.length === 1) feedback.textContent = '來源僅提供一種畫質，省流量模式不會降低此來源的流量。';
                applyMode();
                playVideo();
            });
            current.on(Hls.Events.ERROR, (_, data) => {
                if (!valid() || !data.fatal) return;
                if (recoveries++ < 2 && [Hls.ErrorTypes.NETWORK_ERROR, Hls.ErrorTypes.MEDIA_ERROR].includes(data.type)) {
                    state.textContent = '來源中斷，正在重試';
                    clearTimeout(recoveryTimer);
                    recoveryTimer = setTimeout(() => {
                        if (!valid()) return;
                        if (data.type === Hls.ErrorTypes.MEDIA_ERROR) current.recoverMediaError();
                        else current.startLoad(-1);
                    }, recoveries * 1500);
                } else fail('來源暫時無法播放。請重新載入，或返回賽事切換來源。');
            });
            current.attachMedia(video);
            current.loadSource(player.dataset.sourceUrl);
        } catch { if (active && attempt === generation) fail('播放器載入失敗，請重新載入或使用原連結。'); }
    };
    if (frame) frame.addEventListener('load', () => {
        if (!frame.hasAttribute('src')) return;
        clearTimeout(timeout);
        state.textContent = '來源播放器';
    });
    if (video) {
        video.addEventListener('playing', () => {
            if (!active) return;
            hasPlayed = true;
            state.textContent = '播放中';
            live.disabled = false;
            updateMetrics();
        });
        video.addEventListener('waiting', () => { if (active) state.textContent = '緩衝中'; });
        video.addEventListener('timeupdate', updateMetrics);
        video.addEventListener('pause', () => {
            if (!active || !hasPlayed) return;
            // Release all controllers and buffers; resume reconnects at the live edge.
            disconnect('已暫停');
            message.textContent = '已暫停並停止下載。點選播放直播，回到目前直播。';
        });
        video.addEventListener('error', () => { if (active && !hls) fail('來源暫時無法播放，請切換來源。'); });
        quality.addEventListener('change', () => { if (hls) hls.nextLevel = Number(quality.value); });
        mode.addEventListener('change', () => { applyMode(); if (active && mode.value === 'live') goLive(); });
        live.addEventListener('click', () => { goLive(); playVideo(); });
    }
    start.addEventListener('click', connect);
    el('player-retry').addEventListener('click', connect);
    stop.addEventListener('click', () => { disconnect(); start.focus(); });
    const setTheater = enabled => {
        if (enabled) previousOverflow = document.body.style.overflow;
        document.body.style.overflow = enabled ? 'hidden' : previousOverflow;
        player.classList.toggle('is-web-fullscreen', enabled);
        theater.setAttribute('aria-pressed', String(enabled));
        theater.textContent = enabled ? '退出網頁全螢幕' : '網頁全螢幕';
        if (enabled) { player.setAttribute('role', 'dialog'); player.setAttribute('aria-modal', 'true'); }
        else { player.removeAttribute('role'); player.removeAttribute('aria-modal'); }
        theater.focus();
    };
    theater.addEventListener('click', () => setTheater(!player.classList.contains('is-web-fullscreen')));
    document.addEventListener('keydown', event => {
        if (!player.classList.contains('is-web-fullscreen')) return;
        if (event.key === 'Escape' && !document.fullscreenElement) { event.preventDefault(); setTheater(false); }
        if (event.key === 'Tab') {
            const controls = [...player.querySelectorAll('a, button, iframe, video, select')].filter(element => !element.hidden && !element.disabled && element.getClientRects().length);
            if (event.shiftKey && document.activeElement === controls[0]) { event.preventDefault(); controls.at(-1).focus(); }
            else if (!event.shiftKey && document.activeElement === controls.at(-1)) { event.preventDefault(); controls[0].focus(); }
        }
    });
    if (!player.requestFullscreen) fullscreen.hidden = true;
    fullscreen.addEventListener('click', async () => {
        try {
            if (document.fullscreenElement) await document.exitFullscreen();
            else await player.requestFullscreen();
        } catch { feedback.textContent = '無法使用螢幕全螢幕，請改用網頁全螢幕。'; }
    });
    document.addEventListener('fullscreenchange', () => {
        fullscreen.textContent = document.fullscreenElement ? '退出螢幕全螢幕' : '螢幕全螢幕';
        fullscreen.setAttribute('aria-pressed', String(Boolean(document.fullscreenElement)));
    });
    window.addEventListener('pagehide', () => disconnect());
})();
