(() => {
    'use strict';
    const root = document.getElementById('sports-player');
    if (!root || root.dataset.mode !== 'hls') return;
    const el = id => document.getElementById(id);
    const container = el('sports-artplayer'), overlay = el('player-overlay');
    const message = el('player-message'), state = el('player-state'), metrics = el('player-metrics');
    const feedback = el('player-feedback'), stop = el('player-stop'), start = el('player-start');
    let art, hls, assets, retryTimer, generation = 0, active = false, wanted = false, suspended = false;
    let mode = 'balanced', selectedQuality = -1, recoveries = 0, lastMetric = 0, qualitySignature = '';
    const modes = [
        { html: '均衡 · 優先穩定', value: 'balanced' },
        { html: '低延遲 · 貼近直播', value: 'live' },
        { html: '省流量 · 最高 720p', value: 'save' }
    ];
    const script = url => new Promise((resolve, reject) => {
        const node = document.createElement('script');
        node.src = url;
        node.onload = resolve;
        node.onerror = () => { node.remove(); reject(new Error('Asset unavailable')); };
        document.head.append(node);
    });
    const loadAssets = () => {
        if (!assets) assets = Promise.all([
            window.Artplayer ? null : script(root.dataset.uiLibrary),
            window.artplayerI18nZhTw ? null : script(root.dataset.uiLanguage),
            window.Hls ? null : script(root.dataset.hlsLibrary)
        ]).catch(error => { assets = null; throw error; });
        return assets;
    };
    const setState = (text, playing = false) => {
        state.textContent = text;
        root.classList.toggle('is-playing', playing);
    };
    const disconnect = (label = '已停止') => {
        active = wanted = false;
        suspended = false;
        generation++;
        clearTimeout(retryTimer);
        if (hls) { hls.destroy(); hls = null; }
        if (art) { art.destroy(); art = null; }
        container.replaceChildren();
        stop.disabled = true;
        overlay.hidden = false;
        metrics.textContent = '等待影片資訊';
        feedback.textContent = '';
        message.textContent = '點選播放直播，連接目前的比賽。';
        setState(label);
    };
    const fail = text => { disconnect('無法播放'); message.textContent = text; };
    const play = () => {
        if (!active || !art) return;
        wanted = true;
        const attempt = generation;
        const instance = art;
        instance.play().catch(async error => {
            if (!active || generation !== attempt) return;
            if (error.name === 'NotAllowedError' && !instance.video.muted) {
                instance.muted = true;
                try {
                    await instance.play();
                    if (active && generation === attempt) {
                        instance.layers['unmute-prompt'].hidden = false;
                        instance.notice.show = '已自動靜音播放，點選「開啟聲音」';
                    }
                    return;
                } catch { /* This browser requires a gesture even for muted playback. */ }
            }
            if (!active || generation !== attempt) return;
            wanted = false;
            if (hls) { hls.stopLoad(); suspended = true; }
            setState('點選播放以繼續');
            art.notice.show = '請點選播放器上的播放按鈕';
        });
    };
    const goLive = () => {
        if (!art) return;
        const video = art.video;
        const position = hls?.liveSyncPosition ?? (video.seekable.length ? video.seekable.end(video.seekable.length - 1) - 2 : NaN);
        if (Number.isFinite(position)) video.currentTime = Math.max(0, position);
        play();
    };
    const label = text => {
        const span = document.createElement('span');
        span.textContent = text;
        return span;
    };
    const applyMode = () => {
        if (!hls) return;
        hls.config.liveSyncDurationCount = mode === 'live' ? 2 : 3;
        hls.config.liveMaxLatencyDurationCount = mode === 'live' ? 5 : 6;
        const limit = mode === 'save' ? 720 : 1080;
        const eligible = hls.levels.map((level, index) => ({level, index})).filter(({level}) => level.height > 0 && level.height <= limit);
        hls.autoLevelCapping = eligible.length ? eligible.at(-1).index : -1;
        if (mode === 'save') { selectedQuality = -1; hls.nextLevel = -1; }
        updateQuality();
    };
    const updateQuality = () => {
        if (!art || !hls || !hls.levels.length) return;
        const levels = hls.levels;
        const signature = JSON.stringify([levels.map(item => [item.height, item.bitrate]), art.video.videoHeight, selectedQuality, mode]);
        if (signature === qualitySignature) return;
        qualitySignature = signature;
        const single = levels.length === 1;
        art.setting.update({
            name: 'stream-quality', html: '畫質', width: 260,
            tooltip: single ? (art.video.videoHeight ? art.video.videoHeight + 'p · 唯一畫質' : '來源原始畫質') : selectedQuality < 0 ? '自動' : (levels[selectedQuality]?.height || '') + 'p',
            selector: [
                { html: single ? '自動 · 使用来源原始畫質' : '自動 · 依網路調整', value: -1, default: selectedQuality < 0 },
                ...(!single ? levels.map((item, index) => ({
                    html: label((item.height ? item.height + 'p' : '來源 ' + (index + 1)) + (item.bitrate ? ' · ' + (item.bitrate / 1e6).toFixed(1) + ' Mbps' : '')),
                    value: index, default: selectedQuality === index
                })).filter(item => mode !== 'save' || !levels[item.value].height || levels[item.value].height <= 720) : [])
            ],
            onSelect(item) {
                selectedQuality = Number(item.value);
                hls.nextLevel = selectedQuality;
                return selectedQuality < 0 ? '自動' : (levels[selectedQuality].height || '來源') + 'p';
            }
        });
        if (single) feedback.textContent = '此來源只有一種畫質；省流量模式不會降低它的流量。';
        accessible();
    };
    const updateMetrics = () => {
        if (!art || performance.now() - lastMetric < 1000) return;
        lastMetric = performance.now();
        const video = art.video, latency = hls?.latency;
        metrics.textContent = (video.videoHeight ? video.videoWidth + ' × ' + video.videoHeight : '連接直播中') +
            (Number.isFinite(latency) && latency > 0 ? ' · 距直播端點約 ' + Math.round(latency) + ' 秒' : '');
        updateQuality();
    };
    // ArtPlayer controls also remain operable with a keyboard and screen reader.
    const accessible = () => {
        container.querySelectorAll('.art-control, .art-setting-item').forEach(node => {
            if (node.matches('.art-control-thumbnails')) return;
            if (node.querySelector('button, input')) return;
            node.tabIndex = 0;
            node.setAttribute('role', 'button');
            const title = node.matches('.art-control-volume') ? '音量／靜音' : node.matches('.art-setting-item') ? node.textContent.trim() : node.getAttribute('aria-label') || node.getAttribute('data-tooltip') || node.textContent.trim();
            if (title) node.setAttribute('aria-label', title);
        });
    };
    const menuObserver = new MutationObserver(accessible);
    menuObserver.observe(container, { childList: true, subtree: true });
    container.addEventListener('keydown', event => {
        const control = event.target.closest('.art-control, .art-setting-item');
        if (control && event.target === control && ['Enter', ' '].includes(event.key)) {
            event.preventDefault(); event.stopPropagation();
            if (control.matches('.art-control-volume') && art) art.muted = !art.muted;
            else control.click();
        } else if (art && !['INPUT', 'SELECT', 'TEXTAREA'].includes(event.target.tagName)) {
            if (event.key.toLowerCase() === 'f') { event.preventDefault(); art.fullscreen = !art.fullscreen; }
            if (event.key.toLowerCase() === 'm') { event.preventDefault(); art.muted = !art.muted; }
            if (event.key === 'Escape') art.fullscreenWeb = false;
        }
    });
    const attachStream = (video, url, instance, attempt) => {
        const valid = () => active && generation === attempt && art === instance;
        video.id = 'sports-video';
        video.setAttribute('aria-label', container.getAttribute('aria-label'));
        if (window.Hls.isSupported()) {
            hls = new window.Hls({
                enableWorker: true, lowLatencyMode: true,
                liveSyncDurationCount: mode === 'live' ? 2 : 3,
                liveMaxLatencyDurationCount: mode === 'live' ? 5 : 6,
                maxLiveSyncPlaybackRate: 1.05,
                maxBufferLength: 12, maxMaxBufferLength: 24, backBufferLength: 6,
                maxBufferSize: 24 * 1024 * 1024,
                capLevelToPlayerSize: true, capLevelOnFPSDrop: true, maxDevicePixelRatio: 1.5
            });
            const current = hls, Hls = window.Hls;
            current.on(Hls.Events.MANIFEST_PARSED, () => {
                if (!valid()) return;
                applyMode();
                if (wanted) play();
            });
            current.on(Hls.Events.AUDIO_TRACKS_UPDATED, (_, data) => {
                if (!valid() || data.audioTracks.length < 2) return;
                const audioSetting = {
                    name: 'audio-track', html: '音軌', tooltip: '自動', width: 260,
                    selector: data.audioTracks.map((track, index) => ({ html: label(track.name || track.lang || '音軌 ' + (index + 1)), value: index })),
                    onSelect(item) { current.audioTrack = Number(item.value); return item.html; }
                };
                if (instance.setting.find('audio-track')) instance.setting.update(audioSetting);
                else instance.setting.add(audioSetting);
            });
            current.on(Hls.Events.ERROR, (_, data) => {
                if (!valid() || !wanted || !data.fatal) return;
                if (recoveries++ < 2 && [Hls.ErrorTypes.NETWORK_ERROR, Hls.ErrorTypes.MEDIA_ERROR].includes(data.type)) {
                    setState('來源中斷，正在重試');
                    clearTimeout(retryTimer);
                    retryTimer = setTimeout(() => {
                        if (!valid() || !wanted) return;
                        if (data.type === Hls.ErrorTypes.MEDIA_ERROR) current.recoverMediaError();
                        else current.startLoad(-1);
                    }, recoveries * 1500);
                } else fail('來源暫時無法播放。請重新載入，或返回賽事切換來源。');
            });
            current.attachMedia(video);
            current.loadSource(url);
        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
            instance.setting.remove('stream-mode');
            instance.setting.update({ name: 'stream-quality', html: '畫質', tooltip: '系統自動', onClick: () => '由瀏覽器調整' });
            feedback.textContent = '此裝置使用系統播放，自動管理畫質與延遲。';
            video.src = url;
            if (wanted) play();
        } else fail('此瀏覽器不支援直播格式，請改用原連結。');
    };
    const connect = async () => {
        disconnect('載入播放器');
        active = wanted = true;
        recoveries = 0;
        qualitySignature = '';
        selectedQuality = -1;
        const attempt = generation;
        stop.disabled = false;
        start.disabled = true;
        overlay.hidden = true;
        try {
            await loadAssets();
            if (!active || generation !== attempt) return;
            const Art = window.Artplayer;
            Art.LOG_VERSION = false;
            Art.USE_RAF = false;
            Art.RECONNECT_TIME_MAX = 0;
            Art.SETTING_ITEM_HEIGHT = 40;
            Art.REMOVE_SRC_WHEN_DESTROY = true;
            Art.FULLSCREEN_WEB_IN_BODY = false;
            const instance = new Art({
                container, url: root.dataset.sourceUrl, type: 'sports-hls',
                customType: { 'sports-hls': (video, url, instance) => {
                    // ArtPlayer initializes its custom source on the next task.
                    queueMicrotask(() => { if (active && generation === attempt) attachStream(video, url, instance, attempt); });
                }},
                isLive: true, autoplay: false, autoPlayback: false, autoMini: false,
                theme: '#d1f465', lang: 'zh-tw',
                i18n: { 'zh-tw': { ...window.artplayerI18nZhTw,
                    'Show Setting': '播放設定', 'Hide Setting': '關閉設定', Default: '預設',
                    Fullscreen: '全螢幕', 'Exit Fullscreen': '退出全螢幕',
                    'Web Fullscreen': '劇院模式', 'Exit Web Fullscreen': '退出劇院模式',
                    'PIP Mode': '子母畫面', 'Exit PIP Mode': '退出子母畫面'
                }},
                volume: 0.7, pip: true, fullscreen: true, fullscreenWeb: true,
                setting: true, aspectRatio: true, hotkey: true,
                lock: true, gesture: true, fastForward: false,
                moreVideoAttr: { playsInline: true, preload: 'none' },
                settings: [
                    { name: 'stream-quality', html: '畫質', tooltip: '自動', width: 260 },
                    { name: 'stream-mode', html: '播放模式', tooltip: modes.find(item => item.value === mode).html.split(' · ')[0], width: 260,
                        selector: modes.map(item => ({...item, default: item.value === mode})),
                        onSelect(item) { mode = item.value; applyMode(); if (mode === 'live') goLive(); return item.html.split(' · ')[0]; }
                    }
                ],
                controls: [{
                    name: 'live-edge', position: 'left', index: 20,
                    html: '<button type="button" class="player-live-button" aria-label="回到直播"><i aria-hidden="true"></i>直播<span>回到直播</span></button>',
                    click: goLive
                }],
                layers: [{
                    name: 'unmute-prompt',
                    html: '<button type="button" class="player-unmute">開啟聲音</button>',
                    mounted(node) { node.hidden = true; },
                    style: { top: '16px', left: '16px' },
                    click(component, event) {
                        event.stopPropagation();
                        instance.muted = false;
                        instance.layers['unmute-prompt'].hidden = true;
                    }
                }],
                cssVar: { '--art-control-height': '44px', '--art-bottom-height': '60px',
                    '--art-settings-max-height': 'calc(100% - 64px)', '--art-theme': '#d1f465' }
            });
            art = instance;
            overlay.hidden = true;
            setState('正在連接來源');
            instance.on('video:playing', () => { if (active) { setState('播放中', true); updateMetrics(); } });
            instance.on('video:waiting', () => { if (active && wanted) setState('緩衝中'); });
            instance.on('video:timeupdate', updateMetrics);
            instance.on('video:volumechange', () => {
                if (!instance.video.muted) instance.layers['unmute-prompt'].hidden = true;
            });
            instance.on('video:pause', () => {
                if (!active || art !== instance) return;
                wanted = false;
                clearTimeout(retryTimer);
                if (hls) { hls.stopLoad(); suspended = true; setState('已暫停'); }
                else { disconnect('已暫停'); message.textContent = '已停止下載，點選播放直播重新連接。'; }
            });
            instance.on('video:play', () => {
                if (!active || art !== instance) return;
                wanted = true;
                if (hls && suspended) { suspended = false; hls.startLoad(-1); }
            });
            instance.on('video:error', () => { if (active && !hls) fail('來源無法播放，請重新載入或切換來源。'); });
            instance.on('setting', accessible);
            instance.on('control', accessible);
            instance.on('fullscreenError', () => { instance.fullscreenWeb = true; });
            accessible();
        } catch { if (active && generation === attempt) fail('播放器載入失敗，請重新載入。'); }
        finally { start.disabled = false; }
    };
    start.addEventListener('click', connect);
    el('player-retry').addEventListener('click', connect);
    stop.addEventListener('click', () => { disconnect(); start.focus(); });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden || !active || !art) return;
        if (document.pictureInPictureElement === art.video || art.video.webkitPresentationMode === 'picture-in-picture') return;
        if (art.video.readyState) art.pause();
        else disconnect('已暫停');
    });
    window.addEventListener('pagehide', () => { disconnect(); menuObserver.disconnect(); });
    // Entering the page starts playback; a gesture is only requested if the browser requires it.
    connect();
})();
