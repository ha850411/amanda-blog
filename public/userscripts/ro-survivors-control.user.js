// ==UserScript==
// @name         RO Survivors 實驗控制面板
// @namespace    local.ro-survivors.controls
// @version      0.6.27
// @description:en Local test build; automatic remote updates intentionally disabled.
// @description  自動走向遠征隊長／節慶主持人、選角進場、斷線維修每5秒重整重連、記憶設定與結算診斷（動態相容遊戲建置）。
// @match        https://ro-survivors.com/*
// @match        https://www.ro-survivors.com/*
// @run-at       document-idle
// @grant        none
// @sandbox      raw
// @noframes
// ==/UserScript==
/* 請取代舊版後重新整理。預設不加成，滑桿與開關即時套用並記憶設定。
 * 尋寶率改變局內品質抽選，不保證伺服器接受修改後的結算數值。
 * 連刷支援掛機前選角、斷線／維修每 5 秒重整重連、關卡、難度與開卡模式記憶。
 * 還原不撤銷已恢復的 HP、已造成的傷害、進度或已排程動畫。 */
(() => {
  'use strict';

  const ID = 'ros-experiment-panel-v060';
  if (document.getElementById(ID)) return;

  if ([
    'ros-experiment-panel-v010',
    'ros-experiment-panel-v020',
    'ros-experiment-panel-v030',
    'ros-experiment-panel-v040',
    'ros-experiment-panel-v050',
    'ros-experiment-panel-v051'
  ].some(id => document.getElementById(id))) {
    alert('請先停用舊版 RO 腳本，再重新整理。不要同時執行兩版。');
    return;
  }

  const clamp = (v, max = 100) =>
    Number.isFinite(Number(v))
      ? Math.max(1, Math.min(max, Number(v)))
      : 1;

  function purgeTrapFlags() {
    try {
      localStorage.removeItem('ro_gfx_cal');
    } catch {}
  }
  purgeTrapFlags();

  function isBlockedEnvPost(input, init) {
    try {
      const url = new URL(
        typeof input === 'string' ? input : input?.url ?? input,
        document.baseURI
      );
      if (url.origin !== location.origin) return false;
      if (url.pathname.replace(/\/+$/, '') !== '/api/env') return false;
      const method = String(init?.method ?? input?.method ?? 'GET').toUpperCase();
      return method === 'POST';
    } catch {
      return false;
    }
  }

  function installEnvApiBlocker() {
    const original = window.fetch;
    if (typeof original !== 'function') return;
    window.fetch = function blockedEnvFetch(...args) {
      if (isBlockedEnvPost(args[0], args[1])) {
        return Promise.resolve(
          new Response('{}', {
            status: 200,
            headers: { 'Content-Type': 'application/json' }
          })
        );
      }
      return original.apply(this, args);
    };
  }
  installEnvApiBlocker();

  // Observe the current game's JSON API without changing requests or responses.
  // Keep only diagnostic fields: never retain headers, sessions or account docs.
  function createLootAudit(getSettings, notify) {
    const runs = new Map();
    let sequence = 0;
    let revision = 0;
    let restoreFetch = null;
    const number = v => typeof v === 'number' && Number.isFinite(v) ? v : null;
    const count = v => Number.isSafeInteger(v) && v >= 0 ? v : null;
    const round = v => Math.round(v * 100) / 100;
    const update = () => { try { notify(); } catch {} };

    function drops(dr) {
      if (!dr || typeof dr !== 'object' || Array.isArray(dr)) return null;
      const sources = [];
      for (const [source, row] of Object.entries(dr)) {
        if (!Array.isArray(row)) return null;
        const indexed = Array.isArray(row[0]) && Array.isArray(row[1]);
        const gear = indexed ? row[0].length : count(row[0]);
        const cards = indexed ? row[1].length : count(row[1]);
        if (gear === null || cards === null) return null;
        sources.push({
          source, gear, cards,
          ...(indexed ? {
            gearIndices: [...row[0]], cardIndices: [...row[1]],
            discovery: Array.isArray(row[2]) ? [...row[2]] : null
          } : {})
        });
      }
      let maxGearIndex = null;
      for (const row of sources) {
        if (Array.isArray(row.gearIndices)) {
          for (const idx of row.gearIndices) {
            if (Number.isSafeInteger(idx) && idx > 0) {
              maxGearIndex = maxGearIndex === null ? idx : Math.max(maxGearIndex, idx);
            }
          }
        }
      }
      return {
        gear: sources.reduce((sum, row) => sum + row.gear, 0),
        cards: sources.reduce((sum, row) => sum + row.cards, 0),
        ...(maxGearIndex !== null ? { maxGearIndex } : {}),
        sources
      };
    }

    function request(input, init) {
      const url = new URL(typeof input === 'string' ? input : input?.url ?? input,
        document.baseURI);
      if (url.origin !== location.origin ||
          !['/api/run', '/api/op', '/api/save'].includes(url.pathname) ||
          typeof init?.body !== 'string') return null;
      const body = JSON.parse(init.body);
      const op = url.pathname === '/api/run' ? 'run'
        : url.pathname === '/api/save' ? 'save' : body.op;
      if (!['run', 'save', 'tick', 'loot', 'gloot'].includes(op)) return null;
      const data = op === 'save' || op === 'tick' ? body.rk : body;
      if (!data || typeof data.key !== 'string') return null;
      const now = performance.now();
      let run = runs.get(data.key);
      if (!run) {
        run = {
          id: ++sequence, started: new Date().toISOString(), start: now,
          // Missing the start or resuming makes wall-time comparisons incomplete.
          completeStart: op === 'run' && !body.played,
          ...(op === 'run' && number(body.played) !== null ? { played: number(body.played) } : {}),
          stage: typeof body.stage === 'string' ? body.stage : null,
          multiplier: number(body.mult), events: [], omittedEvents: 0
        };
        runs.set(data.key, run);
        if (runs.size > 12) runs.delete(runs.keys().next().value);
      }
      const settings = getSettings();
      const event = {
        op, atSeconds: round((now - run.start) / 1000),
        gameSeconds: number(data.secs),
        settings: Object.fromEntries(['speed', 'discovery', 'attack', 'cooldown',
          'targets', 'reach', 'pickup', 'suction', 'animation', 'mobDropCap'].map(k => [k, number(settings[k])])),
        kills: data.kills && typeof data.kills === 'object'
          ? Object.fromEntries(Object.entries(data.kills).filter(([, v]) => count(v) !== null))
          : null,
        discovery: number(data.disc), sent: drops(data.dr), status: 'pending'
      };
      run.events.push(event);
      if (run.events.length > 120) {
        run.events.shift();
        run.omittedEvents++;
      }
      update();
      return { run, event, now, revision };
    }

    function missingSources(sent, granted) {
      if (!sent?.sources?.length || !Array.isArray(granted?.items)) return [];
      const grantedBySource = new Map();
      for (const item of granted.items) {
        if (typeof item.source === 'string' && item.source) {
          grantedBySource.set(item.source, (grantedBySource.get(item.source) ?? 0) + 1);
        }
      }
      const missing = [];
      for (const row of sent.sources) {
        if (!row.gear) continue;
        const got = grantedBySource.get(row.source) ?? 0;
        if (row.gear > got) {
          missing.push({ source: row.source, sent: row.gear, granted: got, missing: row.gear - got });
        }
      }
      return missing;
    }

    async function response(ticket, result) {
      if (!ticket || ticket.revision !== revision) return;
      const { run, event } = ticket;
      event.httpStatus = result.status;
      event.responseSeconds = round((performance.now() - ticket.now) / 1000);
      event.status = result.ok ? 'ok' : 'http-error';
      try {
        const data = await result.clone().json();
        if (ticket.revision !== revision) return;
        if (typeof data.error === 'string') event.error = data.error.slice(0, 100);
        if (event.op === 'run' && data.cap) {
          run.cap = {
            mob: count(data.cap.mob), card: count(data.cap.card),
            boss: Object.fromEntries(Object.entries(data.cap.boss ?? {})
              .filter(([, v]) => count(v) !== null))
          };
          run.seeded = Number.isInteger(data.seed) && data.seed > 0;
        }
        if (data.granted && Array.isArray(data.granted.gear)) {
          const g = data.granted;
          event.granted = {
            gear: g.gear.length, cards: Array.isArray(g.cards) ? g.cards.length : null,
            eggs: Array.isArray(g.eggs) ? g.eggs.length : null,
            balls: Array.isArray(g.balls) ? g.balls.length : null,
            ballTypes: Array.isArray(g.balls) ? [...g.balls] : null,
            mail: g.mail === true,
            items: g.gear.map(item => ({
              itemId: number(item.itemId), quality: number(item.quality),
              source: typeof item.src === 'string' ? item.src : null
            }))
          };
          const missing = missingSources(event.sent, event.granted);
          if (missing.length) event.granted.missingSources = missing;
          event.again = data.again === true;
        } else if (data.granted?.mail) {
          event.granted = { mail: true };
        }
      } catch {
        event.status = result.ok ? 'unreadable-response' : 'http-error';
      }
      update();
    }

    return {
      start() {
        if (restoreFetch) return;
        const original = window.fetch;
        const descriptor = Object.getOwnPropertyDescriptor(window, 'fetch');
        let active = true;
        function wrapped(...args) {
          let ticket = null;
          try { if (active) ticket = request(...args); } catch {}
          // Return the exact original promise, including its rejection behavior.
          const result = original.apply(this, args);
          if (ticket) result.then(
            value => response(ticket, value),
            () => {
              if (ticket.revision !== revision) return;
              ticket.event.status = 'network-error';
              update();
            }
          ).catch(() => {});
          return result;
        }
        window.fetch = wrapped;
        restoreFetch = () => {
          active = false;
          if (window.fetch !== wrapped) return;
          if (descriptor) Object.defineProperty(window, 'fetch', descriptor);
          else delete window.fetch;
        };
        update();
      },
      stop() {
        revision++;
        restoreFetch?.();
        restoreFetch = null;
        update();
      },
      summary() {
        const run = [...runs.values()].at(-1);
        if (!run) return restoreFetch ? '診斷已開啟，等待下一局' : '診斷已關閉';
        const event = run.events.findLast(e => e.op === 'loot' || e.op === 'gloot')
          ?? run.events.at(-1);
        const sent = event.sent?.gear ?? '—';
        const received = event.granted?.mail ? '郵差領取'
          : event.granted?.gear ?? (event.status === 'pending' ? '等待中' : '未確認');
        const balls = event.granted?.balls;
        const maxGearIndex = event.sent?.maxGearIndex;
        const missing = event.granted?.missingSources;
        const missingText = Array.isArray(missing) && missing.length
          ? `（少：${missing.slice(0, 3).map(m => `${m.source}×${m.missing}`).join('、')}${missing.length > 3 ? '…' : ''}）`
          : '';
        return `第 ${run.id} 局：送出 ${sent} 件／核發 ${received}${missingText}` +
          (typeof maxGearIndex === 'number' && maxGearIndex > 0 ? ` · 最大序號 #${maxGearIndex}` : '') +
          (typeof balls === 'number' && balls > 0 ? ` · 扭蛋 ${balls}` : '') +
          ` · 遊戲 ${event.gameSeconds ?? '—'} 秒／` +
          `${run.completeStart ? '實際' : '記錄期間'} ${event.atSeconds} 秒` +
          (['http-error', 'network-error', 'unreadable-response'].includes(event.status)
            ? ` · ${event.httpStatus ?? ''} ${event.error ?? event.status}` : '') +
          (event.op === 'gloot' ? '（組隊分配未追蹤）' : '');
      },
      exportData() {
        return JSON.parse(JSON.stringify({
          version: 1, scriptVersion: '0.6.27', exported: new Date().toISOString(),
          note: 'Only observed traffic. Missing starts, resumed runs, mail and group loot require separate confirmation.',
          runs: [...runs.values()].map(({ start, ...run }) => run)
        }));
      }
    };
  }

  /**
   * Define a method override without adding an enumerable own property so
   * `Object.keys(target)` does not expose patched instance methods.
   */
  function patchHiddenMethod(target, name, wrapped, descriptor) {
    Object.defineProperty(target, name, {
      value: wrapped,
      writable: true,
      configurable: true,
      enumerable: descriptor ? descriptor.enumerable : false
    });
  }

  function restorePatchedMethod(target, name, wrapped, descriptor) {
    if (target[name] !== wrapped) return;
    if (descriptor) Object.defineProperty(target, name, descriptor);
    else delete target[name];
  }

  function installStatBoosts(game, state) {
    if (
      typeof game.recomputeStats !== 'function' ||
      !game.runCtx?.locals
    ) {
      throw Error('屬性介面不相容');
    }

    const original = game.recomputeStats;
    const descriptor = Object.getOwnPropertyDescriptor(game, 'recomputeStats');

    let tracked = null;
    let baseDamageMult = 1;
    let baseDiscovery = 0;
    let baseMoney = 1;
    let baseReach = 1;
    let baseArea = 1;
    let appliedDamageMult = 1;
    let appliedDiscovery = 0;
    let appliedMoney = 1;
    let appliedReach = 1;
    let appliedArea = 1;
    let attackFactor = 1;
    let extra = 0;
    let moneyFactor = 1;
    let reachFactor = 1;

    function release() {
      if (tracked) {
        if (Number.isFinite(tracked.damageMult)) tracked.damageMult /= attackFactor;
        if (Number.isFinite(tracked.discovery)) tracked.discovery -= extra;
        if (Number.isFinite(tracked.zenyMult)) tracked.zenyMult /= moneyFactor;
        if (Number.isFinite(tracked.reachMult)) tracked.reachMult /= reachFactor;
        if (Number.isFinite(tracked.areaMult)) tracked.areaMult /= reachFactor;
      }
      tracked = null;
      attackFactor = 1;
      extra = 0;
      moneyFactor = 1;
      reachFactor = 1;
    }

    function sync() {
      const stats = game.stats;

      if (
        !Number.isFinite(stats?.discovery) ||
        !Number.isFinite(stats?.zenyMult)
      ) {
        throw Error('尋寶率／金錢屬性不相容');
      }

      if (tracked !== stats) {
        release();
        tracked = stats;
        baseDamageMult = Number.isFinite(stats.damageMult) ? stats.damageMult : 1;
        baseDiscovery = stats.discovery;
        baseMoney = stats.zenyMult;
        baseReach = Number.isFinite(stats.reachMult) ? stats.reachMult : 1;
        baseArea = Number.isFinite(stats.areaMult) ? stats.areaMult : 1;
        appliedDamageMult = baseDamageMult;
        appliedDiscovery = baseDiscovery;
        appliedMoney = baseMoney;
        appliedReach = baseReach;
        appliedArea = baseArea;
      } else {
        if (Number.isFinite(stats.damageMult) && stats.damageMult !== appliedDamageMult) {
          baseDamageMult += (stats.damageMult - appliedDamageMult) / attackFactor;
        }
        if (stats.discovery !== appliedDiscovery) {
          baseDiscovery += stats.discovery - appliedDiscovery;
        }
        if (stats.zenyMult !== appliedMoney) {
          baseMoney += (stats.zenyMult - appliedMoney) / moneyFactor;
        }
        if (Number.isFinite(stats.reachMult) && stats.reachMult !== appliedReach) {
          baseReach += (stats.reachMult - appliedReach) / reachFactor;
        }
        if (Number.isFinite(stats.areaMult) && stats.areaMult !== appliedArea) {
          baseArea += (stats.areaMult - appliedArea) / reachFactor;
        }
      }

      attackFactor = clamp(state.attack, 100);
      extra = Math.max(0, Math.min(300, Number(state.discovery) || 0)) / 100;
      moneyFactor = clamp(state.money, 100);
      reachFactor = clamp(state.reach, 20);

      appliedDamageMult = baseDamageMult * attackFactor;
      appliedDiscovery = baseDiscovery + extra;
      appliedMoney = baseMoney * moneyFactor;
      appliedReach = baseReach * reachFactor;
      appliedArea = baseArea * reachFactor;

      if (Number.isFinite(stats.damageMult)) stats.damageMult = appliedDamageMult;
      stats.discovery = appliedDiscovery;
      stats.zenyMult = appliedMoney;
      if (Number.isFinite(stats.reachMult)) stats.reachMult = appliedReach;
      if (Number.isFinite(stats.areaMult)) stats.areaMult = appliedArea;

      const locals = game.runCtx.locals;
      let quality = locals.get('quality');

      if (!quality) {
        quality = { discovery: 0 };
        locals.set('quality', quality);
      }

      quality.discovery =
        Math.max(0, appliedDiscovery) / (1 + Math.max(0, appliedDiscovery));
    }

    function wrapped(...args) {
      release();
      const result = original.apply(this, args);
      sync();
      return result;
    }

    patchHiddenMethod(game, 'recomputeStats', wrapped, descriptor);

    try {
      sync();
    } catch (error) {
      restorePatchedMethod(game, 'recomputeStats', wrapped, descriptor);
      throw error;
    }

    return {
      refresh: sync,
      value() {
        return { base: baseDiscovery, total: appliedDiscovery };
      },
      restore() {
        release();

        const value = Math.max(0, Number(game.stats?.discovery) || 0);
        const quality = game.runCtx.locals.get('quality');
        if (quality) quality.discovery = value / (1 + value);

        restorePatchedMethod(game, 'recomputeStats', wrapped, descriptor);
      }
    };
  }

  function installCooldown(game, state) {
    const weapons = new Map();

    const factor = () =>
      1 - Math.max(0, Math.min(99, Number(state.cooldown) || 0)) / 100;

    function scaleTimer(weapon, ratio) {
      if (weapon.waiting || weapon.casting) return;

      if (
        Number.isFinite(weapon.timer) &&
        weapon.timer > 0 &&
        Number.isFinite(weapon.cdTotal) &&
        weapon.cdTotal > 0
      ) {
        weapon.timer *= ratio;
        weapon.cdTotal *= ratio;
      }
    }

    function undo(weapon, record) {
      if (weapon.finalCooldown !== record.wrapped) return;

      scaleTimer(weapon, 1 / record.factor);
      restorePatchedMethod(weapon, 'finalCooldown', record.wrapped, record.descriptor);
    }

    function sync() {
      const current = game.weapons || [];
      const next = factor();

      if (
        current.length === weapons.size &&
        next === lastFactor &&
        current.every(w => weapons.has(w))
      ) {
        return;
      }
      lastFactor = next;

      for (const weapon of current) {
        if (typeof weapon.finalCooldown !== 'function') continue;

        let record = weapons.get(weapon);

        if (!record) {
          const original = weapon.finalCooldown;
          const descriptor = Object.getOwnPropertyDescriptor(
            weapon,
            'finalCooldown'
          );

          const wrapped = function(...args) {
            const result = original.apply(this, args);

            return args[0] === game && Number.isFinite(result) && result > 0
              ? result * factor()
              : result;
          };

          record = { original, descriptor, wrapped, factor: 1 };
          patchHiddenMethod(weapon, 'finalCooldown', wrapped, descriptor);
          weapons.set(weapon, record);
        }

        if (record.factor !== next) {
          scaleTimer(weapon, next / record.factor);
          record.factor = next;
        }
      }

      for (const [weapon, record] of weapons) {
        if (!current.includes(weapon)) {
          undo(weapon, record);
          weapons.delete(weapon);
        }
      }
    }

    let lastFactor = NaN;
    sync();

    return {
      refresh: sync,
      restore() {
        for (const [weapon, record] of weapons) undo(weapon, record);
        weapons.clear();
      }
    };
  }

  function installGameHook(hooks, state, report) {
    if (
      !hooks ||
      !['start', 'tick', 'hurt', 'damageMult'].every(k =>
        Array.isArray(hooks[k])
      )
    ) {
      throw Error('找不到相容遊戲回呼');
    }

    const games = new Map();
    const neutralizedProtos = new Map();
    let latest = null;
    let disposed = false;

    function neutralizeSpringTrap(game) {
      purgeTrapFlags();
      if (Number.isFinite(game.trapAt) && game.trapAt >= 0) game.trapAt = -1;
      if (Number.isFinite(game.trapShotAt) && game.trapShotAt >= 0) game.trapShotAt = -1;
      const proto = Object.getPrototypeOf(game);
      if (
        !proto ||
        proto === Object.prototype ||
        neutralizedProtos.has(proto) ||
        typeof proto.springTrap !== 'function'
      ) return;

      const descriptor = Object.getOwnPropertyDescriptor(proto, 'springTrap');
      const noop = function() {};
      patchHiddenMethod(proto, 'springTrap', noop, descriptor);
      neutralizedProtos.set(proto, { descriptor, noop });
    }

    function pickBestLevelChoice(choices) {
      if (!Array.isArray(choices) || !choices.length) return null;
      let best = choices[0];
      let bestScore = -Infinity;
      for (let i = 0; i < choices.length; i++) {
        const c = choices[i];
        if (!c) continue;
        // Prefer real skills/upgrades over fallback meat ('potion'), then higher rarity,
        // then new skills when rarity ties, preserving left-to-right order on ties.
        const isPotion = c.id === 'potion';
        const rarity = Number.isFinite(c.rarity) ? c.rarity : 0;
        const isNew = c.isNew ? 1 : 0;
        const score = (isPotion ? -1000 : 0) + rarity * 100 + isNew * 10 - i * 0.01;
        if (score > bestScore) {
          bestScore = score;
          best = c;
        }
      }
      return best;
    }

    function pickBestChestChoice(game, choices) {
      if (!Array.isArray(choices) || !choices.length) return null;
      let best = choices[0];
      let bestScore = -Infinity;
      for (let i = 0; i < choices.length; i++) {
        const c = choices[i];
        if (!c) continue;
        // Prefer focused items and higher quality (`c.quality`), then upgrades to an
        // existing equipped item (or empty slot) over replacing a different item.
        const quality = Number.isFinite(c.quality) ? c.quality : 0;
        const focused = game?.focusedItems?.has?.(c.item) ? 1 : 0;
        const existing = game?.runItems?.slots?.get?.(c.slot);
        const sameOrEmpty = !existing || existing.item === c.item ? 1 : 0;
        const refine = Number.isFinite(c.refine) ? c.refine : 0;
        const score = focused * 10000 + quality * 100 + sameOrEmpty * 10 + refine - i * 0.01;
        if (score > bestScore) {
          bestScore = score;
          best = c;
        }
      }
      return best;
    }

    function installBackgroundAutoPick(game, record) {
      const events = game?.events;
      if (!events || typeof events !== 'object' || record.eventsPatched) return;
      record.eventsPatched = true;

      const origLevelUp = events.onLevelUp;
      const origChest = events.onChest;

      if (typeof origLevelUp === 'function') {
        events.onLevelUp = function(choices, ...rest) {
          if (
            !disposed &&
            state.autoPick &&
            typeof game.choose === 'function' &&
            Array.isArray(choices) &&
            choices.length > 0
          ) {
            const pick = pickBestLevelChoice(choices);
            if (pick) {
              queueMicrotask(() => {
                try {
                  if (disposed || game.state !== 'levelup' || game.pendingLevelUps <= 0) return;
                  game.choose(pick);
                } catch {}
              });
              return;
            }
          }
          return origLevelUp.call(this, choices, ...rest);
        };
      }

      if (typeof origChest === 'function') {
        events.onChest = function(choices, cost, ...rest) {
          if (
            !disposed &&
            state.autoPick &&
            typeof game.chooseChest === 'function' &&
            Array.isArray(choices) &&
            choices.length > 0
          ) {
            const pick = pickBestChestChoice(game, choices);
            if (pick) {
              queueMicrotask(() => {
                try {
                  if (disposed || game.state !== 'levelup') return;
                  game.chooseChest(pick);
                } catch {}
              });
              return;
            }
          }
          return origChest.call(this, choices, cost, ...rest);
        };
      }

      record.restoreEvents = () => {
        if (typeof origLevelUp === 'function' && events.onLevelUp !== origLevelUp) {
          events.onLevelUp = origLevelUp;
        }
        if (typeof origChest === 'function' && events.onChest !== origChest) {
          events.onChest = origChest;
        }
        record.eventsPatched = false;
      };
    }

    function syncWeapons(game, record) {
      const current = game.weapons || [];
      if (
        current.length === record.lastWeaponCount &&
        current.every(w => record.weapons.has(w))
      ) {
        return;
      }
      record.lastWeaponCount = current.length;

      for (const weapon of current) {
        if (record.weapons.has(weapon)) continue;

        const patches = [];
        for (const method of ['targetCount', 'targets']) {
          if (typeof weapon[method] !== 'function') continue;

          const original = weapon[method];
          const descriptor = Object.getOwnPropertyDescriptor(weapon, method);

          const wrapped = function(...args) {
            const result = original.apply(this, args);
            return args[0] === game && Number.isFinite(result)
              ? Math.ceil(result * clamp(state.targets, 100))
              : result;
          };

          patchHiddenMethod(weapon, method, wrapped, descriptor);
          patches.push({ method, original, descriptor, wrapped });
        }

        if (patches.length) {
          record.weapons.set(weapon, patches);
        }
      }

      for (const [weapon, patches] of record.weapons) {
        if (current.includes(weapon)) continue;

        for (const p of patches) {
          restorePatchedMethod(weapon, p.method, p.wrapped, p.descriptor);
        }
        record.weapons.delete(weapon);
      }
    }

    function syncHealth(game, record, full = false) {
      if (full) {
        record.discovery?.refresh();
      }
      record.cooldowns?.refresh();
      if (!record.eventsPatched) {
        installBackgroundAutoPick(game, record);
      }

      if (
        !disposed &&
        state.autoPick &&
        state.immediateCards &&
        game.state === 'playing' &&
        !game.bankOpen &&
        !game.player?.dead &&
        !game.coop?.replica
      ) {
        if (
          game.banking &&
          Number(game.bankCount) > 0 &&
          typeof game.pressBank === 'function'
        ) {
          try { game.pressBank(); } catch {}
        } else if (
          Number(game.pendingLevelUps) > 0 &&
          !game.levelUpQueued &&
          typeof game.openLevelUp === 'function'
        ) {
          try { game.openLevelUp(); } catch {}
        }
      }

      syncWeapons(game, record);

      if (state.god) {
        if (!record.godApplied) {
          record.godBefore = game.god;
          record.godApplied = true;
        }
        game.god = true;
      } else if (record.godApplied) {
        game.god = record.godBefore;
        record.godApplied = false;
      }

      if (
        state.fullHp &&
        !game.player.dead &&
        game.state !== 'over' &&
        Number.isFinite(game.stats.maxHp)
      ) {
        game.hp = game.stats.maxHp;
      }
    }

    function patch(game, record, name, make) {
      const original = game[name];
      if (typeof original !== 'function') {
        throw Error('遊戲介面缺少 ' + name);
      }

      const descriptor = Object.getOwnPropertyDescriptor(game, name);
      const wrapped = make(original);

      patchHiddenMethod(game, name, wrapped, descriptor);
      record.methods.push({ name, original, descriptor, wrapped });
    }

    function undo(game, record) {
      record.discovery?.restore();
      record.cooldowns?.restore();
      record.restoreEvents?.();

      for (const [weapon, patches] of record.weapons) {
        for (const p of patches) {
          restorePatchedMethod(weapon, p.method, p.wrapped, p.descriptor);
        }
      }

      record.weapons.clear();

      if (record.godApplied) game.god = record.godBefore;

      for (const p of record.methods) {
        restorePatchedMethod(game, p.name, p.wrapped, p.descriptor);
      }
    }

    const VALUABLE_PICKUP_KINDS = new Set(['drop', 'ball', 'loot', 'card', 'egg', 'mystery']);
    // Under accelerated runs, keep per-species drop indices low and proportional
    // to expected wall-clock duration so the 10-item `mobLoot` cap is filled by
    // low kill indices across multiple monster waves instead of overkill indices
    // that the server rejects when wall-clock time is short.
    const AUTO_MOB_KILL_MIN = 80;
    const AUTO_MOB_KILL_MAX = 450;
    const AUTO_MOB_KILL_PER_WALL_SEC = 4;

    function hasPendingValuablePickups(game) {
      if (!Array.isArray(game?.pickups)) return false;
      for (const item of game.pickups) {
        if (!item?.dead && VALUABLE_PICKUP_KINDS.has(item?.kind)) return true;
      }
      return false;
    }

    function safeMobKillLimit(record) {
      const manual = Number(state.mobDropCap);
      if (Number.isFinite(manual) && manual > 0) return Math.round(manual);
      const speed = clamp(state.speed, 20);
      const real = Number.isFinite(record?.realTime) ? Math.max(0, record.realTime) : 0;
      const estWallSecs = Math.max(20, 900 / speed);
      const wallBudget = Math.max(real, estWallSecs);
      return Math.max(
        AUTO_MOB_KILL_MIN,
        Math.min(AUTO_MOB_KILL_MAX, Math.round(wallBudget * AUTO_MOB_KILL_PER_WALL_SEC))
      );
    }

    function observe(game) {
      if (disposed || !game?.player || !game.stats) return null;

      latest = game;
      let record = games.get(game);

      if (!record) {
        neutralizeSpringTrap(game);
        const initialTime = Number.isFinite(game.time) ? Math.max(0, game.time) : 0;
        record = {
          godApplied: false,
          methods: [],
          weapons: new Map(),
          lastWeaponCount: -1,
          discovery: null,
          cooldowns: null,
          accelerated: false,
          realTime: initialTime,
          lastGameTime: initialTime
        };

        games.set(game, record);

        try {
          record.discovery = installStatBoosts(game, state);
          record.cooldowns = installCooldown(game, state);

          const syncRealTime = (gameObj, beforeTime, factorUsed) => {
            if (!Number.isFinite(gameObj.time)) return;
            if (gameObj.time < record.lastGameTime) {
              record.realTime = Math.max(0, gameObj.time);
            } else if (Number.isFinite(beforeTime)) {
              record.realTime += Math.max(0, gameObj.time - beforeTime) / factorUsed;
            }
            record.lastGameTime = gameObj.time;
          };

          patch(game, record, 'advance', original => function(dt) {
            const requestedFactor = clamp(state.speed, 20);
            const closing = Number.isFinite(this.closeAt) && this.closeAt >= 0;
            // During the post-boss victory window (closeAt >= 0), cap speed at 3x
            // (~3.3s real time for the 10s countdown) so flying drops/balls and
            // the 3s `onDrop` tick timer settle cleanly without waiting a full 10s.
            const factor = closing ? Math.min(requestedFactor, 3) : requestedFactor;

            if (!disposed) {
              syncHealth(this, record, false);
            }

            if (
              disposed ||
              factor === 1 ||
              this.state !== 'playing' ||
              !Number.isFinite(dt) ||
              dt <= 0
            ) {
              if (this.state === 'playing') {
                if (requestedFactor > 1) record.accelerated = true;
                const beforeTime = Number.isFinite(this.time) ? this.time : NaN;
                const result = original.call(this, dt);
                syncRealTime(this, beforeTime, 1);
                return result;
              }
              return original.call(this, dt);
            }

            record.accelerated = true;
            const beforeTime = Number.isFinite(this.time) ? this.time : NaN;
            // Game native advance(dt) caps each call at 5 steps (5 * 0.025s = 0.125s).
            // Batch into chunks of up to 0.1s per call to reduce call overhead while
            // respecting the 8ms frame budget so rendering stays smooth.
            const totalDt = dt * factor;
            const count = Math.max(1, Math.ceil(totalDt / 0.1));
            const stepDt = totalDt / count;
            const began = performance.now();
            let result;

            for (let i = 0; i < count; i++) {
              if (disposed || this.state !== 'playing') break;
              if (!closing && Number.isFinite(this.closeAt) && this.closeAt >= 0) break;

              if (i > 0 && performance.now() - began >= 8) {
                state.limited++;
                break;
              }

              result = original.call(this, stepDt);
            }

            syncRealTime(this, beforeTime, factor);
            return result;
          });

          patch(game, record, 'updatePickups', original => function(dt) {
            if (disposed) return original.call(this, dt);

            const stats = this.stats;
            const base = stats.pickupRadius;
            const bonus = this.buffValue('pickup');
            const radius = (base + bonus) * clamp(state.pickup, 100);
            const suction = clamp(state.suction, 10);
            const closing = Number.isFinite(this.closeAt) && this.closeAt >= 0;
            const activeSuction = closing ? Math.max(suction, 6) : suction;

            stats.pickupRadius = radius - bonus;

            try {
              if (
                dt > 0 &&
                !this.player.dead &&
                !this.coop?.replica &&
                (activeSuction > 1 || closing)
              ) {
                for (const item of this.pickups) {
                  if (item.dead || item.kind === 'chest') continue;
                  if (item.kind === 'mystery') {
                    if (closing && Number.isFinite(item.fadeAt) && Number.isFinite(this.time)) {
                      item.fadeAt = Math.min(item.fadeAt, this.time);
                    }
                    continue;
                  }

                  if (closing && VALUABLE_PICKUP_KINDS.has(item.kind)) {
                    item.magnet = true;
                    item.vacuum = true;
                  }

                  const dx = this.player.x - item.x;
                  const dy = this.player.y - 20 - item.y;
                  const distSq = dx * dx + dy * dy;
                  const reach = radius * (item.kind === 'drop' ? 3.5 : 1);

                  if (
                    !(distSq > 324) ||
                    (!item.magnet && distSq >= reach * reach)
                  ) continue;

                  const distance = Math.sqrt(distSq);

                  const speed = item.vacuum
                    ? 1100
                    : item.kind === 'drop'
                      ? 600
                      : 420;

                  const extra = Math.min(
                    distance - 18,
                    speed * dt * Math.max(0, activeSuction - 1)
                  );

                  if (extra > 0) {
                    item.x += dx / distance * extra;
                    item.y += dy / distance * extra;
                  }
                }
              }

              // Hold closeAt by one step if valuable drops/balls are still mid-flight
              // right as the victory timer expires (bounded to 5 extra game seconds).
              if (
                closing &&
                Number.isFinite(this.time) &&
                this.time + dt >= this.closeAt &&
                hasPendingValuablePickups(this)
              ) {
                record.closeExtended = (record.closeExtended ?? 0) + dt;
                if (record.closeExtended <= 5) {
                  this.closeAt = this.time + Math.max(0.25, dt * 2);
                }
              }

              return original.call(this, dt);
            } finally {
              stats.pickupRadius = base;
            }
          });

          if (typeof game.mysteryDrop === 'function') {
            patch(game, record, 'mysteryDrop', original => function(enemy, cardId, cardMult, dropMult) {
              if (
                !disposed &&
                (record.accelerated || clamp(state.speed, 20) > 1) &&
                enemy?.def &&
                !enemy.def.boss &&
                typeof this.killsBy?.get === 'function'
              ) {
                const mobId = enemy.def.id;
                const killIdx = this.killsBy.get(mobId) ?? 1;
                if (killIdx > safeMobKillLimit(record)) {
                  record.suppressedMobDrops = (record.suppressedMobDrops ?? 0) + 1;
                  return;
                }
              }
              return original.call(this, enemy, cardId, cardMult, dropMult);
            });
          }
        } catch (error) {
          undo(game, record);
          games.delete(game);
          report('新功能未接上：' + error.message);
          return;
        }

        for (const [oldGame, oldRecord] of games) {
          if (oldGame !== game && oldGame.state === 'over') {
            undo(oldGame, oldRecord);
            games.delete(oldGame);
          }
        }
      }

      syncHealth(game, record, true);
      return record;
    }

    const hookedSlots = [];

    function wrapExistingSlot(list, index, wrapperFactory) {
      if (!Array.isArray(list) || typeof list[index] !== 'function') return false;
      const original = list[index];
      const wrapped = wrapperFactory(original);
      list[index] = wrapped;
      hookedSlots.push({ list, index, original, wrapped });
      return true;
    }

    const wrappedStart = wrapExistingSlot(hooks.start, 0, orig => function(game, ...rest) {
      observe(game);
      return orig.call(this, game, ...rest);
    });

    const wrappedHurt = wrapExistingSlot(hooks.hurt, 0, orig => function(game, damage, ...rest) {
      if (!games.has(game)) observe(game);
      if (state.god) return 0;
      return orig.call(this, game, damage, ...rest);
    });

    const wrappedDamage = wrapExistingSlot(hooks.damageMult, 0, orig => function(game, ...rest) {
      if (!games.has(game)) observe(game);
      return orig.call(this, game, ...rest);
    });

    const pushedHooks = [];
    if (!wrappedStart) {
      const onStart = game => observe(game);
      hooks.start.push(onStart);
      pushedHooks.push([hooks.start, onStart]);
    }
    if (!wrappedHurt) {
      const guard = (game, damage) => {
        if (!games.has(game)) observe(game);
        return state.god ? 0 : damage;
      };
      hooks.hurt.push(guard);
      pushedHooks.push([hooks.hurt, guard]);
    }
    if (!wrappedDamage) {
      const damage = game => {
        if (!games.has(game)) observe(game);
        return 1;
      };
      hooks.damageMult.push(damage);
      pushedHooks.push([hooks.damageMult, damage]);
    }

    return {
      refresh() {
        for (const [game, record] of games) syncHealth(game, record, true);
      },
      status() {
        const record = latest && games.get(latest);
        if (!record) return '尋寶率：等待進入戰鬥';

        const value = record.discovery.value();
        return `尋寶率：${(value.base * 100).toFixed(0)}% → ${(value.total * 100).toFixed(0)}%`;
      },
      restore() {
        disposed = true;

        for (const { list, index, original, wrapped } of hookedSlots) {
          if (list[index] === wrapped) list[index] = original;
        }
        hookedSlots.length = 0;

        for (const [list, fn] of pushedHooks) {
          const at = list.indexOf(fn);
          if (at >= 0) list.splice(at, 1);
        }
        pushedHooks.length = 0;

        for (const [game, record] of games) undo(game, record);
        games.clear();
        for (const [proto, entry] of neutralizedProtos) {
          restorePatchedMethod(proto, 'springTrap', entry.noop, entry.descriptor);
        }
        neutralizedProtos.clear();
        latest = null;
      }
    };
  }

  function isDecoyPickerElement(el) {
    if (!el || typeof el.closest !== 'function') return true;
    const slot = el.closest('.pk-slot') || el;
    if (
      slot.getAttribute('aria-hidden') === 'true' ||
      el.getAttribute('aria-hidden') === 'true' ||
      slot.closest('[aria-hidden="true"]') ||
      slot.style?.pointerEvents === 'none' ||
      slot.style?.clipPath ||
      el.tabIndex === -1
    ) {
      return true;
    }
    return false;
  }

  function invokeCardHandler(node) {
    if (!node || isDecoyPickerElement(node)) return false;
    if (typeof node.onclick === 'function') {
      node.onclick({
        isTrusted: false,
        detail: 0,
        pointerType: '',
        clientX: 0,
        clientY: 0,
        target: node,
        currentTarget: node,
        preventDefault() {},
        stopPropagation() {},
        stopImmediatePropagation() {}
      });
      return true;
    }
    return false;
  }

  function classifyTimer(fn, delay) {
    if (typeof fn !== 'function') return false;

    const code = Function.prototype.toString.call(fn);

    return (
      delay === 820 &&
      /\.onPick\(/.test(code) &&
      /cancelAnimationFrame\(/.test(code)
    ) || (
      delay === 160 &&
      /\.classList\.toggle\("asking"/.test(code) &&
      code.includes('[data-yes]')
    ) || (
      delay === 450 &&
      /\.unlock\(\)/.test(code) &&
      code.includes('pk-hint')
    );
  }

  function installPickerInputClock(picker, state) {
    const realNow = performance.now.bind(performance);
    let cards = [];
    let started = 0;
    const records = new Map();

    function clear() {
      for (const [node, p] of records) {
        if (node.onclick === p.wrapped) node.onclick = p.original;
      }
      records.clear();
      cards = [];
      started = 0;
    }

    function sync() {
      if (picker.classList.contains('hidden')) {
        clear();
        return;
      }

      const current = Array.from(picker.querySelectorAll('.pk-card'))
        .filter(node => !isDecoyPickerElement(node));

      if (
        current.length !== cards.length ||
        current.some((node, i) => node !== cards[i])
      ) {
        clear();
        cards = current;
        started = realNow();
      }

      for (const node of [
        ...cards,
        ...Array.from(picker.querySelectorAll('[data-yes]'))
          .filter(n => !isDecoyPickerElement(n))
      ]) {
        const old = records.get(node);
        if (old && node.onclick === old.wrapped) continue;

        const original = node.onclick;
        if (typeof original !== 'function') continue;

        const wrapped = function(...args) {
          const factor = clamp(state.animation, 10);
          const elapsed = Math.max(0, realNow() - started);

          if (factor === 1) return original.apply(this, args);

          if (elapsed < Math.max(80, (380 + cards.length * 120) / factor)) {
            return;
          }

          const descriptor = Object.getOwnPropertyDescriptor(
            performance,
            'now'
          );
          const offset = elapsed * (factor - 1) + 1;

          try {
            Object.defineProperty(performance, 'now', {
              configurable: true,
              value: () => realNow() + offset
            });
          } catch {
            state.inputClockBlocked = true;
            return original.apply(this, args);
          }

          try {
            return original.apply(this, args);
          } finally {
            if (descriptor) {
              Object.defineProperty(performance, 'now', descriptor);
            } else {
              delete performance.now;
            }
          }
        };

        node.onclick = wrapped;
        records.set(node, { original, wrapped });
      }
    }

    function keyboard(event) {
      if (
        !cards.length ||
        picker.classList.contains('hidden') ||
        picker.classList.contains('closing')
      ) return;

      const target = event.target;

      if (
        target?.isContentEditable ||
        /^(INPUT|TEXTAREA|SELECT)$/.test(target?.tagName || '')
      ) return;

      let node = null;

      if (/^[1-9]$/.test(event.key)) {
        node = cards[Number(event.key) - 1];
      } else if (event.key === 'Enter') {
        node = Array.from(picker.querySelectorAll('.pk-slot.asking [data-yes]'))
          .find(n => !isDecoyPickerElement(n));
      }

      if (!node) return;

      event.preventDefault();
      event.stopImmediatePropagation();

      if (!event.repeat) invokeCardHandler(node);
    }

    window.addEventListener('keydown', keyboard, true);
    sync();

    return {
      refresh: sync,
      restore() {
        window.removeEventListener('keydown', keyboard, true);
        clear();
      }
    };
  }

  function installAnimationHook(state) {
    const timeout = window.setTimeout;
    const raf = window.requestAnimationFrame;
    const cancel = window.cancelAnimationFrame;

    const animations = new Map();

    let disposed = false;
    let pending = 0;
    let inputClock = null;

    function scaledTimeout(fn, delay, ...args) {
      const ms = !disposed && classifyTimer(fn, Number(delay))
        ? Number(delay) / clamp(state.animation, 10)
        : delay;

      return timeout.call(window, fn, ms, ...args);
    }

    function applyCSS() {
      pending = 0;
      if (disposed) return;

      const picker = document.getElementById('picker');
      if (!picker) return;

      if (!inputClock) {
        inputClock = installPickerInputClock(picker, state);
      } else {
        inputClock.refresh();
      }

      for (const [animation] of animations) {
        if (
          animation.playState === 'finished' ||
          animation.playState === 'idle'
        ) animations.delete(animation);
      }

      for (const animation of picker.getAnimations({ subtree: true })) {
        if (!animations.has(animation)) {
          animations.set(animation, animation.playbackRate);
        }
        animation.playbackRate =
          animations.get(animation) * clamp(state.animation, 10);
      }
    }

    const observer = new MutationObserver(() => {
      if (!pending) pending = raf.call(window, applyCSS);
    });

    const picker = document.getElementById('picker');

    if (picker) {
      observer.observe(picker, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'style']
      });
    }

    window.setTimeout = scaledTimeout;
    applyCSS();

    return {
      refresh: applyCSS,
      restore() {
        disposed = true;
        observer.disconnect();
        inputClock?.restore();

        if (pending) cancel.call(window, pending);

        if (window.setTimeout === scaledTimeout) {
          window.setTimeout = timeout;
        }

        for (const [animation, rate] of animations) {
          try {
            animation.playbackRate = rate;
          } catch {}
        }
        animations.clear();
      }
    };
  }

  // Use the plaza's own touch-to-walk pathfinding. Its NPC coordinates come
  // from the rendered actors, so camera rotation and zoom do not affect them.
  function createLobbyNavigator(loadModule, doc = document,
    clock = () => performance.now()) {
    const lobbyNpcs = {
      captain: { sprite: 'npc_knight', label: '遠征隊長' },
      festival: { sprite: 'npc_lantern_girl', label: '節慶主持人' }
    };
    let revision = 0;
    let loading = false;
    let failure = '';
    let unhook = null;
    let scene = null;
    let moving = false;
    let currentTarget = 'captain';
    let sentAt = -Infinity;
    let progressAt = 0;
    let bestDistance = Infinity;

    const inLobby = () => doc.body.classList.contains('in-lobby');
    const menuOpen = () => !!doc.querySelector('.overlay:not(.hidden)');
    const usable = value => Number.isFinite(value?.x) && Number.isFinite(value?.y);

    function walkTo(point) {
      const stage = doc.getElementById('stage');
      const win = doc.defaultView;
      const world = scene?.world;
      if (!stage || !win?.PointerEvent || !world || world.gone ||
          !inLobby() || doc.hidden || menuOpen() || !usable(point)) return false;
      const original = world.pickGround;
      const descriptor = Object.getOwnPropertyDescriptor(world, 'pickGround');
      if (typeof original !== 'function') throw Error('找不到廣場尋路介面');
      // A synchronous synthetic tap supplies the exact world destination to
      // the normal moveTo handler; it never assigns the character's position.
      const pick = () => ({ x: point.x, y: point.y });
      world.pickGround = pick;
      try {
        // Outside the viewport avoids opening another player's context menu.
        const options = {
          bubbles: true, cancelable: true, pointerType: 'touch',
          pointerId: 5253, isPrimary: true, button: 0,
          clientX: -10000, clientY: -10000
        };
        stage.dispatchEvent(new win.PointerEvent('pointerdown', { ...options, buttons: 1 }));
        win.dispatchEvent(new win.PointerEvent('pointerup', { ...options, buttons: 0 }));
      } finally {
        if (world.pickGround === pick) {
          if (descriptor) Object.defineProperty(world, 'pickGround', descriptor);
          else delete world.pickGround;
        }
      }
      return true;
    }

    function connect() {
      if (loading || unhook || failure) return;
      loading = true;
      const request = ++revision;
      Promise.resolve().then(loadModule).then(module => {
        if (request !== revision) return;
        const values = module && typeof module === 'object' ? Object.values(module) : [];
        const World = (typeof module?.ed?.prototype?.render === 'function' ? module.ed : null)
          ?? values.find(v => typeof v === 'function' && typeof v.prototype?.render === 'function' && typeof v.prototype?.pickGround === 'function');
        const sheets = (typeof module?.cn?.sheetInfo === 'function' ? module.cn : null)
          ?? values.find(v => v && typeof v === 'object' && typeof v.sheetInfo === 'function');
        const prototype = World?.prototype;
        if (typeof prototype?.render !== 'function' ||
            typeof sheets?.sheetInfo !== 'function') {
          throw Error('版本不符，無法連接廣場尋路');
        }
        const original = prototype.render;
        const descriptor = Object.getOwnPropertyDescriptor(prototype, 'render');
        const sheetCache = {};
        let lastScanAt = -Infinity;
        let cachedNpcs = null;
        let cachedWorld = null;
        let active = true;
        function wrapped(layer, ...args) {
          const result = original.call(this, layer, ...args);
          try {
            if (active && inLobby() && this.map?.name === 'prontera_plaza' && !this.gone) {
              const now = clock();
              const player = { x: this.target?.x, y: this.target?.z };
              if (this !== cachedWorld || !cachedNpcs || now - lastScanAt >= 400) {
                lastScanAt = now;
                cachedWorld = this;
                const npcs = {};
                const children = layer?.children;
                if (Array.isArray(children)) {
                  for (const [key, { sprite }] of Object.entries(lobbyNpcs)) {
                    const sheet = sheetCache[sprite] ??= sheets.sheetInfo(sprite);
                    if (!sheet) continue;
                    const actor = children.find(view =>
                      view.children?.some(child => child.sheet === sheet));
                    if (usable(actor)) npcs[key] = { x: actor.x, y: actor.y };
                  }
                }
                if (Object.keys(npcs).length) cachedNpcs = npcs;
              }
              if (usable(player) && cachedNpcs) {
                scene = {
                  world: this, player, npcs: cachedNpcs,
                  captain: cachedNpcs.captain || null,
                  festival: cachedNpcs.festival || null,
                  at: now
                };
              }
            }
          } catch { failure = '廣場資料不相容，無法定位遠征隊長'; }
          return result;
        }
        prototype.render = wrapped;
        unhook = () => {
          active = false;
          if (prototype.render !== wrapped) return;
          if (descriptor) Object.defineProperty(prototype, 'render', descriptor);
          else delete prototype.render;
        };
      }).catch(error => {
        if (request === revision) failure = error.message;
      }).finally(() => {
        if (request === revision) loading = false;
      });
    }

    return {
      step(targetKey = 'captain') {
        const npcInfo = lobbyNpcs[targetKey] || lobbyNpcs.captain;
        if (currentTarget !== targetKey) {
          currentTarget = targetKey;
          moving = false;
          sentAt = -Infinity;
          bestDistance = Infinity;
        }
        if (failure) throw Error(failure);
        connect();
        if (doc.hidden) return `請切回遊戲分頁，繼續前往${npcInfo.label}`;
        const now = clock();
        const destination = scene?.npcs?.[targetKey] ?? scene?.[targetKey];
        if (!scene || scene.world.gone || now - scene.at > 1500 || !usable(destination)) {
          return `正在定位廣場中的${npcInfo.label}…`;
        }
        const distance = Math.hypot(destination.x - scene.player.x,
          destination.y - scene.player.y);
        if (!moving || distance < bestDistance - 8) {
          bestDistance = distance;
          progressAt = now;
        } else if (now - progressAt > 12000) {
          throw Error(`前往${npcInfo.label}時沒有進展，已停止；請確認路線`);
        }
        if (now - sentAt >= 1800 && walkTo(destination)) {
          sentAt = now;
          moving = true;
        }
        return `正在自動走向${npcInfo.label}…`;
      },
      stop(cancelMovement = true) {
        revision++;
        loading = false;
        // Clicking the current ground position cancels the native walking path.
        try { if (cancelMovement && moving && scene) walkTo(scene.player); }
        catch { /* A disappearing scene must not prevent releasing the hook. */ }
        finally {
          unhook?.();
          unhook = scene = null;
          failure = '';
          moving = false;
          currentTarget = 'captain';
          sentAt = -Infinity;
          bestDistance = Infinity;
        }
      }
    };
  }

  // 一般單人難度；出發前仍核對遊戲控制項的範圍。
  const farmDifficultyFields = [
    ['count', '怪物數量', 350],
    ['damage', '怪物傷害', 175],
    ['hp', '怪物生命', 250],
    ['speed', '怪物速度', 175],
    ['events', '事件數量', 250]
  ];
  const farmDifficultyModes = {
    max: '全滿', normal: '一般（全部 100%）',
    custom: '自訂', current: '沿用遊戲設定'
  };
  const festivalStage = {
    id: 'louyang',
    name: '燈籠節（節慶主持人）',
    blocked: false,
    selected: false
  };

  function createAutoFarm(
    config,
    notify,
    stagesChanged,
    doc = document,
    clock = () => performance.now(),
    navigation = null,
    charactersChanged = null,
    onStopState = null
  ) {
    const one = selector => doc.querySelector(selector);
    const all = selector => Array.from(doc.querySelectorAll(selector));

    const visible = element =>
      !!element &&
      element.isConnected &&
      !element.closest('.hidden,[hidden]') &&
      element.getClientRects().length > 0;

    const shown = selector => visible(one(selector));

    const enabled = element =>
      visible(element) &&
      !element.disabled &&
      element.getAttribute('aria-disabled') !== 'true';

    const parseCardRarityRank = slot => {
      const q = slot?.style?.getPropertyValue?.('--q')?.trim()?.toLowerCase() || '';
      const badge = slot?.querySelector?.('.pk-badge')?.textContent?.trim() || '';
      // Color palette from low to high rarity/quality; new skills or higher quality rank higher.
      const colorScores = {
        '#f2f2f2': 0, '#d9d9d9': 0,
        '#72e384': 1, '#5cd670': 1,
        '#64b5ff': 2, '#4da6ff': 2,
        '#c586ff': 3, '#b366ff': 3,
        '#ffb84d': 4, '#ffa31a': 4,
        '#ff6680': 5, '#ff4d6d': 5
      };
      let score = Object.hasOwn(colorScores, q) ? colorScores[q] * 10 : 0;
      if (/神話|傳說|史詩|稀有|罕見|mític|legend|épic|rar|mythic|epic|rare|uncommon/i.test(badge)) {
        if (/神話|mític|mythic/i.test(badge)) score = Math.max(score, 50);
        else if (/傳說|legend/i.test(badge)) score = Math.max(score, 40);
        else if (/史詩|épic|epic/i.test(badge)) score = Math.max(score, 30);
        else if (/稀有|rar|rare/i.test(badge)) score = Math.max(score, 20);
        else if (/罕見|poco común|uncommon/i.test(badge)) score = Math.max(score, 10);
      }
      if (slot?.querySelector?.('.pk-ribbon')) score += 5;
      if (slot?.querySelector?.('.pk-mark')) score += 100;
      return score;
    };

    function pickBestDomCard(docRef = doc) {
      const valid = el => enabled(el) && !isDecoyPickerElement(el);
      const yes = Array.from(docRef.querySelectorAll('#picker .pk-slot.asking [data-yes]')).find(valid);
      if (yes) return yes;
      const cards = Array.from(docRef.querySelectorAll('#picker .pk-card')).filter(valid);
      if (!cards.length) return null;
      let bestCard = cards[0];
      let bestScore = -Infinity;
      cards.forEach((card, idx) => {
        const slot = card.closest('.pk-slot');
        const score = parseCardRarityRank(slot) - idx * 0.01;
        if (score > bestScore) {
          bestScore = score;
          bestCard = card;
        }
      });
      return bestCard;
    }

    let running = false;
    let reading = false;
    let phase = '';
    let phaseAt = 0;
    let nextAt = 0;
    let completed = 0;
    let signature = '';
    let charSignature = '';
    let choices = [];
    let actions = new Set();
    let deck = null;
    let deckAt = 0;
    let message = '尚未開始';
    let settlementReady = null;
    let readyAt = 0;
    let departedStage = null;
    let reloadAt = 0;
    let reloadReason = '';

    let lastReported = null;
    function say(text) {
      message = text;
      const next = JSON.stringify({ running, reading, completed, text });
      if (next === lastReported) return;
      lastReported = next;
      notify({ running, reading, completed, text });
    }

    function stop(text = '已停止；本局可繼續手動遊玩', clearResume = true) {
      running = reading = false;
      reloadAt = 0;
      reloadReason = '';
      actions.clear();
      navigation?.stop();
      if (clearResume) onStopState?.();
      say(text);
    }

    function scheduleReload(now, reason = '連線異常／維修中') {
      if (!running || !config.autoReconnect) return false;
      if (!reloadAt) {
        reloadAt = now + 5000;
        reloadReason = reason;
        navigation?.stop(false);
      }
      const left = Math.max(0, Math.ceil((reloadAt - now) / 1000));
      if (now >= reloadAt) {
        reloadAt = now + 5000;
        say(`${reloadReason}，正在重新整理…`);
        doc.defaultView?.location?.reload();
      } else {
        say(`${reloadReason}，${left} 秒後重新整理…`);
      }
      return true;
    }

    function refreshCharacters() {
      const slotNodes = all('#slots .slot');
      if (!slotNodes.length) return;
      const scanned = [];
      slotNodes.forEach((slotEl, index) => {
        if (slotEl.classList.contains('empty')) return;
        const name = slotEl.querySelector('.slot-name')?.textContent?.trim() || '';
        const info = slotEl.querySelector('.slot-job')?.textContent
          ?.replace(/\s+/g, ' ').trim() || '';
        if (name) scanned.push({ slot: index, name, info });
      });
      if (!scanned.length) return;
      const value = JSON.stringify(scanned);
      if (value !== charSignature) {
        charSignature = value;
        config.characters = scanned;
        charactersChanged?.(scanned);
      }
    }

    function changePhase(value, now) {
      if (phase !== value) {
        if (phase === 'lobby') navigation?.stop(false);
        phase = value;
        phaseAt = now;
        actions.clear();
      }
    }

    function clickOnce(key, element, now, delay = 700) {
      if (actions.has(key) || !enabled(element)) return false;

      actions.add(key);
      nextAt = now + delay;
      element.click();
      return true;
    }

    function refreshStages() {
      const cards = all('#stg-list .stg-card');
      const regular = cards.map(card => ({
        id: String(card.dataset.i),
        name:
          card.querySelector('.stg-card-info b')?.textContent?.trim() || '',
        blocked:
          card.disabled ||
          card.classList.contains('locked') ||
          card.classList.contains('soon'),
        selected: card.classList.contains('sel')
      })).filter(stage => /^\d+$/.test(stage.id) && stage.name);

      if (!regular.length && choices.length) return;

      const next = [
        ...regular,
        { ...festivalStage, selected: config.stage === festivalStage.id }
      ];

      const value = JSON.stringify(
        next.map(({ id, name, blocked }) => ({ id, name, blocked }))
      );

      choices = next;

      if (value !== signature) {
        signature = value;
        stagesChanged(next);
      }
    }

    function checkBuild() {
      return Array.from(doc.scripts).some(script => {
        try {
          return /^\/build\/main-[A-Za-z0-9_-]+\.js$/.test(new URL(script.src).pathname);
        } catch {
          return false;
        }
      });
    }

    function start(scanOnly = false, resuming = false) {
      const serverDown = !checkBuild() && !one('#stage') && !one('#splash');
      if (!checkBuild() && !(resuming && config.autoReconnect && serverDown)) {
        stop('版本不符；自動連刷未啟用');
        return;
      }

      refreshStages();
      refreshCharacters();

      if (
        !scanOnly &&
        (config.stage !== festivalStage.id && !/^\d+$/.test(config.stage) ||
          (choices.some(stage => /^\d+$/.test(stage.id)) &&
            !choices.some(stage => stage.id === config.stage && !stage.blocked)))
      ) {
        stop('請先讀取關卡，並選擇已解鎖的關卡');
        return;
      }

      running = !scanOnly;
      reading = scanOnly;
      navigation?.stop();
      phase = '';
      nextAt = 0;
      reloadAt = 0;
      reloadReason = '';
      actions.clear();
      deck = null;
      settlementReady = null;
      departedStage = null;

      if (!scanOnly && !resuming) completed = 0;

      say(scanOnly ? '正在讀取關卡…' : resuming ? '已恢復自動連刷…' : '已開始連刷');
      tick();
    }

    function tick() {
      if (shown('#stages')) refreshStages();
      if (shown('#charselect')) refreshCharacters();

      if (!running && !reading) return;

      const now = clock();
      if (reloadAt) {
        scheduleReload(now, reloadReason);
        return;
      }
      if (now < nextAt) return;

      if (!checkBuild() && !one('#stage') && !one('#splash')) {
        if (!scheduleReload(now, '伺服器無回應／維護中')) {
          stop('找不到遊戲畫面，已停止');
        }
        return;
      }

      // The game keeps #splash.menu visible behind the character selector.
      if (shown('#splash:not(.menu)') && !shown('#npc-dialog') &&
          !shown('#legal-popup') && !shown('#news-popup')) {
        if (reading) {
          stop('請先進入遊戲大廳，再按「讀取關卡」');
          return;
        }
        changePhase('splash', now);
        const maintVisible = shown('#sp-maint.maintenance') ||
          one('#splash')?.classList.contains('sp-maint-on') ||
          doc.body.classList.contains('sp-maint-on') ||
          one('#sp-play')?.classList.contains('maint');
        if (maintVisible) {
          if (!scheduleReload(now, '伺服器維修中')) {
            stop('伺服器維修中，已停止');
          }
          return;
        }
        if (shown('#sp-login') || shown('#sp-register') || shown('#sp-recover')) {
          stop('請先登入帳號，再開始連刷');
          return;
        }
        const playBtn = one('#sp-play');
        if (enabled(playBtn)) {
          say('自動點擊開始遊戲…');
          nextAt = now + 1500;
          playBtn.click();
          return;
        }
        if (now - phaseAt > 25000) {
          if (!scheduleReload(now, '開始畫面載入逾時／斷線')) {
            stop('開始畫面載入逾時，已停止');
          }
        } else {
          say('等待遊戲載入開始按鈕…');
        }
        return;
      }

      if (shown('#legal-popup')) {
        const close = one('#legal-close');
        if (enabled(close)) {
          clickOnce('legal-close', close, now, 600);
          return;
        }
      }

      if (shown('#news-popup')) {
        const close = one('#news-close');
        if (enabled(close)) {
          clickOnce('news-close', close, now, 600);
          return;
        }
      }

      if (shown('#charselect') && !shown('#npc-dialog') && !shown('#loading')) {
        if (reading) {
          stop('請先選擇角色進入遊戲，再按「讀取關卡」');
          return;
        }
        changePhase('charselect', now);
        const slotNodes = all('#slots .slot');
        const filled = slotNodes.filter(el => !el.classList.contains('empty'));
        if (!filled.length) {
          if (now - phaseAt > 15000) {
            if (!scheduleReload(now, '角色列表載入逾時')) {
              stop('找不到可用角色，已停止');
            }
          } else {
            say('等待角色列表載入…');
          }
          return;
        }

        let targetSlot = null;
        if (config.character) {
          targetSlot = filled.find(el =>
            el.querySelector('.slot-name')?.textContent?.trim() === config.character
          ) || null;
          if (!targetSlot && /^slot:(\d+)$/.test(config.character)) {
            const idx = Number(RegExp.$1);
            const candidate = slotNodes[idx];
            if (candidate && !candidate.classList.contains('empty')) {
              targetSlot = candidate;
            }
          }
          if (!targetSlot) {
            stop(`找不到指定角色「${config.character}」，已停止`);
            return;
          }
        } else {
          targetSlot = filled.find(el => el.classList.contains('selected')) || filled[0];
        }

        if (now - phaseAt > 15000) {
          if (!scheduleReload(now, '選角或進入遊戲逾時')) {
            stop('選角或進入遊戲逾時，已停止');
          }
          return;
        }

        if (targetSlot && !targetSlot.classList.contains('selected')) {
          const name = targetSlot.querySelector('.slot-name')?.textContent?.trim() || '角色';
          say(`切換掛機角色：${name}…`);
          nextAt = now + 600;
          targetSlot.click();
          return;
        }

        const playCharBtn = one('#btn-play');
        if (enabled(playCharBtn)) {
          const name = targetSlot?.querySelector('.slot-name')?.textContent?.trim() || '角色';
          say(`以「${name}」進入遊戲…`);
          nextAt = now + 1500;
          playCharBtn.click();
          return;
        }

        say('準備進入遊戲…');
        return;
      }

      if (shown('#loading')) {
        changePhase('loading', now);

        if (now - phaseAt > 120000) {
          if (!scheduleReload(now, '遊戲載入逾時')) stop('載入逾時，已停止連刷');
        } else {
          say('等待遊戲載入…');
        }
        return;
      }

      if (shown('#party-frame')) {
        stop('目前在隊伍中；自動連刷僅支援單人');
        return;
      }

      if (shown('#pause')) {
        changePhase('paused', now);
        say('遊戲已暫停，等待你繼續');
        return;
      }

      if (shown('#npc-dialog')) {
        // Returning to town can open the lobby chooser before the captain.
        // Its lobby rows are in npc-text; the solo action is in npc-options.
        if (one('#npc-text .lb-pick')) {
          changePhase('lobby-choice', now);
          const buttons = all('#npc-options button');
          const solo = buttons.length === 1 && buttons[0];

          if (now - phaseAt > 15000) {
            stop('選擇單人大廳沒有回應，已停止');
          } else if (
            solo &&
            /^(單人大廳|Solo lobby|Lobby solo)$/i.test(solo.textContent.trim())
          ) {
            say('選擇單人大廳，準備返回遠征隊長…');
            clickOnce('solo-lobby', solo, now, 1000);
          } else {
            stop('大廳選單找不到單人大廳按鈕，已停止');
          }
          return;
        }

        changePhase('dialog', now);
        const diff = one('#npc-text .diff');

        if (diff && running) {
          if (departedStage !== config.stage) {
            stop('請先關閉難度視窗，再開始連刷以確認關卡');
            return;
          }

          const sliders = all('#npc-text .diff input[data-key]');
          const keys = farmDifficultyFields.map(([key]) => key);

          const isFestival = config.stage === festivalStage.id;

          if (
            one('#npc-text .diff input[data-pick]') ||
            (isFestival
              ? sliders.length !== 0
              : (sliders.length !== keys.length ||
                  !keys.every(key =>
                    sliders.some(input => input.dataset.key === key)
                  )))
          ) {
            stop('此模式不是一般單人關卡，已停止');
            return;
          }

          if (!isFestival && !Object.hasOwn(farmDifficultyModes, config.difficultyMode)) {
            stop('難度模式無效，已停止');
            return;
          }

          if (!isFestival && config.difficultyMode !== 'current') {
            const desired = sliders.map(input => {
              const min = Number(input.min);
              const max = Number(input.max);
              const step = Number(input.step);
              const value = config.difficultyMode === 'max' ? max
                : config.difficultyMode === 'normal' ? 1
                  : Number(config.difficulty?.[input.dataset.key]) / 100;
              const steps = (value - min) / step;
              return { input, min, max, step, value, steps };
            });

            if (desired.some(({ input, min, max, step, value, steps }) =>
              !input.min || !input.max || !input.step ||
              ![min, max, step, value].every(Number.isFinite) ||
              min <= 0 || max < min || step <= 0 ||
              value < min || value > max ||
              Math.abs(steps - Math.round(steps)) > 1e-6
            )) {
              stop('所選難度不符合遊戲範圍，已停止');
              return;
            }

            for (const { input, value } of desired) {
              input.value = String(value);
              input.dispatchEvent(
                new doc.defaultView.Event('input', { bubbles: true })
              );
            }

            if (desired.some(({ input, value }) =>
              Math.abs(Number(input.value) - value) > 1e-6
            )) {
              stop('難度未成功套用，已停止');
              return;
            }
          }

          const bankMode = one(
            `#npc-text .diff [data-bank="${config.immediateCards ? 0 : 1}"]`
          );
          if (!enabled(bankMode)) {
            stop('找不到升級模式控制項');
            return;
          }
          if (!bankMode.classList.contains('active')) bankMode.click();
          if (!bankMode.classList.contains('active')) {
            stop('開卡模式未成功套用，已停止');
            return;
          }

          say(`${
            isFestival ? '節慶固定難度' : `難度：${farmDifficultyModes[config.difficultyMode]}`
          } · ${
            config.immediateCards ? '立即開卡' : '累積後手動開卡'
          }，準備出發…`);

          clickOnce(
            'launch',
            one('#npc-options button.primary'),
            now,
            1500
          );

          if (now - phaseAt > 15000) {
            stop('出發沒有回應，已停止');
          }
          return;
        }

        const title = one('#npc-name')?.textContent?.trim() || '';
        const dialogText = one('#npc-text')?.textContent?.trim() || '';
        const buttons = all('#npc-options button');

        const isUnfinishedRun =
          /^(未完成的一局|Partida a medias|Unfinished run|Partida inacabada|中断したラン)$/i.test(title) ||
          /離開了一局|要從那裡繼續嗎|Dejaste una partida|Quieres seguir desde ahí|You left a run|Do you want to continue from there|deixou uma partida|ランを中断しています/i.test(dialogText);

        if (isUnfinishedRun && running) {
          const continueBtn =
            buttons.find(btn =>
              /^(繼續|Continuar|Resume|再開)$/i.test(btn.textContent.trim())
            ) ||
            (buttons.length === 2 ? one('#npc-options button.primary') : null) ||
            (buttons.length === 1 ? buttons[0] : null);

          if (enabled(continueBtn)) {
            say(buttons.length === 1 ? '上一局已結算，確認並返回…' : '發現未完成的一局，自動繼續戰鬥…');
            clickOnce('unfinished:' + buttons.length, continueBtn, now, 1200);
            return;
          }
        }

        const isSaveRunRetry =
          /^(儲存遊戲|Guardar partida|Save run|Salvar partida|ランを保存)$/i.test(title);

        if (isSaveRunRetry && running) {
          const retryBtn =
            buttons.find(btn =>
              /^(重試|Reintentar|Retry|Tentar de novo|再試行)$/i.test(btn.textContent.trim())
            ) || one('#npc-options button.primary');
          if (enabled(retryBtn)) {
            say('重試儲存上一局戰利品…');
            clickOnce('save-retry', retryBtn, now, 1500);
            return;
          }
        }

        const informational =
          /^(未完待續[.…！!]*|待續[.…！!]*|Continuará[.…!]*|To be continued[.…!]*|Continua[.…!]*|Continua a seguir[.…!]*|閒置|離開|暫離|Ausente|Away|Idle|AFK|Inactivo|Ausência|已捨棄本局|Partida descartada|Run discarded|ランを破棄しました|你的上一局|Tu última partida|Your last run|Sua última partida|前回のラン)$/i.test(title) ||
          !!one('#npc-text .pending-loot') ||
          /5\s*(分鐘|minutos|minutes)/i.test(dialogText);

        if (informational && buttons.length === 1 && running) {
          clickOnce('ack:' + title, buttons[0], now);
          return;
        }
        const reconnectBtn = buttons.find(btn =>
          /^(Recargar|Reload|重新整理|重整)$/i.test(btn.textContent.trim())
        );
        if (
          running &&
          config.autoReconnect &&
          (reconnectBtn ||
            /維修|維護|斷線|連線|更新|mantenimiento|maintenance|conexión|connection|desconect|actualiz/i.test(
              `${title} ${dialogText}`
            ))
        ) {
          scheduleReload(now, title || '連線中斷／伺服器更新');
          return;
        }

        stop('需要手動處理視窗：' + (title || '未知提示'));
        return;
      }

      if (shown('#gameover')) {
        if (reading) {
          stop('請先返回廣場，再按「讀取關卡」');
          return;
        }

        changePhase('settlement', now);
        const restart = one('#btn-restart');

        if (!enabled(restart)) {
          settlementReady = null;

          if (now - phaseAt > 120000) {
            stop('結算等待逾時，已停止；請檢查遊戲提示');
          } else {
            say('等待伺服器結算…');
          }
          return;
        }

        if (
          !/^(返回角色|Volver al personaje|Return to character|Back to character|Voltar ao personagem)$/i.test(
            restart.textContent.trim()
          )
        ) {
          stop('結算未能返回角色，已停止；請檢查遊戲提示');
          return;
        }

        if (actions.has('return')) {
          if (now - phaseAt > 15000) {
            stop('返回角色沒有回應，已停止');
          }
          return;
        }

        if (settlementReady !== restart) {
          settlementReady = restart;
          readyAt = now;
        }

        if (now - readyAt < 2000) {
          say('結算完成，2 秒後重刷…');
          return;
        }

        if (clickOnce('return', restart, now, 1500)) {
          completed++;
          settlementReady = null;
          say('已結算 ' + completed + ' 局，返回選關…');
        }
        return;
      }

      settlementReady = null;

      if (shown('#festival')) {
        changePhase('festival', now);

        if (reading || config.stage !== festivalStage.id) {
          stop('請先關閉節慶視窗，再開始連刷');
          return;
        }

        const play = one('#festival-play');

        if (!enabled(play)) {
          stop('節慶關卡目前無法開始，已停止');
          return;
        }

        say('準備進入：' + festivalStage.name);

        if (clickOnce('depart:' + festivalStage.id, play, now)) {
          departedStage = festivalStage.id;
        }

        if (now - phaseAt > 15000) {
          stop('開啟節慶出發選單沒有回應，已停止');
        }
        return;
      }

      if (shown('#stages')) {
        changePhase('stages', now);

        if (reading) {
          stop('關卡已更新，請選關後開始連刷');
          return;
        }

        if (config.stage === festivalStage.id) {
          stop('請先關閉遠征選單，再開始連刷節慶關卡');
          return;
        }

        const selected = choices.find(stage => stage.id === config.stage);

        if (!selected || selected.blocked) {
          stop('所選關卡不存在或尚未解鎖');
          return;
        }

        const card = all('#stg-list .stg-card').find(
          element => element.dataset.i === config.stage
        );

        if (!card?.classList.contains('sel')) {
          clickOnce('select:' + config.stage, card, now);

          if (now - phaseAt > 10000) {
            stop('選關沒有回應，已停止');
          }
          return;
        }

        const go = one('#stg-go');

        if (!enabled(go)) {
          stop('此關卡無法出發，可能需要先完成劇情或解鎖條件');
          return;
        }

        say('準備進入：' + selected.name);

        if (clickOnce('depart:' + config.stage, go, now)) {
          departedStage = config.stage;
        }

        if (now - phaseAt > 15000) {
          stop('開啟難度選單沒有回應，已停止');
        }
        return;
      }

      if (shown('#picker')) {
        if (reading) {
          stop('請先返回廣場，再讀取關卡');
          return;
        }

        changePhase('picker', now);

        if (!config.autoPick) {
          say('等待你選卡');
          return;
        }

        const picker = one('#picker');

        if (picker.classList.contains('closing')) {
          say('等待選卡動畫…');
          return;
        }

        const target = pickBestDomCard(doc);

        if (deck !== target) {
          deck = target;
          deckAt = now;
        }

        if (now - deckAt > 30000) {
          stop('選卡超過 30 秒沒有進展，已停止');
          return;
        }

        if (target) {
          nextAt = now + 220;
          invokeCardHandler(target);
          say('自動選卡：優先選擇高品質卡片');
        }
        return;
      }

      const blocking = all('.overlay:not(#splash.menu)').find(visible);

      if (blocking || shown('#story')) {
        stop('遇到其他畫面，已停止；請手動處理後重新開始');
        return;
      }

      if (doc.body.classList.contains('in-lobby')) {
        changePhase('lobby', now);
        if (!reading && config.character && !/^slot:\d+$/.test(config.character)) {
          const currentName = one('#lobby-labels .lobby-name.self')?.textContent?.trim() || '';
          const switchBtn = one('#lobby-switch');
          if (currentName && currentName !== config.character && enabled(switchBtn)) {
            navigation?.stop();
            say(`目前角色「${currentName}」，切換至「${config.character}」…`);
            clickOnce('switch-char', switchBtn, now, 1200);
            return;
          }
        }
        const prompt = one('#lobby-prompt');
        const isFestival = !reading && config.stage === festivalStage.id;
        const targetKey = isFestival ? 'festival' : 'captain';
        const targetLabel = isFestival ? '節慶主持人' : '遠征隊長';
        const menuLabel = isFestival ? '節慶選單' : '遠征選單';
        const promptPattern = isFestival
          ? /主持人|host|anfitriona|anfitriã|案内人/i
          : /隊長|captain|capitán|capitão/i;

        if (
          enabled(prompt) &&
          promptPattern.test(prompt.textContent)
        ) {
          navigation?.stop();
          say(`開啟${menuLabel}…`);
          clickOnce(targetKey, prompt, now);

          if (now - phaseAt > 15000) {
            stop(`${menuLabel}沒有回應，已停止`);
          }
        } else if (now - phaseAt > (navigation ? 45000 : 20000)) {
          stop(navigation ? `自動前往${targetLabel}逾時，已停止` : `請移動到${targetLabel}旁，再開始連刷`);
        } else if (navigation) {
          try { say(navigation.step(targetKey)); }
          catch (error) { stop(error.message); }
        } else {
          say(`等待廣場載入／靠近${targetLabel}…`);
        }
        return;
      }

      if (shown('#hud')) {
        if (reading) {
          stop('請先返回廣場，再讀取關卡');
          return;
        }

        changePhase('playing', now);
        deck = null;

        say('戰鬥中 · 已結算 ' + completed + ' 局');
        return;
      }

      changePhase('unknown', now);

      if (now - phaseAt > 15000) {
        if (!scheduleReload(now, '找不到可操作的遊戲畫面／斷線')) {
          stop('找不到可操作的遊戲畫面，已停止');
        }
      } else {
        say('等待遊戲畫面…');
      }
    }

    return {
      start,
      stop,
      tick,
      refreshStages,
      refreshCharacters,
      triggerReload(reason) {
        if (running && config.autoReconnect) {
          scheduleReload(clock(), reason);
        }
      },
      get running() {
        return running || reading;
      },
      get status() {
        return message;
      }
    };
  }

  const host = document.createElement('div');
  host.id = ID;
  host.style.cssText =
    'position:fixed;right:16px;top:60px;z-index:2147483647;max-width:calc(100vw - 24px)';

  let panelWindow = null;
  let floatingStyle = '';
  let popupMonitor = 0;

  const root = host.attachShadow({ mode: 'open' });

  root.innerHTML = `
    <style>
      :host{all:initial}
      *{box-sizing:border-box}
      ::-webkit-scrollbar{width:5px;height:5px}
      ::-webkit-scrollbar-track{background:transparent}
      ::-webkit-scrollbar-thumb{background:rgba(148,163,184,.28);border-radius:99px}
      ::-webkit-scrollbar-thumb:hover{background:rgba(148,163,184,.48)}
      section{
        width:336px;max-width:calc(100vw - 24px);
        background:linear-gradient(165deg,rgba(19,25,40,.97) 0%,rgba(11,15,26,.99) 100%);
        color:#f1f5f9;
        border:1px solid rgba(212,175,106,.32);
        border-radius:16px;
        box-shadow:0 16px 36px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.04) inset,0 1px 0 rgba(255,236,196,.18) inset;
        font:12.5px/1.45 "Inter","Noto Sans TC","PingFang TC","Microsoft JhengHei",system-ui,sans-serif;
        overflow:hidden;
        contain:layout style paint;
        transition:border-color .2s ease
      }
      section:hover{
        border-color:rgba(212,175,106,.48)
      }
      header{
        padding:11px 14px;display:flex;justify-content:space-between;
        align-items:center;
        background:linear-gradient(90deg,rgba(38,50,78,.85) 0%,rgba(26,34,54,.85) 100%);
        border-bottom:1px solid rgba(212,175,106,.22);
        cursor:grab;touch-action:none;user-select:none
      }
      header.dragging{cursor:grabbing}
      .brand{
        display:flex;align-items:center;gap:8px;font-weight:700;
        font-size:13.5px;letter-spacing:.3px;color:#fff7e6;
        text-shadow:0 1px 2px rgba(0,0,0,.5)
      }
      .brand-dot{
        width:8px;height:8px;border-radius:99px;
        background:linear-gradient(135deg,#f6d365 0%,#fda085 100%);
        box-shadow:0 0 10px rgba(246,211,101,.75)
      }
      .ver-badge{
        display:inline-block;padding:1px 7px;border-radius:99px;
        font-size:10.5px;font-weight:600;letter-spacing:.2px;
        color:#f6d365;background:rgba(246,211,101,.12);
        border:1px solid rgba(246,211,101,.28)
      }
      .win-actions{display:flex;align-items:center;gap:5px}
      .icon-btn{
        width:26px;height:26px;padding:0;display:inline-flex;
        align-items:center;justify-content:center;
        background:rgba(255,255,255,.06);
        border:1px solid rgba(255,255,255,.12);
        border-radius:7px;color:#cbd5e1;font-size:13px;
        transition:all .15s ease
      }
      .icon-btn:hover{
        background:rgba(246,211,101,.16);
        border-color:rgba(246,211,101,.4);
        color:#fff
      }
      main{
        padding:12px;max-height:80vh;overflow-y:auto;
        display:flex;flex-direction:column;gap:10px
      }
      .card{
        background:rgba(22,30,49,.62);
        border:1px solid rgba(148,163,184,.14);
        border-radius:12px;padding:10px 11px;
        box-shadow:0 4px 14px rgba(0,0,0,.2) inset
      }
      .card-head{
        display:flex;align-items:center;justify-content:space-between;
        margin-bottom:8px;padding-bottom:6px;
        border-bottom:1px solid rgba(148,163,184,.12)
      }
      .card-title{
        font-weight:700;font-size:12.5px;color:#f8fafc;
        display:flex;align-items:center;gap:6px;letter-spacing:.3px
      }
      .card-title::before{
        content:"";display:inline-block;width:3px;height:12px;
        border-radius:2px;background:linear-gradient(180deg,#f6d365,#e29547)
      }
      small{display:block;color:#94a3b8;font-size:11px;margin-top:4px;line-height:1.4}
      label{display:block;margin-top:7px;font-size:12px;color:#cbd5e1}
      output{
        float:right;font-weight:700;font-size:11.5px;
        color:#93c5fd;background:rgba(59,130,246,.14);
        border:1px solid rgba(96,165,250,.24);
        padding:0 6px;border-radius:99px;line-height:1.5
      }
      input[type=range]{
        -webkit-appearance:none;appearance:none;
        width:100%;height:5px;margin:6px 0 2px;
        background:rgba(15,23,42,.85);
        border:1px solid rgba(148,163,184,.18);
        border-radius:99px;outline:none;cursor:pointer
      }
      input[type=range]::-webkit-slider-thumb{
        -webkit-appearance:none;appearance:none;
        width:15px;height:15px;border-radius:50%;
        background:linear-gradient(135deg,#93c5fd 0%,#3b82f6 100%);
        border:2px solid #eff6ff;
        box-shadow:0 2px 6px rgba(0,0,0,.45);
        cursor:pointer;transition:transform .12s ease,box-shadow .12s ease
      }
      input[type=range]::-webkit-slider-thumb:hover{
        transform:scale(1.12);
        box-shadow:0 0 10px rgba(96,165,250,.7)
      }
      input[type=range]:disabled{opacity:.45;cursor:default}
      button{
        font:inherit;font-weight:600;color:#e2e8f0;
        background:linear-gradient(180deg,#2a3956 0%,#1f2b42 100%);
        border:1px solid rgba(148,163,184,.28);border-radius:8px;
        padding:5px 10px;cursor:pointer;
        transition:all .15s ease
      }
      button:hover:not(:disabled){
        background:linear-gradient(180deg,#34476a 0%,#263552 100%);
        border-color:rgba(147,197,253,.5);color:#fff
      }
      button:active:not(:disabled){transform:translateY(1px)}
      button:disabled{opacity:.45;cursor:default}
      .btn-sm{padding:3px 9px;font-size:11.5px;border-radius:7px}
      .btn-primary{
        width:100%;margin-top:9px;padding:8px 12px;
        font-size:13px;font-weight:700;letter-spacing:.5px;
        color:#0f172a;
        background:linear-gradient(135deg,#f6d365 0%,#eab308 55%,#d97706 100%);
        border:1px solid rgba(254,240,138,.65);
        border-radius:9px;
        box-shadow:0 6px 16px rgba(234,179,8,.25),0 1px 0 rgba(255,255,255,.4) inset
      }
      .btn-primary:hover:not(:disabled){
        background:linear-gradient(135deg,#fde047 0%,#f59e0b 60%,#ea580c 100%);
        border-color:#fef08a;color:#090d16;
        box-shadow:0 8px 20px rgba(245,158,11,.35)
      }
      .btn-primary.running{
        color:#fff;
        background:linear-gradient(135deg,#ef4444 0%,#dc2626 60%,#991b1b 100%);
        border-color:rgba(252,165,165,.55);
        box-shadow:0 6px 16px rgba(239,68,68,.28)
      }
      .btn-primary.running:hover:not(:disabled){
        background:linear-gradient(135deg,#f87171 0%,#ef4444 60%,#b91c1c 100%);
        border-color:#fecaca;color:#fff
      }
      .row{
        display:flex;align-items:center;gap:8px;
        justify-content:space-between
      }
      .row label{margin:0}
      .field-grid{
        display:grid;grid-template-columns:64px 1fr;
        align-items:center;gap:6px 8px;margin-top:4px
      }
      .field-grid label{margin:0;color:#94a3b8;font-size:11.5px;font-weight:500}
      .farm select{
        width:100%;margin:0;
        background:rgba(15,23,42,.85);color:#f1f5f9;
        padding:5px 8px;border:1px solid rgba(148,163,184,.24);
        border-radius:7px;font:inherit;font-size:12px;
        outline:none;transition:border-color .15s ease
      }
      .farm select:focus{border-color:rgba(246,211,101,.6)}
      .farm select:disabled{opacity:.5}
      .custom-box{
        margin-top:8px;padding:8px 10px;
        background:rgba(15,23,42,.55);
        border:1px solid rgba(148,163,184,.14);
        border-radius:9px
      }
      .checks-grid{
        display:grid;grid-template-columns:1fr 1fr;
        gap:6px;margin-top:9px
      }
      .chip-check{
        display:flex;align-items:center;gap:6px;margin:0;
        padding:5px 8px;border-radius:8px;
        background:rgba(15,23,42,.6);
        border:1px solid rgba(148,163,184,.16);
        font-size:11.5px;color:#cbd5e1;cursor:pointer;
        user-select:none;transition:all .15s ease
      }
      .chip-check:hover{
        border-color:rgba(147,197,253,.35);
        background:rgba(30,41,59,.7)
      }
      .chip-check input[type=checkbox]{
        accent-color:#eab308;margin:0;width:13.5px;height:13.5px;cursor:pointer
      }
      .chip-check.full{grid-column:1 / -1}
      .status-banner{
        margin-top:8px;padding:7px 10px;border-radius:8px;
        background:rgba(15,23,42,.75);
        border-left:3px solid #eab308;
        color:#fde68a;font-size:11.5px;font-weight:500;
        overflow-wrap:anywhere
      }
      .sliders-grid{
        display:grid;grid-template-columns:1fr;gap:3px
      }
      .slider-item{
        padding:3px 0
      }
      .slider-item label{
        margin:0;display:flex;align-items:center;justify-content:space-between
      }
      .toggles-bar{
        display:grid;grid-template-columns:1fr 1fr auto;
        gap:6px;align-items:center;margin-top:8px;padding-top:8px;
        border-top:1px solid rgba(148,163,184,.12)
      }
      .telemetry-grid{
        display:grid;grid-template-columns:1fr 1fr;gap:6px
      }
      .stat-pill{
        padding:6px 8px;border-radius:8px;
        background:rgba(15,23,42,.65);
        border:1px solid rgba(148,163,184,.12);
        font-size:11.5px;color:#cbd5e1;
        white-space:nowrap;overflow:hidden;text-overflow:ellipsis
      }
      .stat-pill.wide{grid-column:1 / -1}
      .audit-box{
        margin-top:6px;padding:7px 9px;border-radius:8px;
        background:rgba(15,23,42,.72);
        border:1px solid rgba(96,165,250,.18);
        color:#bfdbfe;font-size:11.5px;line-height:1.45;
        overflow-wrap:anywhere
      }
      .footer-note{
        text-align:center;color:#64748b;font-size:10.5px;
        padding:2px 4px;line-height:1.45
      }
      .hidden{display:none !important}
    </style>
    <section>
      <header id="dragHandle" title="按住拖曳面板位置">
        <div class="brand">
          <span class="brand-dot"></span>
          <span>RO 實驗控制台</span>
          <small class="ver-badge">0.6.27</small>
        </div>
        <span class="win-actions">
          <button id="popout" class="icon-btn" title="另開獨立視窗">↗</button>
          <button id="collapse" class="icon-btn" title="收合／展開 (F8)">−</button>
        </span>
      </header>
      <main id="body">
        <div class="card farm">
          <div class="card-head">
            <span class="card-title">自動連刷巡航</span>
            <button id="farmRead" class="btn-sm">讀取關卡</button>
          </div>
          <div class="field-grid">
            <label for="farmCharacter">掛機角色</label>
            <select id="farmCharacter" aria-label="掛機角色">
              <option value="">沿用目前／預設角色</option>
            </select>
            <label for="farmStage">連刷關卡</label>
            <select id="farmStage" aria-label="連刷關卡">
              <option value="">按「讀取關卡」自動前往遠征隊長</option>
            </select>
            <label for="farmDifficulty">連刷難度</label>
            <select id="farmDifficulty">
              ${Object.entries(farmDifficultyModes).map(([value, label]) =>
                `<option value="${value}">${label}</option>`
              ).join('')}
            </select>
          </div>
          <div id="farmCustom" class="custom-box hidden">
            ${farmDifficultyFields.map(([key, label, max]) => `
              <div class="slider-item">
                <label for="farmDiff_${key}">
                  <span>${label}</span>
                  <output id="farmDiff_${key}Value">${max}%</output>
                </label>
                <input id="farmDiff_${key}" type="range"
                  min="100" max="${max}" step="5" value="${max}">
              </div>
            `).join('')}
          </div>
          <div class="checks-grid">
            <label class="chip-check" title="關閉時：升級／寶箱先累積，手動開啟後才選卡">
              <input id="farmImmediate" type="checkbox" checked>
              <span>立即開卡</span>
            </label>
            <label class="chip-check" title="連刷與手動遊玩皆生效：背景靜默自動選最高品質的升級／寶箱卡片，不跳出選卡畫面">
              <input id="farmPick" type="checkbox" checked>
              <span>背景選高品卡</span>
            </label>
            <label class="chip-check">
              <input id="farmReconnect" type="checkbox" checked>
              <span>斷線5秒重連</span>
            </label>
            <label class="chip-check">
              <input id="farmAntiIdle" type="checkbox" checked>
              <span>防5分閒置</span>
            </label>
          </div>
          <button id="farmToggle" class="btn-primary">
            開始連刷
          </button>
          <small id="farmStatus" class="status-banner">尚未開始</small>
        </div>

        <div class="card">
          <div class="card-head">
            <span class="card-title">戰鬥與掉寶強化</span>
            <button id="reset" class="btn-sm" title="還原所有強化 (F9)">一鍵還原</button>
          </div>
          <div class="sliders-grid">
            ${[
              ['speed', '遊戲速度', 1, 20, 1, 1, '×'],
              ['animation', '選卡速度', 1, 10, 1, 1, '×'],
              ['attack', '傷害倍率', 1, 100, 1, 1, '×'],
              ['cooldown', '冷卻縮減', 0, 99, 1, 0, '%'],
              ['targets', '目標倍率', 1, 100, 1, 1, '×'],
              ['reach', '攻擊距離／範圍', 1, 20, 1, 1, '×'],
              ['money', '局內金錢', 1, 100, 1, 1, '×'],
              ['discovery', '額外尋寶率', 0, 300, 10, 0, '%'],
              ['pickup', '撿取範圍', 1, 100, 1, 1, '×'],
              ['suction', '吸取速度', 1, 10, 1, 1, '×'],
              ['mobDropCap', '單怪掉裝上限', 0, 500, 25, 0, '隻']
            ].map(([id, label, min, max, step, value, unit]) => `
              <div class="slider-item">
                <label for="${id}">
                  <span>${label}</span>
                  <output id="${id}Value">${id === 'mobDropCap' && value === 0 ? '自動' : `${id === 'discovery' ? '+' : ''}${value}${unit}`}</output>
                </label>
                <input id="${id}" type="range" min="${min}" max="${max}"
                  step="${step}" value="${value}">
              </div>
            `).join('')}
          </div>
          <div class="checks-grid" style="margin-top:8px">
            <label class="chip-check">
              <input id="god" type="checkbox">
              <span>免傷無敵</span>
            </label>
            <label class="chip-check">
              <input id="fullHp" type="checkbox">
              <span>鎖定滿血</span>
            </label>
          </div>
        </div>

        <div class="card">
          <div class="card-head">
            <span class="card-title">即時監控與結算診斷</span>
            <button id="lootExport" class="btn-sm">匯出報告</button>
          </div>
          <div class="telemetry-grid">
            <div id="status" class="stat-pill">就緒</div>
            <div id="meter" class="stat-pill">實測速度：—</div>
            <div id="discoveryStatus" class="stat-pill wide">尋寶率：—</div>
          </div>
          <div class="row" style="margin-top:8px">
            <label class="chip-check" style="padding:3px 8px">
              <input id="lootAudit" type="checkbox" checked>
              <span>記錄結算送出與核發數量</span>
            </label>
          </div>
          <small id="lootStatus" class="audit-box" aria-live="polite">等待下一局</small>
        </div>

        <small class="footer-note">
          快捷鍵：<b>F8</b> 收合面板 · <b>F9</b> 還原預設值
        </small>
      </main>
    </section>
  `;

  document.documentElement.append(host);

  for (const event of [
    'keydown', 'keyup', 'keypress',
    'pointerdown', 'pointerup',
    'mousedown', 'mouseup',
    'click', 'dblclick', 'wheel'
  ]) {
    host.addEventListener(event, e => e.stopPropagation());
  }

  const positionKey = 'ros-panel-position-v1';
  const dragHandle = root.getElementById('dragHandle');
  let drag = null;

  function placePanel(left, top) {
    const rect = host.getBoundingClientRect();

    const x = Math.max(
      0,
      Math.min(left, Math.max(0, innerWidth - rect.width))
    );
    const y = Math.max(
      0,
      Math.min(top, Math.max(0, innerHeight - Math.min(rect.height, innerHeight)))
    );

    host.style.right = 'auto';
    host.style.left = x + 'px';
    host.style.top = y + 'px';

    return { left: x, top: y };
  }

  function keepPanelVisible() {
    if (panelWindow && !panelWindow.closed) return;

    const rect = host.getBoundingClientRect();
    placePanel(rect.left, rect.top);
  }

  try {
    const saved = JSON.parse(localStorage.getItem(positionKey));

    if (
      saved &&
      Number.isFinite(saved.left) &&
      Number.isFinite(saved.top)
    ) {
      placePanel(saved.left, saved.top);
    }
  } catch {}

  dragHandle.addEventListener('pointerdown', e => {
    if (panelWindow || e.button !== 0 || e.target.closest('button')) return;

    const rect = host.getBoundingClientRect();
    drag = {
      id: e.pointerId,
      x: e.clientX - rect.left,
      y: e.clientY - rect.top
    };

    dragHandle.setPointerCapture(e.pointerId);
    dragHandle.classList.add('dragging');
    e.preventDefault();
  });

  dragHandle.addEventListener('pointermove', e => {
    if (!drag || drag.id !== e.pointerId) return;
    placePanel(e.clientX - drag.x, e.clientY - drag.y);
  });

  function stopDrag(e) {
    if (!drag || drag.id !== e.pointerId) return;

    drag = null;
    dragHandle.classList.remove('dragging');

    if (dragHandle.hasPointerCapture(e.pointerId)) {
      dragHandle.releasePointerCapture(e.pointerId);
    }

    const rect = host.getBoundingClientRect();

    try {
      localStorage.setItem(
        positionKey,
        JSON.stringify({ left: rect.left, top: rect.top })
      );
    } catch {}
  }

  dragHandle.addEventListener('pointerup', stopDrag);
  dragHandle.addEventListener('pointercancel', stopDrag);
  dragHandle.addEventListener('lostpointercapture', stopDrag);
  window.addEventListener('resize', keepPanelVisible);

  const $ = id => root.getElementById(id);

  const limits = {
    speed: 20,
    animation: 10,
    attack: 100,
    cooldown: 99,
    targets: 100,
    reach: 20,
    money: 100,
    discovery: 300,
    pickup: 100,
    suction: 10,
    mobDropCap: 500
  };

  const defaults = {
    speed: 1,
    animation: 1,
    attack: 1,
    cooldown: 0,
    targets: 1,
    reach: 1,
    money: 1,
    discovery: 0,
    pickup: 1,
    suction: 1,
    mobDropCap: 0
  };

  const state = {
    ...defaults,
    god: false,
    fullHp: false,
    autoPick: true,
    immediateCards: true,
    limited: 0,
    inputClockBlocked: false
  };

  const lootAudit = createLootAudit(() => state, () => {
    $('lootStatus').textContent = lootAudit.summary();
  });
  lootAudit.start();
  $('lootAudit').addEventListener('change', () => {
    if ($('lootAudit').checked) lootAudit.start();
    else lootAudit.stop();
  });
  $('lootExport').onclick = () => {
    const blob = new Blob([JSON.stringify(lootAudit.exportData(), null, 2)],
      { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'ro-settlement-diagnostic.json';
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
  };

  const settingValue = (id, v) =>
    Math.max(defaults[id], Math.min(limits[id], Number(v) || defaults[id]));

  const report = text => {
    $('status').textContent = text;
  };

  let gameControl = null;
  let animation = null;
  let generation = 0;
  let modulePromise = null;
  let samples = [];

  const MAIN_SCRIPT_RE = /^\/build\/main-[A-Za-z0-9_-]+\.js$/;
  const SOUL_LINKER_RE = /(["'])(\.\/soul_linker-[A-Za-z0-9_-]+\.js)\1/;
  let cachedSoulLinkerUrl = null;
  const isGameHooks = v =>
    !!v && typeof v === 'object' &&
    ['start', 'tick', 'hurt', 'damageMult'].every(k => Array.isArray(v[k]));

  function findMainScript() {
    return Array.from(document.scripts).find(script => {
      try {
        const url = new URL(script.src);
        return url.origin === location.origin && MAIN_SCRIPT_RE.test(url.pathname);
      } catch {
        return false;
      }
    });
  }

  function findPreloadedSoulLinker() {
    if (cachedSoulLinkerUrl) return cachedSoulLinkerUrl;
    for (const link of document.querySelectorAll('link[href*="soul_linker-"]')) {
      try {
        const url = new URL(link.href, document.baseURI);
        if (url.origin === location.origin && /^\/build\/soul_linker-[A-Za-z0-9_-]+\.js$/.test(url.pathname)) {
          cachedSoulLinkerUrl = url.href;
          return cachedSoulLinkerUrl;
        }
      } catch {}
    }
    try {
      const entries = performance.getEntriesByType?.('resource') || [];
      for (const entry of entries) {
        const url = new URL(entry.name, document.baseURI);
        if (url.origin === location.origin && /^\/build\/soul_linker-[A-Za-z0-9_-]+\.js$/.test(url.pathname)) {
          cachedSoulLinkerUrl = url.href;
          return cachedSoulLinkerUrl;
        }
      }
    } catch {}
    return null;
  }

  function loadGameModule(main = findMainScript()) {
    const preloaded = findPreloadedSoulLinker();
    if (preloaded) {
      modulePromise ??= import(preloaded).catch(error => {
        modulePromise = null;
        throw error;
      });
      return modulePromise;
    }
    if (!main) return Promise.reject(Error('找不到遊戲主腳本（/build/main-*.js）'));
    modulePromise ??= fetch(main.src, { credentials: 'same-origin' })
      .then(res => {
        if (!res.ok) throw Error(`讀取主腳本失敗（${res.status}）`);
        return res.text();
      })
      .then(text => {
        const match = text.match(SOUL_LINKER_RE);
        if (!match) throw Error('版本不符；找不到 soul_linker 模組');
        cachedSoulLinkerUrl = new URL(match[2], main.src).href;
        return import(cachedSoulLinkerUrl);
      })
      .catch(error => { modulePromise = null; throw error; });
    return modulePromise;
  }

  const lobbyNavigation = createLobbyNavigator(() => loadGameModule());

  const farmConfigKey = 'ros-auto-farm-v1';
  const farmRuntimeKey = 'ros-auto-farm-runtime-v1';
  const farmConfig = {
    stage: '',
    character: '',
    characters: [],
    difficultyMode: 'max',
    difficulty: Object.fromEntries(
      farmDifficultyFields.map(([key, , max]) => [key, max])
    ),
    immediateCards: true,
    autoPick: true,
    autoReconnect: true,
    antiIdle: true
  };

  try {
    const saved = JSON.parse(localStorage.getItem(farmConfigKey));

    if (saved) {
      if (/^(\d+|louyang)$/.test(String(saved.stage))) {
        farmConfig.stage = String(saved.stage);
      }
      if (typeof saved.character === 'string') {
        farmConfig.character = saved.character;
      }
      if (Array.isArray(saved.characters)) {
        farmConfig.characters = saved.characters
          .filter(c => c && typeof c.name === 'string' && c.name)
          .slice(0, 12)
          .map(c => ({
            slot: Number.isInteger(c.slot) ? c.slot : 0,
            name: c.name,
            info: typeof c.info === 'string' ? c.info : ''
          }));
      }
      if (Object.hasOwn(farmDifficultyModes, saved.difficultyMode)) {
        farmConfig.difficultyMode = saved.difficultyMode;
      } else if (saved.maxDifficulty === false) {
        farmConfig.difficultyMode = 'current';
      }
      for (const [key, , max] of farmDifficultyFields) {
        const value = Number(saved.difficulty?.[key]);
        if (Number.isFinite(value) && value >= 100 && value <= max) {
          farmConfig.difficulty[key] = Math.round(value / 5) * 5;
        }
      }
      if (typeof saved.immediateCards === 'boolean') {
        farmConfig.immediateCards = saved.immediateCards;
      }
      if (typeof saved.autoPick === 'boolean') {
        farmConfig.autoPick = saved.autoPick;
      }
      if (typeof saved.autoReconnect === 'boolean') {
        farmConfig.autoReconnect = saved.autoReconnect;
      }
      if (typeof saved.antiIdle === 'boolean') {
        farmConfig.antiIdle = saved.antiIdle;
      }
    }
  } catch {}

  const saveFarm = () => {
    try {
      localStorage.setItem(farmConfigKey, JSON.stringify(farmConfig));
    } catch {}
  };

  const setFarmRuntimeActive = active => {
    try {
      if (active) {
        localStorage.setItem(
          farmRuntimeKey,
          JSON.stringify({ active: true, at: Date.now() })
        );
      } else {
        localStorage.removeItem(farmRuntimeKey);
      }
    } catch {}
  };

  const isFarmRuntimeActive = () => {
    try {
      const data = JSON.parse(localStorage.getItem(farmRuntimeKey));
      return data?.active === true;
    } catch {
      return false;
    }
  };

  function renderFarmCharacters(characters = farmConfig.characters) {
    const select = $('farmCharacter');
    select.replaceChildren();

    const defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = characters.length
      ? '沿用目前／預設角色'
      : '沿用目前角色（進入選角畫面會自動讀取）';
    select.append(defaultOpt);

    for (const char of characters) {
      const option = document.createElement('option');
      option.value = char.name;
      option.textContent = char.info
        ? `${char.slot + 1}. ${char.name} (${char.info})`
        : `${char.slot + 1}. ${char.name}`;
      select.append(option);
    }

    if (
      farmConfig.character &&
      !characters.some(c => c.name === farmConfig.character)
    ) {
      const savedOpt = document.createElement('option');
      savedOpt.value = farmConfig.character;
      savedOpt.textContent = `${farmConfig.character}（已記憶）`;
      select.append(savedOpt);
    }

    select.value = farmConfig.character;
  }

  function showFarmDifficulty() {
    $('farmDifficulty').value = farmConfig.difficultyMode;
    $('farmCustom').classList.toggle(
      'hidden', farmConfig.difficultyMode !== 'custom'
    );
    for (const [key] of farmDifficultyFields) {
      $('farmDiff_' + key).value = String(farmConfig.difficulty[key]);
      $('farmDiff_' + key + 'Value').textContent =
        farmConfig.difficulty[key] + '%';
    }
  }

  renderFarmCharacters();
  showFarmDifficulty();
  $('farmImmediate').checked = farmConfig.immediateCards;
  $('farmPick').checked = farmConfig.autoPick;
  $('farmReconnect').checked = farmConfig.autoReconnect;
  $('farmAntiIdle').checked = farmConfig.antiIdle;
  state.autoPick = farmConfig.autoPick;
  state.immediateCards = farmConfig.immediateCards;

  const farm = createAutoFarm(
    farmConfig,
    info => {
      const active = info.running || info.reading;
      $('farmToggle').textContent = active ? '停止連刷' : '開始連刷';
      $('farmToggle').classList.toggle('running', active);

      $('farmStatus').textContent = info.text;

      $('farmCharacter').disabled =
        $('farmStage').disabled =
        $('farmRead').disabled =
        active;

      for (const id of [
        'farmDifficulty', 'farmReconnect',
        ...farmDifficultyFields.map(([key]) => 'farmDiff_' + key)
      ]) {
        $(id).disabled = active;
      }
    },
    stages => {
      const selected =
        stages.find(s => /^\d+$/.test(s.id) && s.selected && !s.blocked) ||
        stages.find(s => /^\d+$/.test(s.id) && !s.blocked) ||
        stages.find(s => s.selected && !s.blocked) ||
        stages.find(s => !s.blocked);

      if (!farm.running && !farmConfig.stage) {
        farmConfig.stage = selected?.id || '';
      }

      $('farmStage').replaceChildren();

      for (const stage of stages) {
        const option = document.createElement('option');
        option.value = stage.id;
        option.textContent =
          stage.name + (stage.blocked ? '（未開放）' : '');
        option.disabled = stage.blocked;
        $('farmStage').append(option);
      }

      $('farmStage').value = farmConfig.stage;
      saveFarm();
    },
    document,
    () => performance.now(),
    lobbyNavigation,
    characters => {
      renderFarmCharacters(characters);
      saveFarm();
    },
    () => setFarmRuntimeActive(false)
  );

  $('farmCharacter').addEventListener('change', () => {
    farmConfig.character = $('farmCharacter').value;
    saveFarm();
  });

  $('farmStage').addEventListener('change', () => {
    farmConfig.stage = $('farmStage').value;
    saveFarm();
  });

  $('farmDifficulty').addEventListener('change', () => {
    farmConfig.difficultyMode = $('farmDifficulty').value;
    showFarmDifficulty();
    saveFarm();
  });

  for (const [key] of farmDifficultyFields) {
    $('farmDiff_' + key).addEventListener('input', () => {
      farmConfig.difficulty[key] = Number($('farmDiff_' + key).value);
      showFarmDifficulty();
      saveFarm();
    });
  }

  $('farmImmediate').addEventListener('change', () => {
    farmConfig.immediateCards = $('farmImmediate').checked;
    state.immediateCards = farmConfig.immediateCards;
    saveFarm();
    void applySettings();
  });

  $('farmPick').addEventListener('change', () => {
    farmConfig.autoPick = $('farmPick').checked;
    state.autoPick = farmConfig.autoPick;
    saveFarm();
    void applySettings();
  });

  $('farmReconnect').addEventListener('change', () => {
    farmConfig.autoReconnect = $('farmReconnect').checked;
    if (!farmConfig.autoReconnect && !farm.running) setFarmRuntimeActive(false);
    saveFarm();
  });

  $('farmAntiIdle').addEventListener('change', () => {
    farmConfig.antiIdle = $('farmAntiIdle').checked;
    saveFarm();
  });

  function sendAntiIdlePulse() {
    if (!farmConfig.antiIdle || !document.body?.classList?.contains('in-lobby')) return;
    try {
      const EventCtor = window.PointerEvent || window.MouseEvent || window.Event;
      if (typeof EventCtor === 'function') {
        window.dispatchEvent(new EventCtor('pointermove', {
          bubbles: true,
          cancelable: true,
          clientX: 0,
          clientY: 0
        }));
      }
    } catch {}
  }

  const antiIdleTimer = setInterval(sendAntiIdlePulse, 60000);

  $('farmRead').onclick = () => farm.start(true);
  $('farmToggle').onclick = () => {
    if (farm.running) {
      setFarmRuntimeActive(false);
      farm.stop();
    } else {
      setFarmRuntimeActive(farmConfig.autoReconnect);
      farm.start();
    }
  };

  farm.refreshStages();
  farm.refreshCharacters();

  function standAloneAutoPickStep() {
    if (farm.running || !farmConfig.autoPick) return;
    const isOpen = el =>
      !!el &&
      el.isConnected &&
      !el.classList.contains('hidden') &&
      !el.hidden;
    const isVisible = el =>
      isOpen(el) &&
      !el.closest('.hidden,[hidden]') &&
      el.getClientRects().length > 0;
    const isEnabled = el =>
      isVisible(el) &&
      !isDecoyPickerElement(el) &&
      !el.disabled &&
      el.getAttribute('aria-disabled') !== 'true';

    const picker = document.getElementById('picker');
    if (isOpen(picker) && !picker.classList.contains('closing')) {
      const yes = Array.from(document.querySelectorAll('#picker .pk-slot.asking [data-yes]')).find(isEnabled);
      if (yes) {
        invokeCardHandler(yes);
        return;
      }
      const cards = Array.from(document.querySelectorAll('#picker .pk-card')).filter(isEnabled);
      if (cards.length) {
        let bestCard = cards[0];
        let bestScore = -Infinity;
        const colorScores = {
          '#f2f2f2': 0, '#d9d9d9': 0,
          '#72e384': 1, '#5cd670': 1,
          '#64b5ff': 2, '#4da6ff': 2,
          '#c586ff': 3, '#b366ff': 3,
          '#ffb84d': 4, '#ffa31a': 4,
          '#ff6680': 5, '#ff4d6d': 5
        };
        cards.forEach((card, idx) => {
          const slot = card.closest('.pk-slot');
          const q = slot?.style?.getPropertyValue?.('--q')?.trim()?.toLowerCase() || '';
          const badge = slot?.querySelector?.('.pk-badge')?.textContent?.trim() || '';
          let score = Object.hasOwn(colorScores, q) ? colorScores[q] * 10 : 0;
          if (/神話|mític|mythic/i.test(badge)) score = Math.max(score, 50);
          else if (/傳說|legend/i.test(badge)) score = Math.max(score, 40);
          else if (/史詩|épic|epic/i.test(badge)) score = Math.max(score, 30);
          else if (/稀有|rar|rare/i.test(badge)) score = Math.max(score, 20);
          else if (/罕見|poco común|uncommon/i.test(badge)) score = Math.max(score, 10);
          if (slot?.querySelector?.('.pk-ribbon')) score += 5;
          if (slot?.querySelector?.('.pk-mark')) score += 100;
          score -= idx * 0.01;
          if (score > bestScore) {
            bestScore = score;
            bestCard = card;
          }
        });
        invokeCardHandler(bestCard);
        return;
      }
    }
  }

  const farmTimer = setInterval(() => {
    try {
      farm.tick();
      standAloneAutoPickStep();
    } catch (error) {
      setFarmRuntimeActive(false);
      farm.stop('連刷已停止：' + error.message);
    }
  }, 250);

  window.addEventListener('pagehide', () => {
    // Keep farmRuntimeActive so 5s reload / reconnect resumes automatically.
    farm.stop('頁面已關閉', false);
    clearInterval(farmTimer);
    clearInterval(antiIdleTimer);
  });

  function choose(id, value) {
    const normalized = settingValue(id, value);
    $(id).value = String(normalized);
    if (id === 'mobDropCap') {
      $(id + 'Value').textContent = normalized === 0 ? '自動' : `${normalized}隻`;
      return;
    }
    $(id + 'Value').textContent =
      (id === 'discovery' ? '+' : '') +
      $(id).value +
      (['discovery', 'cooldown'].includes(id) ? '%' : '×');
  }

  function resetMeter() {
    samples = [];
    state.limited = 0;
    $('meter').textContent = '實測速度：—';
  }

  const panelSettingsKey = 'ros-panel-settings-v1';

  function savePanelSettings() {
    try {
      const payload = {
        ...Object.fromEntries(
          Object.keys(limits).map(id => [id, settingValue(id, $(id).value)])
        ),
        god: $('god').checked,
        fullHp: $('fullHp').checked,
        lootAudit: $('lootAudit').checked,
        collapsed: $('body').classList.contains('hidden')
      };
      localStorage.setItem(panelSettingsKey, JSON.stringify(payload));
    } catch {}
  }

  function loadPanelSettings() {
    try {
      const saved = JSON.parse(localStorage.getItem(panelSettingsKey));
      if (!saved || typeof saved !== 'object') return;
      for (const id of Object.keys(limits)) {
        if (saved[id] !== undefined) {
          const val = settingValue(id, saved[id]);
          choose(id, val);
        }
      }
      if (typeof saved.god === 'boolean') {
        $('god').checked = saved.god;
      }
      if (typeof saved.fullHp === 'boolean') {
        $('fullHp').checked = saved.fullHp;
      }
      if (typeof saved.lootAudit === 'boolean') {
        $('lootAudit').checked = saved.lootAudit;
        if (saved.lootAudit) lootAudit.start();
        else lootAudit.stop();
      }
      if (saved.collapsed === true) {
        $('body').classList.add('hidden');
        keepPanelVisible();
      }
    } catch {
      return;
    }
  }

  function restore(stopFarm = true, persist = true) {
    if (stopFarm) {
      setFarmRuntimeActive(false);
      farm?.stop();
    }

    generation++;

    Object.assign(state, defaults, {
      god: false,
      fullHp: false,
      autoPick: farmConfig.autoPick,
      immediateCards: farmConfig.immediateCards,
      inputClockBlocked: false
    });

    for (const id of Object.keys(limits)) choose(id, defaults[id]);

    $('god').checked = $('fullHp').checked = false;

    purgeTrapFlags();
    gameControl?.refresh();
    animation?.restore();
    animation = null;

    if (persist) savePanelSettings();

    resetMeter();
    $('discoveryStatus').textContent = '尋寶率：原始值';
    report('已還原');
  }

  async function applySettings() {
    const request = ++generation;
    purgeTrapFlags();

    const desired = Object.fromEntries(
      Object.keys(limits).map(id => [id, settingValue(id, $(id).value)])
    );

    desired.god = $('god').checked;
    desired.fullHp = $('fullHp').checked;
    desired.autoPick = farmConfig.autoPick;
    desired.immediateCards = farmConfig.immediateCards;

    try {
      const main = findMainScript();

      if (!main) throw Error('找不到遊戲主腳本（/build/main-*.js）');

      const speedChanged = state.speed !== desired.speed;
      Object.assign(state, desired);

      if (speedChanged) resetMeter();

      gameControl?.refresh();

      if (state.animation > 1) {
        if (!animation) animation = installAnimationHook(state);
        else animation.refresh();
      } else if (animation) {
        animation.restore();
        animation = null;
      }

      if (!gameControl) {
        report('連接中…');

        const module = await loadGameModule(main);
        if (request !== generation) return;

        if (!gameControl) {
          const hooks = isGameHooks(module.ei)
            ? module.ei
            : Object.values(module).find(isGameHooks);
          gameControl = installGameHook(hooks, state, report);
        }
      }

      if (request !== generation) return;

      gameControl?.refresh();
      report('已套用');
    } catch (e) {
      if (request === generation) {
        restore(true, false);
        report('未啟用：' + e.message);
      }
    }
  }

  for (const id of Object.keys(limits)) {
    $(id).addEventListener('input', () => {
      choose(id, $(id).value);
      savePanelSettings();
      void applySettings();
    });
  }

  for (const id of ['god', 'fullHp']) {
    $(id).addEventListener('change', () => {
      savePanelSettings();
      void applySettings();
    });
  }

  $('lootAudit').addEventListener('change', () => savePanelSettings());

  function togglePanel() {
    $('body').classList.toggle('hidden');
    savePanelSettings();
    keepPanelVisible();
  }

  $('collapse').onclick = togglePanel;
  $('reset').onclick = () => restore();

  function handleHotkeys(e) {
    if (e.code === 'F8' || e.code === 'F9') {
      e.preventDefault();
      e.stopImmediatePropagation();
      e.code === 'F8' ? togglePanel() : restore();
    }
  }

  document.addEventListener('keydown', handleHotkeys, true);

  function returnPanel(closing = false) {
    if (!panelWindow) return;

    const old = panelWindow;
    panelWindow = null;

    clearInterval(popupMonitor);
    document.documentElement.append(host);
    host.style.cssText = floatingStyle;

    $('popout').textContent = '↗';
    $('popout').title = '另開視窗';

    keepPanelVisible();

    if (!closing && !old.closed) old.close();
  }

  function openPanelWindow() {
    if (panelWindow) {
      if (!panelWindow.closed) {
        returnPanel();
        return;
      }
      returnPanel(true);
    }

    const popup = window.open(
      '',
      'ros-controls-v060',
      'popup=yes,width=370,height=780,resizable=yes,scrollbars=yes'
    );

    if (!popup) {
      report('瀏覽器阻擋了新視窗；允許此網站開啟彈出式視窗後再按一次。');
      return;
    }

    try {
      floatingStyle = host.style.cssText;

      popup.document.title = 'RO 控制面板';
      popup.document.body.style.cssText =
        'margin:0;padding:10px;background:#0d1420;min-height:100vh;box-sizing:border-box';

      panelWindow = popup;

      host.style.cssText =
        'position:relative;display:block;width:max-content;max-width:100%;margin:0 auto';

      popup.document.body.append(popup.document.adoptNode(host));

      $('popout').textContent = '↙';
      $('popout').title = '收回頁面';

      popup.document.addEventListener('keydown', handleHotkeys, true);

      popup.addEventListener('beforeunload', () => {
        if (panelWindow === popup) returnPanel(true);
      }, { once: true });

      popupMonitor = setInterval(() => {
        if (panelWindow?.closed) returnPanel(true);
      }, 500);

      popup.focus();
    } catch (e) {
      if (panelWindow) returnPanel();
      else popup.close();

      report('無法開啟控制視窗：' + e.message);
    }
  }

  $('popout').onclick = openPanelWindow;
  window.addEventListener('pagehide', () => returnPanel());

  setInterval(() => {
    if (state.inputClockBlocked) {
      $('status').textContent = '瀏覽器不允許縮短點選鎖定';
    }

    $('discoveryStatus').textContent =
      gameControl ? gameControl.status() : '尋寶率：原始值';

    const text = document.getElementById('timer')?.textContent?.trim();
    const match = text?.match(/^(\d+):(\d{2})$/);

    const blocked = ['picker', 'pause', 'gameover'].some(id => {
      const e = document.getElementById(id);
      return e && !e.classList.contains('hidden');
    });

    if (!match || blocked || document.hidden) {
      samples = [];
      $('meter').textContent = '實測速度：暫停／選卡中';
      return;
    }

    const now = performance.now();
    const game = Number(match[1]) * 60 + Number(match[2]);

    if (samples.length && game < samples.at(-1).game) samples = [];

    samples.push({ now, game });

    while (samples.length > 1 && now - samples[0].now > 11000) {
      samples.shift();
    }

    const first = samples[0];
    const elapsed = (now - first.now) / 1000;

    $('meter').textContent = elapsed >= 5
      ? `實測速度：${((game - first.game) / elapsed).toFixed(2)}×${state.limited ? ' · 效能限制' : ''}`
      : '實測速度：量測中…';
  }, 500);

  let apiNetworkErrors = 0;
  const origFetch = window.fetch;
  window.fetch = function watchedFetch(...args) {
    const result = origFetch.apply(this, args);
    try {
      const input = args[0];
      const url = new URL(typeof input === 'string' ? input : input?.url ?? input, document.baseURI);
      if (url.origin === location.origin) {
        const isStatus = url.pathname.endsWith('/status.json');
        const isApi = url.pathname.startsWith('/api/');
        if (isStatus || isApi) {
          result.then(
            res => {
              if (isApi) {
                if (res.status >= 502 && res.status <= 504) {
                  farm.triggerReload(`伺服器回應 ${res.status}`);
                } else if (res.ok) {
                  apiNetworkErrors = 0;
                }
              }
              if (isStatus && res.ok) {
                res.clone().json().then(data => {
                  if (data?.state === 'maintenance') {
                    farm.triggerReload('伺服器維修中');
                  }
                }).catch(() => {});
              }
            },
            () => {
              if (isApi && ++apiNetworkErrors >= 2) {
                farm.triggerReload('API 連線中斷');
              }
            }
          ).catch(() => {});
        }
      }
    } catch {}
    return result;
  };

  report('就緒 · 即時套用');

  loadPanelSettings();
  void applySettings();

  if (isFarmRuntimeActive() && farmConfig.autoReconnect) {
    farm.start(false, true);
  }
})();
