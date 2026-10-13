(() => {
    'use strict';
    const hub = document.getElementById('sports-hub');
    if (!hub) return;
    const el = id => document.getElementById(id);
    const sources = el('hub-source-list'), status = el('hub-status'), refresh = el('sources-refresh');
    let eventId = '', eventData, selectedUrl = '', sourceAbort, scheduleAbort, sourceGeneration = 0, scheduleGeneration = 0;
    let currentStatusFilter = 'all';

    const text = (tag, value, className) => {
        const node = document.createElement(tag);
        if (value) node.textContent = value;
        if (className) node.className = className;
        return node;
    };

    const safeExternal = value => {
        try {
            const url = new URL(value);
            return url.protocol === 'https:' && !url.username && !url.password ? url.href : null;
        } catch {
            return null;
        }
    };

    const updateUrl = (id, replace = false) => {
        const url = new URL(location.href);
        if (id) url.searchParams.set('watch', id); else url.searchParams.delete('watch');
        history[replace ? 'replaceState' : 'pushState']({}, '', url);
    };

    const toggleSchedule = forceState => {
        const isCurrentlyCollapsed = hub.classList.contains('is-schedule-collapsed');
        const willCollapse = typeof forceState === 'boolean' ? forceState : !isCurrentlyCollapsed;

        hub.classList.toggle('is-schedule-collapsed', willCollapse);

        try {
            if (typeof localStorage !== 'undefined') {
                localStorage.setItem('sports_schedule_collapsed', willCollapse ? '1' : '0');
            }
        } catch {}

        const toggleBtns = [el('schedule-toggle-button'), el('schedule-toggle-watch-btn')];
        toggleBtns.forEach(btn => {
            if (!btn) return;
            btn.setAttribute('aria-expanded', String(!willCollapse));
            btn.classList.toggle('is-collapsed-state', willCollapse);
        });

        const labels = [el('schedule-toggle-label'), el('watch-toggle-label')];
        labels.forEach(lbl => {
            if (!lbl) return;
            lbl.textContent = willCollapse ? '展開賽程' : '收合賽程';
        });

        if (typeof window.dispatchEvent === 'function') {
            setTimeout(() => {
                window.dispatchEvent(new Event('resize'));
            }, 180);
        }
    };

    const markSelected = () => {
        const cards = typeof document.querySelectorAll === 'function' ? document.querySelectorAll('[data-event-id]') : [];
        for (let i = 0; i < cards.length; i++) {
            const card = cards[i];
            const selected = card.dataset.eventId === eventId;
            card.classList.toggle('is-selected', selected);
            const link = typeof card.querySelector === 'function' ? card.querySelector('[data-watch-event]') : null;
            if (selected) link?.setAttribute('aria-current', 'true'); else link?.removeAttribute('aria-current');
        }
        const sourceButtons = typeof sources.querySelectorAll === 'function' ? sources.querySelectorAll('[data-source-url]') : [];
        for (let i = 0; i < sourceButtons.length; i++) {
            const button = sourceButtons[i];
            const selected = button.dataset.sourceUrl === selectedUrl;
            button.setAttribute('aria-pressed', String(selected));
            const parent = typeof button.closest === 'function' ? button.closest('.hub-source') : null;
            parent?.classList.toggle('is-selected', selected);
        }
    };

    const updateFloatingBar = () => {
        const floating = el('hub-floating-return');
        if (!floating) return;
        if (!eventId || !selectedUrl) {
            floating.hidden = true;
            return;
        }
        const watchArea = el('watch-area');
        if (!watchArea || typeof window.innerHeight === 'undefined') return;
        const rect = typeof watchArea.getBoundingClientRect === 'function' ? watchArea.getBoundingClientRect() : null;
        if (rect) {
            floating.hidden = rect.bottom > 120;
        }
    };

    const playSource = stream => {
        if (!stream.playback || selectedUrl === stream.url) return;
        selectedUrl = stream.url;
        window.SportsLivePlayer.load(stream.playback, eventData.match.translated_title);
        const original = safeExternal(stream.url);
        const origBtn = el('player-original');
        if (origBtn) {
            origBtn.hidden = !original;
            if (original) origBtn.href = original;
        }
        const watchMeta = el('watch-meta');
        if (watchMeta) {
            watchMeta.textContent = [eventData.match.league, stream.platform, stream.language, '頻道 ' + stream.number].join(' / ');
        }
        status.textContent = '正在連接 ' + stream.language + ' · ' + stream.platform;

        const floatTitle = el('floating-match-title');
        if (floatTitle) floatTitle.textContent = eventData.match.translated_title;
        const floatInfo = el('floating-source-info');
        if (floatInfo) floatInfo.textContent = stream.platform + ' · ' + stream.language + ' 頻道 ' + stream.number;

        markSelected();
        updateFloatingBar();
    };

    const renderSources = data => {
        const groups = new Map();
        data.streams.forEach(stream => {
            const key = stream.platform || stream.source || '其他來源';
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(stream);
        });
        sources.replaceChildren();
        const countDisplay = el('source-count');
        if (countDisplay) countDisplay.textContent = String(data.streams.length);
        for (const [platform, streams] of groups) {
            const group = text('div', '', 'source-group');
            const heading = text('div', '', 'source-group-head');
            heading.append(text('h3', platform));
            heading.append(text('span', streams.length + ' 個訊號', 'source-group-badge'));
            group.append(heading);

            const grid = text('div', '', 'source-options');
            for (const stream of streams) {
                const card = text('div', '', 'hub-source' + (stream.playback ? ' is-playable' : ' is-external'));
                const button = text(stream.playback ? 'button' : 'div', '', 'source-choice');
                if (stream.playback) {
                    button.type = 'button';
                    button.dataset.sourceUrl = stream.url;
                    button.setAttribute('aria-pressed', 'false');
                    button.setAttribute('aria-label', '播放 ' + platform + ' ' + stream.language + ' 頻道 ' + stream.number);
                    button.addEventListener('click', () => playSource(stream));
                }

                const labelRow = text('div', '', 'source-choice-top');
                const badge = text('span', stream.playback ? (stream.hd ? '⚡ 站內高畫質' : '⚡ 站內播放') : '↗ 原站連結', stream.playback ? 'badge-hls' : 'badge-external');
                labelRow.append(badge);
                if (stream.playback && stream.hd) {
                    labelRow.append(text('span', '1080p', 'badge-quality'));
                }
                button.append(labelRow);

                const titleRow = text('div', '', 'source-choice-title');
                titleRow.append(text('strong', stream.language + ' · 頻道 ' + stream.number));
                button.append(titleRow);

                button.append(text('small', stream.playback ? '免跳轉直接觀看 · 即時轉播' : '在新分頁載入原站來源'));
                card.append(button);

                const external = safeExternal(stream.url);
                if (external) {
                    const link = text('a', '↗', 'source-external');
                    link.href = external;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer nofollow';
                    link.setAttribute('aria-label', platform + ' ' + stream.language + ' 頻道 ' + stream.number + ' 原連結');
                    link.title = '在新分頁開啟原連結';
                    card.append(link);
                }
                grid.append(card);
            }
            group.append(grid);
            sources.append(group);
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
            const orig = el('player-original');
            if (orig) orig.hidden = true;
            const wTitle = el('watch-title');
            if (wTitle) wTitle.textContent = title || '載入比賽中…';
            const wOrig = el('watch-original');
            if (wOrig) wOrig.textContent = '';
            const wMeta = el('watch-meta');
            if (wMeta) wMeta.textContent = '正在查詢來源';
            sources.replaceChildren();
            const countDisplay = el('source-count');
            if (countDisplay) countDisplay.textContent = '…';
        }
        markSelected();
        refresh.disabled = true;
        status.textContent = '正在查詢 Sportsurge、Streameast 與 MLBWebcast…';
        sources.setAttribute('aria-busy', 'true');
        const timeout = setTimeout(() => controller.abort(), 35000);
        try {
            const response = await fetch(hub.dataset.eventBase + '/' + encodeURIComponent(id), {
                headers: { Accept: 'application/json' },
                signal: controller.signal
            });
            if (!response.ok) throw new Error(response.status === 404 ? '這場比賽已不在來源賽程中，請選擇其他比賽。' : '暫時無法取得轉播來源，請點選「更新來源」重試。');
            const data = await response.json();
            if (attempt !== sourceGeneration) return;
            eventData = data;
            const wTitle = el('watch-title');
            if (wTitle) wTitle.textContent = data.match.translated_title;
            const wOrig = el('watch-original');
            if (wOrig) wOrig.textContent = data.match.title + ' · ' + data.match.date + ' ' + data.match.time + ' 台灣時間';
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
                    const wMeta = el('watch-meta');
                    if (wMeta) wMeta.textContent = data.match.league + ' / 轉播來源';
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
            if (attempt === sourceGeneration) {
                refresh.disabled = false;
                sources.removeAttribute('aria-busy');
            }
        }
    };

    const applyClientFilters = () => {
        const searchInput = el('schedule-search-input');
        const query = (searchInput?.value || '').trim().toLowerCase();
        const clearBtn = el('schedule-search-clear');
        if (clearBtn) clearBtn.hidden = !query;

        const cards = typeof document.querySelectorAll === 'function' ? document.querySelectorAll('.match-card') : [];
        let visibleCount = 0;
        const daySections = new Map();

        for (let i = 0; i < cards.length; i++) {
            const card = cards[i];
            const textMatch = !query || (card.dataset.searchText || card.textContent || '').toLowerCase().includes(query);
            const isLive = card.dataset.isLive === '1';
            let statusMatch = true;
            if (currentStatusFilter === 'live') statusMatch = isLive;
            else if (currentStatusFilter === 'upcoming') statusMatch = !isLive;

            const visible = textMatch && statusMatch;
            card.style.display = visible ? '' : 'none';
            if (visible) visibleCount++;

            const section = typeof card.closest === 'function' ? card.closest('.day-section') : null;
            if (section) {
                daySections.set(section, (daySections.get(section) || 0) + (visible ? 1 : 0));
            }
        }

        for (const [section, count] of daySections) {
            section.style.display = count > 0 ? '' : 'none';
        }

        const countDisplay = el('results-count-display');
        if (countDisplay) countDisplay.textContent = String(visibleCount);

        const emptyMessage = el('client-filter-empty');
        if (emptyMessage) emptyMessage.hidden = visibleCount > 0;
    };

    const loadSchedule = async (url, push = true) => {
        scheduleAbort?.abort();
        const controller = scheduleAbort = new AbortController();
        const attempt = ++scheduleGeneration;
        const feedback = el('schedule-feedback');
        if (feedback) feedback.textContent = '更新中…';
        const schedulePanel = el('sports-schedule');
        if (schedulePanel) schedulePanel.setAttribute('aria-busy', 'true');
        if (eventId) url.searchParams.set('watch', eventId);
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'text/html' } });
            if (!response.ok) throw new Error('賽程更新失敗，請重新整理賽程。');
            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            const panel = html.getElementById('sports-schedule');
            if (!panel) throw new Error('賽程暫時無法顯示。');
            if (attempt !== scheduleGeneration) return;
            schedulePanel?.replaceChildren(...panel.childNodes);
            if (eventId) url.searchParams.set('watch', eventId); else url.searchParams.delete('watch');
            if (push) history.pushState({}, '', url);
            markSelected();
            applyClientFilters();
            document.dispatchEvent(new Event('sports:schedule-updated'));
            if (feedback) feedback.textContent = '已更新';
        } catch (error) {
            if (attempt === scheduleGeneration && feedback) {
                feedback.textContent = error.name === 'AbortError' ? '更新逾時，請再試一次。' : error.message;
            }
        } finally {
            clearTimeout(timeout);
            if (attempt === scheduleGeneration) schedulePanel?.removeAttribute('aria-busy');
        }
    };

    hub.addEventListener('click', event => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const target = event.target;

        // Schedule sidebar toggle button
        if (typeof target.closest === 'function' && (target.closest('#schedule-toggle-button') || target.closest('#schedule-toggle-watch-btn') || target.closest('#schedule-close-btn'))) {
            event.preventDefault();
            toggleSchedule();
            return;
        }

        // Schedule jump link: if schedule was collapsed, expand it before scrolling
        if (typeof target.closest === 'function' && target.closest('.schedule-jump')) {
            if (hub.classList.contains('is-schedule-collapsed')) {
                toggleSchedule(false);
            }
            return;
        }

        // Search clear button
        if (typeof target.closest === 'function' && target.closest('#schedule-search-clear')) {
            event.preventDefault();
            const input = el('schedule-search-input');
            if (input) {
                input.value = '';
                input.focus();
                applyClientFilters();
            }
            return;
        }

        // Client filter reset button
        if (typeof target.closest === 'function' && target.closest('#client-filter-reset')) {
            event.preventDefault();
            currentStatusFilter = 'all';
            const pills = typeof document.querySelectorAll === 'function' ? document.querySelectorAll('.status-filter-pill') : [];
            pills.forEach(pill => {
                const isAll = pill.dataset.statusFilter === 'all';
                pill.classList.toggle('is-active', isAll);
                pill.setAttribute('aria-selected', String(isAll));
            });
            const input = el('schedule-search-input');
            if (input) input.value = '';
            applyClientFilters();
            return;
        }

        // Status filter pills
        const pill = typeof target.closest === 'function' ? target.closest('.status-filter-pill') : null;
        if (pill) {
            event.preventDefault();
            currentStatusFilter = pill.dataset.statusFilter || 'all';
            const pills = typeof document.querySelectorAll === 'function' ? document.querySelectorAll('.status-filter-pill') : [];
            pills.forEach(p => {
                const active = p === pill;
                p.classList.toggle('is-active', active);
                p.setAttribute('aria-selected', String(active));
            });
            applyClientFilters();
            return;
        }

        // Floating return button
        if (typeof target.closest === 'function' && target.closest('#floating-return-btn')) {
            event.preventDefault();
            el('watch-area')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }

        // Match card or watch link click
        const card = typeof target.closest === 'function' ? target.closest('.match-card') : null;
        const watchLink = typeof target.closest === 'function' ? target.closest('[data-watch-event]') : null;

        if (card || watchLink) {
            const externalLink = typeof target.closest === 'function' ? target.closest('a[target="_blank"]') : null;
            if (!externalLink) {
                const targetEventId = watchLink?.dataset?.watchEvent || card?.dataset?.eventId;
                if (targetEventId) {
                    event.preventDefault();
                    const title = watchLink?.dataset?.title || card?.getAttribute('aria-label') || '';
                    if (targetEventId !== eventId) {
                        selectEvent(targetEventId, { title });
                    }
                    if (typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 1000px)').matches) {
                        el('watch-area')?.scrollIntoView({
                            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
                            block: 'start'
                        });
                    }
                    return;
                }
            }
        }

        // Quick filter chips inside schedule (range or league links)
        const link = typeof target.closest === 'function' ? target.closest('a') : null;
        if (link && link.closest && link.closest('#sports-schedule')) {
            const url = new URL(link.href);
            if (url.origin === location.origin && url.pathname === '/sports') {
                event.preventDefault();
                loadSchedule(url);
            }
        }
    });

    hub.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const target = event.target;
        if (target && target.classList && target.classList.contains('match-card')) {
            event.preventDefault();
            target.click();
        }
    });

    hub.addEventListener('submit', event => {
        if (!event.target.matches || !event.target.matches('#sports-schedule form')) return;
        event.preventDefault();
        const url = new URL(event.target.action);
        for (const [key, value] of new FormData(event.target)) if (value) url.searchParams.set(key, value);
        loadSchedule(url);
    });

    hub.addEventListener('input', event => {
        if (event.target && event.target.id === 'schedule-search-input') {
            applyClientFilters();
        }
    });

    refresh.addEventListener('click', () => {
        if (eventId) selectEvent(eventId, { preserve: true, historyMode: 'none' });
    });

    const playerEl = el('sports-player');
    if (playerEl) {
        playerEl.addEventListener('sports:playback', event => {
            if (!selectedUrl) return;
            if (event.detail.playing) status.textContent = '正在播放所選來源。可直接切換下方頻道，或繼續瀏覽賽程。';
            else if (event.detail.text === '無法播放') status.textContent = '此來源暫時無法播放，請選擇其他站內來源，或開啟原連結。';
            updateFloatingBar();
        });
    }

    // Taipei Live Clock
    const clockEl = el('hub-clock-time');
    if (clockEl) {
        const updateClock = () => {
            try {
                if (typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
                    const now = new Date();
                    const timeString = new Intl.DateTimeFormat('zh-TW', {
                        timeZone: 'Asia/Taipei',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: false
                    }).format(now);
                    clockEl.textContent = timeString;
                }
            } catch {
                clockEl.textContent = '';
            }
        };
        updateClock();
        if (typeof setInterval === 'function') {
            setInterval(updateClock, 1000);
        }
    }

    // Scroll listener for mobile floating return bar
    window.addEventListener('scroll', () => {
        updateFloatingBar();
    }, { passive: true });

    // Restore saved schedule collapse state on wide screens
    try {
        if (typeof localStorage !== 'undefined' && localStorage.getItem('sports_schedule_collapsed') === '1') {
            if (typeof window.matchMedia === 'function' && window.matchMedia('(min-width: 1001px)').matches) {
                toggleSchedule(true);
            }
        }
    } catch {}

    window.addEventListener('popstate', () => {
        const url = new URL(location.href), id = url.searchParams.get('watch');
        if (id && id !== eventId) {
            selectEvent(id, { historyMode: 'none' });
        } else if (!id && eventId) {
            sourceAbort?.abort();
            sourceGeneration++;
            eventId = selectedUrl = '';
            hub.classList.remove('has-selection');
            window.SportsLivePlayer.clear();
            sources.replaceChildren();
            const wTitle = el('watch-title');
            if (wTitle) wTitle.textContent = '選一場比賽，開始觀看';
            const wOrig = el('watch-original');
            if (wOrig) wOrig.textContent = '';
            const wMeta = el('watch-meta');
            if (wMeta) wMeta.textContent = '你的觀賽席';
            status.textContent = '請從賽程選擇比賽。';
            const countDisplay = el('source-count');
            if (countDisplay) countDisplay.textContent = '—';
            const orig = el('player-original');
            if (orig) orig.hidden = true;
            refresh.disabled = true;
            updateFloatingBar();
        }
        loadSchedule(url, false);
    });

    window.addEventListener('pagehide', () => {
        sourceAbort?.abort();
        scheduleAbort?.abort();
    });

    if (hub.dataset.initialEvent) {
        selectEvent(hub.dataset.initialEvent, { historyMode: 'replace' });
    }
})();
