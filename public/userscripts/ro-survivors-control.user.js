// ==UserScript==
// @name         RO Survivors 實驗控制面板
// @namespace    local.ro-survivors.controls
// @version      0.6.14
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
      return {
        gear: sources.reduce((sum, row) => sum + row.gear, 0),
        cards: sources.reduce((sum, row) => sum + row.cards, 0), sources
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
          'targets', 'pickup', 'suction', 'animation'].map(k => [k, number(settings[k])])),
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
        const missing = event.granted?.missingSources;
        const missingText = Array.isArray(missing) && missing.length
          ? `（少：${missing.slice(0, 3).map(m => `${m.source}×${m.missing}`).join('、')}${missing.length > 3 ? '…' : ''}）`
          : '';
        return `第 ${run.id} 局：送出 ${sent} 件／核發 ${received}${missingText}` +
          (typeof balls === 'number' && balls > 0 ? ` · 扭蛋 ${balls}` : '') +
          ` · 遊戲 ${event.gameSeconds ?? '—'} 秒／` +
          `${run.completeStart ? '實際' : '記錄期間'} ${event.atSeconds} 秒` +
          (['http-error', 'network-error', 'unreadable-response'].includes(event.status)
            ? ` · ${event.httpStatus ?? ''} ${event.error ?? event.status}` : '') +
          (event.op === 'gloot' ? '（組隊分配未追蹤）' : '');
      },
      exportData() {
        return JSON.parse(JSON.stringify({
          version: 1, scriptVersion: '0.6.14', exported: new Date().toISOString(),
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
    let baseDiscovery = 0;
    let baseMoney = 1;
    let appliedDiscovery = 0;
    let appliedMoney = 1;
    let extra = 0;
    let moneyFactor = 1;

    function release() {
      if (tracked) {
        if (Number.isFinite(tracked.discovery)) tracked.discovery -= extra;
        if (Number.isFinite(tracked.zenyMult)) tracked.zenyMult /= moneyFactor;
      }
      tracked = null;
      extra = 0;
      moneyFactor = 1;
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
        baseDiscovery = stats.discovery;
        baseMoney = stats.zenyMult;
        appliedDiscovery = baseDiscovery;
        appliedMoney = baseMoney;
      } else {
        if (stats.discovery !== appliedDiscovery) {
          baseDiscovery += stats.discovery - appliedDiscovery;
        }
        if (stats.zenyMult !== appliedMoney) {
          baseMoney += (stats.zenyMult - appliedMoney) / moneyFactor;
        }
      }

      extra = Math.max(0, Math.min(300, Number(state.discovery) || 0)) / 100;
      moneyFactor = clamp(state.money, 100);

      appliedDiscovery = baseDiscovery + extra;
      appliedMoney = baseMoney * moneyFactor;

      stats.discovery = appliedDiscovery;
      stats.zenyMult = appliedMoney;

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

    function syncHealth(game, record) {
      record.discovery?.refresh();
      record.cooldowns?.refresh();

      for (const weapon of game.weapons || []) {
        if (
          record.weapons.has(weapon) ||
          typeof weapon.targetCount !== 'function'
        ) continue;

        const original = weapon.targetCount;
        const descriptor = Object.getOwnPropertyDescriptor(
          weapon,
          'targetCount'
        );

        const wrapped = function(...args) {
          const result = original.apply(this, args);
          return args[0] === game && Number.isFinite(result)
            ? Math.ceil(result * clamp(state.targets, 100))
            : result;
        };

        patchHiddenMethod(weapon, 'targetCount', wrapped, descriptor);
        record.weapons.set(weapon, { original, descriptor, wrapped });
      }

      for (const [weapon, p] of record.weapons) {
        if (game.weapons?.includes(weapon)) continue;

        restorePatchedMethod(weapon, 'targetCount', p.wrapped, p.descriptor);
        record.weapons.delete(weapon);
      }

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

      for (const [weapon, p] of record.weapons) {
        restorePatchedMethod(weapon, 'targetCount', p.wrapped, p.descriptor);
      }

      record.weapons.clear();

      if (record.godApplied) game.god = record.godBefore;

      for (const p of record.methods) {
        restorePatchedMethod(game, p.name, p.wrapped, p.descriptor);
      }
    }

    function maxBossSpawnTime(bosses) {
      if (!Array.isArray(bosses)) return null;
      let maxTime = null;
      for (const boss of bosses) {
        const t = boss?.t;
        // Ignore the 1e9 (Jl) placeholder assigned before a rare boss is summoned.
        if (typeof t === 'number' && Number.isFinite(t) && t >= 0 && t < 1e8) {
          maxTime = maxTime === null ? t : Math.max(maxTime, t);
        }
      }
      return maxTime;
    }

    const VALUABLE_PICKUP_KINDS = new Set(['drop', 'ball', 'loot', 'card', 'egg', 'mystery']);

    function hasPendingValuablePickups(game) {
      if (!Array.isArray(game?.pickups)) return false;
      for (const item of game.pickups) {
        if (!item?.dead && VALUABLE_PICKUP_KINDS.has(item?.kind)) return true;
      }
      return false;
    }

    function observe(game) {
      if (disposed || !game?.player || !game.stats) return;

      neutralizeSpringTrap(game);
      latest = game;
      let record = games.get(game);

      if (!record) {
        const initialTime = Number.isFinite(game.time) ? Math.max(0, game.time) : 0;
        record = {
          godApplied: false,
          methods: [],
          weapons: new Map(),
          discovery: null,
          cooldowns: null,
          accelerated: false,
          realTime: initialTime,
          lastGameTime: initialTime,
          bossSpawnTime: null
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
            // During the post-boss victory window (closeAt >= 0), run at 1x speed
            // so the 10s loot countdown is not compressed into 1s and pending
            // `onDrop` tick timers and flying drops/balls can settle cleanly.
            const factor = closing ? 1 : requestedFactor;

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
            const count = Math.ceil(factor);
            const began = performance.now();
            let result;

            for (let i = 0; i < count; i++) {
              if (disposed || this.state !== 'playing') break;
              if (Number.isFinite(this.closeAt) && this.closeAt >= 0) break;

              if (i > 0 && performance.now() - began >= 12) {
                state.limited++;
                break;
              }

              result = original.call(this, dt * factor / count);
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
                  const distance = Math.hypot(dx, dy);
                  const reach = radius * (item.kind === 'drop' ? 3.5 : 1);

                  if (
                    !(distance > 18) ||
                    (!item.magnet && distance >= reach)
                  ) continue;

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

      const observedBossTime = maxBossSpawnTime(game.script?.bosses);
      if (observedBossTime !== null) {
        record.bossSpawnTime = record.bossSpawnTime === null
          ? observedBossTime
          : Math.max(record.bossSpawnTime, observedBossTime);
      }

      syncHealth(game, record);
    }

    function guard(game, damage) {
      observe(game);
      return state.god ? 0 : damage;
    }

    function damage(game) {
      if (!games.has(game)) observe(game);
      return clamp(state.attack, 100);
    }

    const onStart = game => observe(game);
    const onTick = game => observe(game);

    hooks.start.push(onStart);
    hooks.tick.push(onTick);
    hooks.hurt.push(guard);
    hooks.damageMult.push(damage);

    return {
      refresh() {
        for (const [game, record] of games) syncHealth(game, record);
      },
      status() {
        const record = latest && games.get(latest);
        if (!record) return '尋寶率：等待進入戰鬥';

        const value = record.discovery.value();
        return `尋寶率：${(value.base * 100).toFixed(0)}% → ${(value.total * 100).toFixed(0)}%`;
      },
      realSeconds() {
        const record = latest && games.get(latest);
        if (!record || !record.accelerated || !Number.isFinite(record.realTime)) {
          return null;
        }
        return Math.max(0, Math.round(record.realTime));
      },
      bossSpawnSeconds() {
        const record = latest && games.get(latest);
        if (!record || !record.accelerated) return null;
        const liveTime = maxBossSpawnTime(latest?.script?.bosses);
        const recordedTime = record.bossSpawnTime;
        if (liveTime === null && recordedTime === null) return null;
        if (liveTime === null) return recordedTime;
        if (recordedTime === null) return liveTime;
        return Math.max(liveTime, recordedTime);
      },
      restore() {
        disposed = true;

        for (const [list, fn] of [
          [hooks.start, onStart],
          [hooks.tick, onTick],
          [hooks.hurt, guard],
          [hooks.damageMult, damage]
        ]) {
          const at = list.indexOf(fn);
          if (at >= 0) list.splice(at, 1);
        }

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

      const current = Array.from(picker.querySelectorAll('.pk-card'));

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
        ...picker.querySelectorAll('[data-yes]')
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
        node = picker.querySelector('.pk-slot.asking [data-yes]');
      }

      if (!node) return;

      event.preventDefault();
      event.stopImmediatePropagation();

      if (!event.repeat) node.click();
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

    const tracks = new WeakMap();
    const classification = new WeakMap();
    const animations = new Map();

    let disposed = false;
    let pending = 0;
    let inputClock = null;

    function isReveal(fn) {
      if (!classification.has(fn)) {
        const s = Function.prototype.toString.call(fn);
        classification.set(
          fn,
          s.includes('pk-landed') &&
          /\.slots\[/.test(s) &&
          /const\s+\w+=\w+-\w+/.test(s)
        );
      }
      return classification.get(fn);
    }

    function scaledTimeout(fn, delay, ...args) {
      const ms = !disposed && classifyTimer(fn, Number(delay))
        ? Number(delay) / clamp(state.animation, 10)
        : delay;

      return timeout.call(window, fn, ms, ...args);
    }

    function scaledFrame(fn) {
      if (disposed || typeof fn !== 'function' || !isReveal(fn)) {
        return raf.call(window, fn);
      }

      let track = tracks.get(fn);

      if (!track) {
        const now = performance.now();
        track = { real: now, virtual: now };
        tracks.set(fn, track);
      }

      return raf.call(window, stamp => {
        track.virtual += Math.max(0, stamp - track.real) *
          (disposed ? 1 : clamp(state.animation, 10));
        track.real = stamp;
        fn(track.virtual);
      });
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
    window.requestAnimationFrame = scaledFrame;
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
        if (window.requestAnimationFrame === scaledFrame) {
          window.requestAnimationFrame = raf;
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
        let active = true;
        function wrapped(layer, ...args) {
          const result = original.call(this, layer, ...args);
          try {
            if (active && inLobby() && this.map?.name === 'prontera_plaza' && !this.gone) {
              const player = { x: this.target?.x, y: this.target?.z };
              const npcs = {};
              for (const [key, { sprite }] of Object.entries(lobbyNpcs)) {
                const sheet = sheets.sheetInfo(sprite);
                const actor = sheet && layer?.children?.find(view =>
                  view.children?.some(child => child.sheet === sheet));
                if (usable(actor)) npcs[key] = { x: actor.x, y: actor.y };
              }
              if (usable(player) && Object.keys(npcs).length) {
                scene = {
                  world: this, player, npcs,
                  captain: npcs.captain || null,
                  festival: npcs.festival || null,
                  at: clock()
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
      refreshStages();
      refreshCharacters();

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

      if (shown('#splash')) {
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
          clickOnce('sp-play', playBtn, now, 1200);
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

      if (shown('#charselect')) {
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

        if (targetSlot && !targetSlot.classList.contains('selected')) {
          const name = targetSlot.querySelector('.slot-name')?.textContent?.trim() || '角色';
          say(`切換掛機角色：${name}…`);
          clickOnce('select-char:' + name, targetSlot, now, 600);
          return;
        }

        const playCharBtn = one('#btn-play');
        if (enabled(playCharBtn)) {
          const name = targetSlot?.querySelector('.slot-name')?.textContent?.trim() || '角色';
          say(`以「${name}」進入遊戲…`);
          clickOnce('char-play', playCharBtn, now, 1500);
          return;
        }

        if (now - phaseAt > 15000) {
          if (!scheduleReload(now, '進入遊戲沒有回應')) {
            stop('進入遊戲沒有回應，已停止');
          }
        } else {
          say('準備進入遊戲…');
        }
        return;
      }

      if (shown('#loading')) {
        changePhase('loading', now);

        if (now - phaseAt > 120000) {
          stop('載入逾時，已停止連刷');
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
        const buttons = all('#npc-options button');

        const informational =
          /^(未完待續[.…！!]*|待續[.…！!]*|Continuará[.…!]*|To be continued[.…!]*|Continua[.…!]*|Continua a seguir[.…!]*)$/i.test(title) ||
          !!one('#npc-text .pending-loot');

        if (informational && buttons.length === 1 && running) {
          clickOnce('ack:' + title, buttons[0], now);
          return;
        }

        const dialogText = one('#npc-text')?.textContent?.trim() || '';
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

        const first = all('#picker .pk-card').find(enabled);

        if (deck !== first) {
          deck = first;
          deckAt = now;
        }

        if (now - deckAt > 30000) {
          stop('選卡超過 30 秒沒有進展，已停止');
          return;
        }

        const yes = all('#picker .pk-slot.asking [data-yes]').find(enabled);
        const target = yes || first;

        if (target) {
          nextAt = now + 600;
          target.click();
          say('自動選卡：第一張可選卡片');
        }
        return;
      }

      const blocking = all('.overlay').find(visible);

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

        const bank = one('#bank-btn');

        if (config.immediateCards && config.autoPick && enabled(bank)) {
          nextAt = now + 1000;
          bank.click();
        }

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
      section{
        width:290px;max-width:calc(100vw - 24px);
        background:#141b28;color:#edf2fc;
        border:1px solid #45516a;border-radius:12px;
        box-shadow:0 12px 36px #0008;
        font:13px/1.5 system-ui,sans-serif;overflow:hidden
      }
      header{
        padding:10px 12px;display:flex;justify-content:space-between;
        align-items:center;background:#24314a;cursor:grab;
        touch-action:none;user-select:none
      }
      header.dragging{cursor:grabbing}
      main{padding:10px 12px;max-height:78vh;overflow:auto}
      small{display:block;color:#aebbd0;font-size:11px;margin-top:5px}
      label{display:block;margin-top:8px}
      output{float:right;color:#aec4ff}
      input[type=range]{width:100%;accent-color:#8eaaff;margin:3px 0}
      button{
        font:inherit;color:inherit;background:#2c3b57;
        border:1px solid #506284;border-radius:6px;
        padding:3px 7px;cursor:pointer
      }
      .row{
        display:flex;align-items:center;gap:8px;
        justify-content:space-between
      }
      .row label{margin:0}
      .info{
        margin-top:9px;border-top:1px solid #344159;
        padding-top:7px;font-size:12px;color:#b9c9e7
      }
      .farm{
        padding-bottom:9px;border-bottom:1px solid #344159;
        margin-bottom:6px
      }
      .farm select{
        width:100%;margin:5px 0;background:#1b263a;color:#edf2fc;
        padding:5px;border:1px solid #506284;
        border-radius:5px;font:inherit
      }
      .farm button:disabled{opacity:.5;cursor:default}
      .farm small{overflow-wrap:anywhere}
      .hidden{display:none}
    </style>
    <section>
      <header id="dragHandle" title="拖曳移動">
        <b>RO 控制台 <small style="display:inline">0.6.14</small></b>
        <span>
          <button id="popout" title="另開視窗">↗</button>
          <button id="collapse" title="收合">−</button>
        </span>
      </header>
      <main id="body">
        <div class="farm">
          <div class="row">
            <b>自動連刷</b>
            <button id="farmRead">讀取關卡</button>
          </div>
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
          <div id="farmCustom" class="hidden">
            ${farmDifficultyFields.map(([key, label, max]) => `
              <label for="farmDiff_${key}">${label}
                <output id="farmDiff_${key}Value">${max}%</output>
              </label>
              <input id="farmDiff_${key}" type="range"
                min="100" max="${max}" step="5" value="${max}">
            `).join('')}
          </div>
          <div class="row">
            <label>
              <input id="farmImmediate" type="checkbox" checked>立即開卡
            </label>
            <label>
              <input id="farmPick" type="checkbox" checked
                title="選第一張可選卡片，遇到技能替換也會確認">自動選首張
            </label>
          </div>
          <label>
            <input id="farmReconnect" type="checkbox" checked>斷線／維修每 5 秒重整並自動進場
          </label>
          <small>關閉立即開卡：升級／寶箱先累積，手動開啟後才選卡。</small>
          <button id="farmToggle" style="width:100%;margin-top:7px">
            開始連刷
          </button>
          <small id="farmStatus">尚未開始</small>
          <small>讀取關卡／開始連刷會自動走向遠征隊長或節慶主持人。</small>
        </div>
        ${[
          ['speed', '遊戲速度', 1, 20, 1, 1, '×'],
          ['animation', '選卡速度', 1, 10, 1, 1, '×'],
          ['attack', '傷害倍率', 1, 100, 1, 1, '×'],
          ['cooldown', '冷卻縮減', 0, 99, 1, 0, '%'],
          ['targets', '目標倍率', 1, 100, 1, 1, '×'],
          ['money', '局內金錢', 1, 100, 1, 1, '×'],
          ['discovery', '額外尋寶率', 0, 300, 10, 0, '%'],
          ['pickup', '撿取範圍', 1, 100, 1, 1, '×'],
          ['suction', '吸取速度', 1, 10, 1, 1, '×']
        ].map(([id, label, min, max, step, value, unit]) => `
          <label for="${id}">
            ${label}
            <output id="${id}Value">${id === 'discovery' ? '+' : ''}${value}${unit}</output>
          </label>
          <input id="${id}" type="range" min="${min}" max="${max}"
            step="${step}" value="${value}">
        `).join('')}
        <div class="row">
          <label><input id="god" type="checkbox">免傷</label>
          <label><input id="fullHp" type="checkbox">滿血</label>
          <button id="reset">還原</button>
        </div>
        <div class="info">
          <div id="status">就緒</div>
          <div id="meter">實測速度：—</div>
          <div id="discoveryStatus">尋寶率：—</div>
        </div>
        <div class="info">
          <div class="row">
            <label><input id="lootAudit" type="checkbox" checked>結算診斷</label>
            <button id="lootExport">匯出報告</button>
          </div>
          <small id="lootStatus" aria-live="polite">等待下一局</small>
          <small>記錄最近 12 局的送出與核發件數；重新整理前可匯出。</small>
        </div>
        <small>
          尋寶率影響品質；結算由伺服器核發。<br>
          目標倍率僅適用支援的技能。F8 收合 · F9 還原
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
    money: 100,
    discovery: 300,
    pickup: 100,
    suction: 10
  };

  const defaults = {
    speed: 1,
    animation: 1,
    attack: 1,
    cooldown: 0,
    targets: 1,
    money: 1,
    discovery: 0,
    pickup: 1,
    suction: 1
  };

  const state = {
    ...defaults,
    god: false,
    fullHp: false,
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

  function loadGameModule(main = findMainScript()) {
    if (!main) return Promise.reject(Error('找不到遊戲主腳本（/build/main-*.js）'));
    modulePromise ??= fetch(main.src, { credentials: 'same-origin' })
      .then(res => {
        if (!res.ok) throw Error(`讀取主腳本失敗（${res.status}）`);
        return res.text();
      })
      .then(text => {
        const match = text.match(SOUL_LINKER_RE);
        if (!match) throw Error('版本不符；找不到 soul_linker 模組');
        return import(new URL(match[2], main.src).href);
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
    autoReconnect: true
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

  const farm = createAutoFarm(
    farmConfig,
    info => {
      $('farmToggle').textContent =
        info.running || info.reading ? '停止連刷' : '開始連刷';

      $('farmStatus').textContent = info.text;

      $('farmCharacter').disabled =
        $('farmStage').disabled =
        $('farmRead').disabled =
        info.running || info.reading;

      for (const id of [
        'farmDifficulty', 'farmImmediate', 'farmPick', 'farmReconnect',
        ...farmDifficultyFields.map(([key]) => 'farmDiff_' + key)
      ]) {
        $(id).disabled = info.running || info.reading;
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
    saveFarm();
  });

  $('farmPick').addEventListener('change', () => {
    farmConfig.autoPick = $('farmPick').checked;
    saveFarm();
  });

  $('farmReconnect').addEventListener('change', () => {
    farmConfig.autoReconnect = $('farmReconnect').checked;
    if (!farmConfig.autoReconnect && !farm.running) setFarmRuntimeActive(false);
    saveFarm();
  });

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

  const farmTimer = setInterval(() => {
    try {
      farm.tick();
    } catch (error) {
      setFarmRuntimeActive(false);
      farm.stop('連刷已停止：' + error.message);
    }
  }, 400);

  window.addEventListener('pagehide', () => {
    // Keep farmRuntimeActive so 5s reload / reconnect resumes automatically.
    farm.stop('頁面已關閉', false);
    clearInterval(farmTimer);
  });

  function choose(id, value) {
    $(id).value = String(settingValue(id, value));
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
      if (!saved || typeof saved !== 'object') return false;
      let hasBoost = false;
      for (const id of Object.keys(limits)) {
        if (saved[id] !== undefined) {
          const val = settingValue(id, saved[id]);
          choose(id, val);
          if (val !== defaults[id]) hasBoost = true;
        }
      }
      if (typeof saved.god === 'boolean') {
        $('god').checked = saved.god;
        if (saved.god) hasBoost = true;
      }
      if (typeof saved.fullHp === 'boolean') {
        $('fullHp').checked = saved.fullHp;
        if (saved.fullHp) hasBoost = true;
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
      return hasBoost;
    } catch {
      return false;
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
      inputClockBlocked: false
    });

    for (const id of Object.keys(limits)) choose(id, defaults[id]);

    $('god').checked = $('fullHp').checked = false;

    gameControl?.restore();
    animation?.restore();
    gameControl = animation = null;

    if (persist) savePanelSettings();

    resetMeter();
    $('discoveryStatus').textContent = '尋寶率：原始值';
    report('已還原');
  }

  async function applySettings() {
    const request = ++generation;

    const desired = Object.fromEntries(
      Object.keys(limits).map(id => [id, settingValue(id, $(id).value)])
    );

    desired.god = $('god').checked;
    desired.fullHp = $('fullHp').checked;

    if (
      Object.keys(limits).every(id => desired[id] === defaults[id]) &&
      !desired.god &&
      !desired.fullHp
    ) {
      restore(false);
      return;
    }

    try {
      const main = findMainScript();

      if (!main) throw Error('找不到遊戲主腳本（/build/main-*.js）');

      const speedChanged = state.speed !== desired.speed;
      Object.assign(state, desired);

      if (speedChanged) resetMeter();

      const needGame =
        state.speed > 1 ||
        state.attack > 1 ||
        state.cooldown > 0 ||
        state.targets > 1 ||
        state.money > 1 ||
        state.discovery > 0 ||
        state.pickup > 1 ||
        state.suction > 1 ||
        state.god ||
        state.fullHp;

      if (!needGame && gameControl) {
        gameControl.restore();
        gameControl = null;
      } else {
        gameControl?.refresh();
      }

      if (state.animation > 1) {
        if (!animation) animation = installAnimationHook(state);
        else animation.refresh();
      } else if (animation) {
        animation.restore();
        animation = null;
      }

      if (needGame && !gameControl) {
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
      'popup=yes,width=330,height=720,resizable=yes,scrollbars=yes'
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
  const adjustedSecsByKey = new Map();
  const playedByKey = new Map();
  const runStartAtByKey = new Map();
  const origFetch = window.fetch;
  function rememberBounded(map, key, value) {
    if (typeof key !== 'string') return;
    map.set(key, value);
    if (map.size > 12) map.delete(map.keys().next().value);
  }
  function normalizeAcceleratedRunTime(input, init) {
    if (!init || typeof init.body !== 'string') return init;
    const url = new URL(typeof input === 'string' ? input : input?.url ?? input, document.baseURI);
    if (url.origin !== location.origin || !['/api/run', '/api/op', '/api/save'].includes(url.pathname)) {
      return init;
    }
    const body = JSON.parse(init.body);
    const now = performance.now();
    if (url.pathname === '/api/run') {
      if (typeof body?.key === 'string') {
        rememberBounded(runStartAtByKey, body.key, now);
      }
      const speed = clamp(state.speed, 100);
      if (speed <= 1 || body?.skip || typeof body?.played === 'number' || typeof body?.key !== 'string') {
        return init;
      }
      // Include the post-boss victory window (900s + 15s) so `played + wallSecs`
      // always covers the full 910s run and final boss drops even at high speed.
      const expectedRunSecs = 915;
      const played = Math.max(0, Math.round(expectedRunSecs - expectedRunSecs / speed));
      if (played <= 0) return init;
      body.played = played;
      rememberBounded(playedByKey, body.key, played);
      return { ...init, body: JSON.stringify(body) };
    }
    const op = url.pathname === '/api/save' ? 'save' : body?.op;
    if (!['tick', 'save', 'loot', 'gloot'].includes(op)) return init;
    const target = op === 'tick' || op === 'save' ? body.rk : body;
    if (!target || typeof target.secs !== 'number' || !Number.isFinite(target.secs)) return init;

    const key = typeof target.key === 'string' ? target.key : null;
    const realSecs = gameControl?.realSeconds?.();
    const playedOffset = key ? (playedByKey.get(key) ?? 0) : 0;
    const startAt = key ? runStartAtByKey.get(key) : undefined;
    const wallSecs = typeof startAt === 'number' && Number.isFinite(startAt)
      ? Math.max(0, Math.floor((now - startAt) / 1000))
      : null;
    const elapsedSecs = realSecs !== null && realSecs !== undefined && Number.isFinite(realSecs)
      ? (wallSecs !== null ? Math.max(realSecs, wallSecs) : realSecs)
      : wallSecs;
    const lastSentSecs = key ? (adjustedSecsByKey.get(key) ?? 0) : 0;

    if (op === 'loot' || op === 'gloot') {
      const bossSecs = gameControl?.bossSpawnSeconds?.();
      const hasBossSecs = bossSecs !== null && bossSecs !== undefined && Number.isFinite(bossSecs);
      if (!hasBossSecs && playedOffset <= 0 && elapsedSecs === null) return init;

      const extraSecs = 5 + Math.floor(Math.random() * 11);
      const bossTarget = hasBossSecs
        ? Math.max(target.secs, Math.ceil(bossSecs) + extraSecs)
        : target.secs;
      const maxAllowed = playedOffset > 0 && elapsedSecs !== null
        ? playedOffset + elapsedSecs
        : null;
      // Never report a final settlement time earlier than a previously sent
      // tick/save or earlier than the final boss spawn / actual game time.
      const floorSecs = Math.max(
        0,
        lastSentSecs,
        target.secs,
        hasBossSecs ? Math.ceil(bossSecs) + 2 : 0
      );
      const bounded = maxAllowed !== null ? Math.min(bossTarget, maxAllowed) : bossTarget;
      const normalized = Math.max(floorSecs, bounded);
      rememberBounded(adjustedSecsByKey, key, normalized);
      if (normalized === target.secs) return init;

      target.secs = normalized;
      return { ...init, body: JSON.stringify(body) };
    }

    const fallbackSecs = key ? adjustedSecsByKey.get(key) : undefined;
    const candidate = elapsedSecs !== null
      ? playedOffset + elapsedSecs
      : fallbackSecs;
    if (candidate === null || candidate === undefined || !Number.isFinite(candidate)) return init;

    const rawNormalized = playedOffset > 0
      ? Math.max(0, Math.min(target.secs, candidate))
      : Math.max(0, Math.min(target.secs, candidate));
    const normalized = Math.max(lastSentSecs, rawNormalized);
    rememberBounded(adjustedSecsByKey, key, normalized);
    if (normalized === target.secs) return init;

    target.secs = normalized;
    return { ...init, body: JSON.stringify(body) };
  }

  window.fetch = function watchedFetch(...args) {
    let nextArgs = args;
    try {
      const nextInit = normalizeAcceleratedRunTime(args[0], args[1]);
      if (nextInit !== args[1]) nextArgs = [args[0], nextInit, ...args.slice(2)];
    } catch {}
    const result = origFetch.apply(this, nextArgs);
    try {
      const input = nextArgs[0];
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

  if (loadPanelSettings()) {
    void applySettings();
  }

  if (isFarmRuntimeActive() && farmConfig.autoReconnect) {
    farm.start(false, true);
  }
})();
