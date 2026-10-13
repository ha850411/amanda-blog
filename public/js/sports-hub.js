(() => {
    'use strict';
    const hub = document.getElementById('sports-hub');
    if (!hub) return;
    const el = id => document.getElementById(id);
    const sources = el('hub-source-list'), status = el('hub-status'), refresh = el('sources-refresh');
    let eventId = '', eventData, selectedUrl = '', sourceAbort, scheduleAbort, sourceGeneration = 0, scheduleGeneration = 0;
    const text = (tag, value, className) => { const node = document.createElement(tag); node.textContent = value; if (className) node.className = className; return node; };
    const safeExternal = value => { try { const url = new URL(value); return url.protocol === 'https:' && !url.username && !url.password ? url.href : null; } catch { return null; } };
    const updateUrl = (id, replace = false) => {
        const url = new URL(location.href);
        if (id) url.searchParams.set('watch', id); else url.searchParams.delete('watch');
        history[replace ? 'replaceState' : 'pushState']({}, '', url);
    };
    const markSelected = () => {
        document.querySelectorAll('[data-event-id]').forEach(card => {
            const selected = card.dataset.eventId === eventId;
            card.classList.toggle('is-selected', selected);
            const link = card.querySelector('[data-watch-event]');
            if (selected) link?.setAttribute('aria-current', 'true'); else link?.removeAttribute('aria-current');
        });
        sources.querySelectorAll('[data-source-url]').forEach(button => {
            const selected = button.dataset.sourceUrl === selectedUrl;
            button.setAttribute('aria-pressed', String(selected));
            button.closest('.hub-source').classList.toggle('is-selected', selected);
        });
    };
    const playSource = stream => {
        if (!stream.playback || selectedUrl === stream.url) return;
        selectedUrl = stream.url;
        window.SportsLivePlayer.load(stream.playback, eventData.match.translated_title);
        const original = safeExternal(stream.url);
        el('player-original').hidden = !original;
        if (original) el('player-original').href = original;
        el('watch-meta').textContent = [eventData.match.league, stream.platform, stream.language, '頻道 ' + stream.number].join(' / ');
        status.textContent = '正在連接 ' + stream.language + ' · ' + stream.platform;
        markSelected();
    };
    const renderSources = data => {
        const groups = new Map();
        data.streams.forEach(stream => {
            const key = stream.platform || stream.source || '其他來源';
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(stream);
        });
        sources.replaceChildren();
        el('source-count').textContent = String(data.streams.length);
        for (const [platform, streams] of groups) {
            const group = text('div', '', 'source-group');
            group.append(text('h3', platform));
            const grid = text('div', '', 'source-options');
            for (const stream of streams) {
                const card = text('div', '', 'hub-source');
                const button = text(stream.playback ? 'button' : 'div', '', 'source-choice');
                if (stream.playback) {
                    button.type = 'button';
                    button.dataset.sourceUrl = stream.url;
                    button.setAttribute('aria-pressed', 'false');
                    button.setAttribute('aria-label', '播放 ' + platform + ' ' + stream.language + ' 頻道 ' + stream.number);
                    button.addEventListener('click', () => playSource(stream));
                }
                button.append(text('strong', stream.language + ' · ' + stream.number));
                button.append(text('small', stream.playback ? '站內播放' + (stream.hd ? ' · HD' : '') : '原站觀看'));
                card.append(button);
                const external = safeExternal(stream.url);
                if (external) {
                    const link = text('a', '↗', 'source-external');
                    link.href = external; link.target = '_blank'; link.rel = 'noopener noreferrer nofollow';
                    link.setAttribute('aria-label', platform + ' ' + stream.language + ' 頻道 ' + stream.number + ' 原連結');
                    link.title = '在新分頁開啟原連結'; card.append(link);
                }
                grid.append(card);
            }
            group.append(grid); sources.append(group);
        }
        markSelected();
    };
    const selectEvent = async (id, { preserve = false, historyMode = 'push', title = '' } = {}) => {
        sourceAbort?.abort();
        const controller = sourceAbort = new AbortController();
        const attempt = ++sourceGeneration;
        const changed = id !== eventId;
        eventId = id;
        hub.classList.add('has-selection');
        if (historyMode !== 'none') updateUrl(id, historyMode === 'replace');
        if (changed || !preserve) {
            selectedUrl = '';
            window.SportsLivePlayer.clear('正在查詢這場比賽的轉播來源…');
            el('player-original').hidden = true;
            el('watch-title').textContent = title || '載入比賽中…';
            el('watch-original').textContent = '';
            el('watch-meta').textContent = '正在查詢來源';
            sources.replaceChildren(); el('source-count').textContent = '…';
        }
        markSelected();
        refresh.disabled = true;
        status.textContent = '正在查詢 Sportsurge、Streameast 與 MLBWebcast…';
        sources.setAttribute('aria-busy', 'true');
        const timeout = setTimeout(() => controller.abort(), 35000);
        try {
            const response = await fetch(hub.dataset.eventBase + '/' + encodeURIComponent(id), { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!response.ok) throw new Error(response.status === 404 ? '這場比賽已不在來源賽程中，請選擇其他比賽。' : '暫時無法取得轉播來源，請點選「更新來源」重試。');
            const data = await response.json();
            if (attempt !== sourceGeneration) return;
            eventData = data;
            el('watch-title').textContent = data.match.translated_title;
            el('watch-original').textContent = data.match.title + ' · ' + data.match.date + ' ' + data.match.time + ' 台灣時間';
            renderSources(data);
            const current = data.streams.find(stream => stream.url === selectedUrl && stream.playback);
            if (preserve && current) {
                status.textContent = '來源已更新，保留目前選取的頻道。';
            } else {
                selectedUrl = '';
                const playable = data.streams.find(stream => stream.playback);
                if (playable) playSource(playable);
                else {
                    window.SportsLivePlayer.clear(data.streams.length ? '這場比賽目前提供原站連結，請從下方選擇來源。' : '目前尚未提供轉播來源，開賽前可再更新。');
                    el('watch-meta').textContent = data.match.league + ' / 轉播來源';
                    status.textContent = data.streams.length ? '目前沒有可在站內播放的來源，可使用原連結。' : '目前沒有來源，請稍後更新或選擇其他比賽。';
                }
            }
            if (data.warnings?.length) status.textContent += ' ' + data.warnings.join(' ');
        } catch (error) {
            if (attempt !== sourceGeneration) return;
            status.textContent = error.name === 'AbortError' ? '來源查詢逾時，請點選「更新來源」重試。' : error.message;
            if (!preserve) window.SportsLivePlayer.clear('來源暫時無法載入，請更新來源或選擇其他比賽。');
        } finally {
            clearTimeout(timeout);
            if (attempt === sourceGeneration) { refresh.disabled = false; sources.removeAttribute('aria-busy'); }
        }
    };
    const loadSchedule = async (url, push = true) => {
        scheduleAbort?.abort(); const controller = scheduleAbort = new AbortController();
        const attempt = ++scheduleGeneration;
        const feedback = el('schedule-feedback');
        feedback.textContent = '更新中…';
        el('sports-schedule').setAttribute('aria-busy', 'true');
        if (eventId) url.searchParams.set('watch', eventId);
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'text/html' } });
            if (!response.ok) throw new Error('賽程更新失敗，請重新整理賽程。');
            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            const panel = html.getElementById('sports-schedule');
            if (!panel) throw new Error('賽程暫時無法顯示。');
            if (attempt !== scheduleGeneration) return;
            el('sports-schedule').replaceChildren(...panel.childNodes);
            if (eventId) url.searchParams.set('watch', eventId); else url.searchParams.delete('watch');
            if (push) history.pushState({}, '', url);
            markSelected();
            document.dispatchEvent(new Event('sports:schedule-updated'));
            feedback.textContent = '已更新';
        } catch (error) {
            if (attempt === scheduleGeneration) feedback.textContent = error.name === 'AbortError' ? '更新逾時，請再試一次。' : error.message;
        } finally {
            clearTimeout(timeout);
            if (attempt === scheduleGeneration) el('sports-schedule').removeAttribute('aria-busy');
        }
    };
    hub.addEventListener('click', event => {
        const link = event.target.closest('a');
        if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (link.dataset.watchEvent) {
            event.preventDefault();
            if (link.dataset.watchEvent !== eventId) selectEvent(link.dataset.watchEvent, { title: link.dataset.title });
            if (matchMedia('(max-width: 1000px)').matches) el('watch-area').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' });
        } else if (link.closest('#sports-schedule')) {
            const url = new URL(link.href);
            if (url.origin === location.origin && url.pathname === '/sports') { event.preventDefault(); loadSchedule(url); }
        }
    });
    hub.addEventListener('submit', event => {
        if (!event.target.matches('#sports-schedule form')) return;
        event.preventDefault();
        const url = new URL(event.target.action);
        for (const [key, value] of new FormData(event.target)) if (value) url.searchParams.set(key, value);
        loadSchedule(url);
    });
    refresh.addEventListener('click', () => { if (eventId) selectEvent(eventId, { preserve: true, historyMode: 'none' }); });
    el('sports-player').addEventListener('sports:playback', event => {
        if (!selectedUrl) return;
        if (event.detail.playing) status.textContent = '正在播放所選來源。可直接切換下方頻道，或繼續瀏覽賽程。';
        else if (event.detail.text === '無法播放') status.textContent = '此來源暫時無法播放，請選擇其他站內來源，或開啟原連結。';
    });
    window.addEventListener('popstate', () => {
        const url = new URL(location.href), id = url.searchParams.get('watch');
        if (id && id !== eventId) selectEvent(id, { historyMode: 'none' });
        else if (!id && eventId) { sourceAbort?.abort(); sourceGeneration++; eventId = selectedUrl = ''; hub.classList.remove('has-selection'); window.SportsLivePlayer.clear(); sources.replaceChildren(); el('watch-title').textContent = '選一場比賽，開始觀看'; el('watch-original').textContent = ''; el('watch-meta').textContent = '你的觀賽席'; status.textContent = '請從賽程選擇比賽。'; el('source-count').textContent = '—'; el('player-original').hidden = true; refresh.disabled = true; }
        loadSchedule(url, false);
    });
    window.addEventListener('pagehide', () => { sourceAbort?.abort(); scheduleAbort?.abort(); });
    if (hub.dataset.initialEvent) selectEvent(hub.dataset.initialEvent, { historyMode: 'replace' });
})();
