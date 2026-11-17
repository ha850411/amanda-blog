// ==UserScript==
// @name         RO Survivors 實驗控制面板
// @namespace    local.ro-survivors.controls
// @version      0.6.1
// @homepageURL  https://amanda-blog.com/
// @updateURL    https://amanda-blog.com/userscripts/ro-survivors-control.user.js
// @downloadURL  https://amanda-blog.com/userscripts/ro-survivors-control.user.js
// @description  adc0fdf6：自動單人連刷、選關與全滿難度；速度、選卡、尋寶、傷害與冷卻。
// @match        https://ro-survivors.com/*
// @match        https://www.ro-survivors.com/*
// @run-at       document-idle
// @grant        none
// @sandbox      raw
// @noframes
// ==/UserScript==
/* 請取代舊版後重新整理。預設不加成，滑桿即時套用。
 * 尋寶率改變局內品質抽選，不保證伺服器接受修改後的結算數值。
 * 連刷預設關閉；選關、全滿難度與選首張可記憶，重新整理需重新開始。
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

    game.recomputeStats = wrapped;

    try {
      sync();
    } catch (error) {
      if (descriptor) Object.defineProperty(game, 'recomputeStats', descriptor);
      else delete game.recomputeStats;
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

        if (game.recomputeStats === wrapped) {
          if (descriptor) {
            Object.defineProperty(game, 'recomputeStats', descriptor);
          } else {
            delete game.recomputeStats;
          }
        }
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

      if (record.descriptor) {
        Object.defineProperty(weapon, 'finalCooldown', record.descriptor);
      } else {
        delete weapon.finalCooldown;
      }
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
          weapon.finalCooldown = wrapped;
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
    let latest = null;
    let disposed = false;

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

        weapon.targetCount = wrapped;
        record.weapons.set(weapon, { original, descriptor, wrapped });
      }

      for (const [weapon, p] of record.weapons) {
        if (game.weapons?.includes(weapon)) continue;

        if (weapon.targetCount === p.wrapped) {
          if (p.descriptor) {
            Object.defineProperty(weapon, 'targetCount', p.descriptor);
          } else {
            delete weapon.targetCount;
          }
        }
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

      game[name] = wrapped;
      record.methods.push({ name, original, descriptor, wrapped });
    }

    function undo(game, record) {
      record.discovery?.restore();
      record.cooldowns?.restore();

      for (const [weapon, p] of record.weapons) {
        if (weapon.targetCount !== p.wrapped) continue;

        if (p.descriptor) {
          Object.defineProperty(weapon, 'targetCount', p.descriptor);
        } else {
          delete weapon.targetCount;
        }
      }

      record.weapons.clear();

      if (record.godApplied) game.god = record.godBefore;

      for (const p of record.methods) {
        if (game[p.name] !== p.wrapped) continue;

        if (p.descriptor) {
          Object.defineProperty(game, p.name, p.descriptor);
        } else {
          delete game[p.name];
        }
      }
    }

    function observe(game) {
      if (disposed || !game?.player || !game.stats) return;

      latest = game;
      let record = games.get(game);

      if (!record) {
        record = {
          godApplied: false,
          methods: [],
          weapons: new Map(),
          discovery: null,
          cooldowns: null
        };

        games.set(game, record);

        try {
          record.discovery = installStatBoosts(game, state);
          record.cooldowns = installCooldown(game, state);

          patch(game, record, 'advance', original => function(dt) {
            const factor = clamp(state.speed, 10);

            if (
              disposed ||
              factor === 1 ||
              this.state !== 'playing' ||
              !Number.isFinite(dt) ||
              dt <= 0
            ) {
              return original.call(this, dt);
            }

            const count = Math.ceil(factor);
            const began = performance.now();
            let result;

            for (let i = 0; i < count; i++) {
              if (disposed || this.state !== 'playing') break;

              if (i > 0 && performance.now() - began >= 12) {
                state.limited++;
                break;
              }

              result = original.call(this, dt * factor / count);
            }

            return result;
          });

          patch(game, record, 'updatePickups', original => function(dt) {
            if (disposed) return original.call(this, dt);

            const stats = this.stats;
            const base = stats.pickupRadius;
            const bonus = this.buffValue('pickup');
            const radius = (base + bonus) * clamp(state.pickup, 100);
            const suction = clamp(state.suction, 10);

            stats.pickupRadius = radius - bonus;

            try {
              if (
                suction > 1 &&
                dt > 0 &&
                !this.player.dead &&
                !this.coop?.replica
              ) {
                for (const item of this.pickups) {
                  if (
                    item.dead ||
                    item.kind === 'chest' ||
                    item.kind === 'mystery'
                  ) continue;

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
                    speed * dt * (suction - 1)
                  );

                  item.x += dx / distance * extra;
                  item.y += dy / distance * extra;
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

    hooks.start.push(observe);
    hooks.tick.push(observe);
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
      restore() {
        disposed = true;

        for (const [list, fn] of [
          [hooks.start, observe],
          [hooks.tick, observe],
          [hooks.hurt, guard],
          [hooks.damageMult, damage]
        ]) {
          const at = list.indexOf(fn);
          if (at >= 0) list.splice(at, 1);
        }

        for (const [game, record] of games) undo(game, record);
        games.clear();
        latest = null;
      }
    };
  }

  function classifyTimer(fn, delay) {
    if (typeof fn !== 'function') return false;

    const code = Function.prototype.toString.call(fn);

    return (
      delay === 820 &&
      code.includes('s.onPick(A)') &&
      code.includes('cancelAnimationFrame(y)')
    ) || (
      delay === 160 &&
      code.includes('classList.toggle("asking",$)') &&
      code.includes('[data-yes]')
    ) || (
      delay === 450 &&
      code.includes('i.unlock()') &&
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
          s.includes('const v=f-l') &&
          s.includes('pk-landed') &&
          s.includes('i.slots[m]')
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

  function createAutoFarm(
    config,
    notify,
    stagesChanged,
    doc = document,
    clock = () => performance.now()
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
    let choices = [];
    let actions = new Set();
    let deck = null;
    let deckAt = 0;
    let message = '尚未開始';
    let settlementReady = null;
    let readyAt = 0;
    let departedStage = null;

    function say(text) {
      message = text;
      notify({ running, reading, completed, text });
    }

    function stop(text = '已停止；本局可繼續手動遊玩') {
      running = reading = false;
      actions.clear();
      say(text);
    }

    function changePhase(value, now) {
      if (phase !== value) {
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
      if (!cards.length) return;

      const next = cards.map(card => ({
        id: String(card.dataset.i),
        name:
          card.querySelector('.stg-card-info b')?.textContent?.trim() || '',
        blocked:
          card.disabled ||
          card.classList.contains('locked') ||
          card.classList.contains('soon'),
        selected: card.classList.contains('sel')
      })).filter(stage => /^\d+$/.test(stage.id) && stage.name);

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
          return new URL(script.src).pathname === '/build/main-2n_YvaNQ.js';
        } catch {
          return false;
        }
      });
    }

    function start(scanOnly = false) {
      if (!checkBuild()) {
        stop('版本不符；自動連刷未啟用');
        return;
      }

      refreshStages();

      if (
        !scanOnly &&
        !choices.some(stage => stage.id === config.stage && !stage.blocked)
      ) {
        stop('請先讀取關卡，並選擇已解鎖的關卡');
        return;
      }

      running = !scanOnly;
      reading = scanOnly;
      phase = '';
      nextAt = 0;
      actions.clear();
      deck = null;
      settlementReady = null;
      departedStage = null;

      if (!scanOnly) completed = 0;

      say(scanOnly ? '正在讀取關卡…' : '已開始連刷');
      tick();
    }

    function tick() {
      refreshStages();

      if (!running && !reading) return;

      const now = clock();
      if (now < nextAt) return;

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
        changePhase('dialog', now);
        const diff = one('#npc-text .diff');

        if (diff && running) {
          if (departedStage !== config.stage) {
            stop('請先關閉難度視窗，再開始連刷以確認關卡');
            return;
          }

          const sliders = all('#npc-text .diff input[data-key]');
          const keys = ['count', 'damage', 'hp', 'speed', 'events'];

          if (
            sliders.length !== keys.length ||
            !keys.every(key =>
              sliders.some(input => input.dataset.key === key)
            ) ||
            one('#npc-text .diff input[data-pick]')
          ) {
            stop('此模式不是一般單人關卡，已停止');
            return;
          }

          if (config.maxDifficulty) {
            for (const input of sliders) {
              if (
                !Number.isFinite(Number(input.max)) ||
                Number(input.max) <= 0
              ) {
                stop('難度上限讀取失敗');
                return;
              }

              input.value = input.max;
              input.dispatchEvent(
                new doc.defaultView.Event('input', { bubbles: true })
              );
            }

            if (
              sliders.some(input =>
                Number(input.value) !== Number(input.max)
              )
            ) {
              stop('難度未成功設為全滿');
              return;
            }
          }

          if (config.autoPick) {
            const immediate = one('#npc-text .diff [data-bank="0"]');

            if (!enabled(immediate)) {
              stop('找不到升級模式控制項');
              return;
            }

            if (!immediate.classList.contains('active')) {
              immediate.click();
            }
          }

          say(
            config.maxDifficulty
              ? '難度全滿，準備出發…'
              : '使用遊戲目前難度出發…'
          );

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

      if (shown('#stages')) {
        changePhase('stages', now);

        if (reading) {
          stop('關卡已更新，請選關後開始連刷');
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
        const prompt = one('#lobby-prompt');

        if (
          enabled(prompt) &&
          /隊長|captain|capitán|capitão/i.test(prompt.textContent)
        ) {
          say('開啟遠征選單…');
          clickOnce('captain', prompt, now);

          if (now - phaseAt > 15000) {
            stop('遠征選單沒有回應，已停止');
          }
        } else if (now - phaseAt > 20000) {
          stop('請移動到遠征隊長旁，再開始連刷');
        } else {
          say('等待廣場載入／靠近遠征隊長…');
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

        if (config.autoPick && enabled(bank)) {
          nextAt = now + 1000;
          bank.click();
        }

        say('戰鬥中 · 已結算 ' + completed + ' 局');
        return;
      }

      changePhase('unknown', now);

      if (now - phaseAt > 15000) {
        stop('找不到可操作的遊戲畫面，已停止');
      } else {
        say('等待遊戲畫面…');
      }
    }

    return {
      start,
      stop,
      tick,
      refreshStages,
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
        <b>RO 控制台 <small style="display:inline">0.6.1</small></b>
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
          <select id="farmStage" aria-label="連刷關卡">
            <option value="">先開啟一次遠征選單</option>
          </select>
          <div class="row">
            <label>
              <input id="farmMax" type="checkbox" checked>難度全滿
            </label>
            <label>
              <input id="farmPick" type="checkbox" checked
                title="選第一張可選卡片，遇到技能替換也會確認">自動選首張
            </label>
          </div>
          <button id="farmToggle" style="width:100%;margin-top:7px">
            開始連刷
          </button>
          <small id="farmStatus">尚未開始</small>
        </div>
        ${[
          ['speed', '遊戲速度', 1, 10, 1, 1, '×'],
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
    speed: 10,
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

  const farmConfigKey = 'ros-auto-farm-v1';
  const farmConfig = {
    stage: '',
    maxDifficulty: true,
    autoPick: true
  };

  try {
    const saved = JSON.parse(localStorage.getItem(farmConfigKey));

    if (saved) {
      if (/^\d+$/.test(String(saved.stage))) {
        farmConfig.stage = String(saved.stage);
      }
      if (typeof saved.maxDifficulty === 'boolean') {
        farmConfig.maxDifficulty = saved.maxDifficulty;
      }
      if (typeof saved.autoPick === 'boolean') {
        farmConfig.autoPick = saved.autoPick;
      }
    }
  } catch {}

  const saveFarm = () => {
    try {
      localStorage.setItem(farmConfigKey, JSON.stringify(farmConfig));
    } catch {}
  };

  $('farmMax').checked = farmConfig.maxDifficulty;
  $('farmPick').checked = farmConfig.autoPick;

  const farm = createAutoFarm(
    farmConfig,
    info => {
      $('farmToggle').textContent =
        info.running || info.reading ? '停止連刷' : '開始連刷';

      $('farmStatus').textContent = info.text;

      $('farmStage').disabled =
        $('farmRead').disabled =
        info.running || info.reading;

      $('farmMax').disabled =
        $('farmPick').disabled =
        info.running || info.reading;
    },
    stages => {
      const selected =
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
    }
  );

  $('farmStage').addEventListener('change', () => {
    farmConfig.stage = $('farmStage').value;
    saveFarm();
  });

  $('farmMax').addEventListener('change', () => {
    farmConfig.maxDifficulty = $('farmMax').checked;
    saveFarm();
  });

  $('farmPick').addEventListener('change', () => {
    farmConfig.autoPick = $('farmPick').checked;
    saveFarm();
  });

  $('farmRead').onclick = () => farm.start(true);
  $('farmToggle').onclick = () =>
    farm.running ? farm.stop() : farm.start();

  farm.refreshStages();

  const farmTimer = setInterval(() => {
    try {
      farm.tick();
    } catch (error) {
      farm.stop('連刷已停止：' + error.message);
    }
  }, 400);

  window.addEventListener('pagehide', () => {
    farm.stop('頁面已關閉');
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

  function restore(stopFarm = true) {
    if (stopFarm) farm?.stop();

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
      const main = Array.from(document.scripts).find(s => {
        try {
          const u = new URL(s.src);
          return (
            u.origin === location.origin &&
            u.pathname === '/build/main-2n_YvaNQ.js'
          );
        } catch {
          return false;
        }
      });

      if (!main) throw Error('版本不符；僅支援 adc0fdf6');

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

        modulePromise ??= import(
          new URL('./soul_linker-BNTKE1pN.js', main.src).href
        ).catch(e => {
          modulePromise = null;
          throw e;
        });

        const module = await modulePromise;
        if (request !== generation) return;

        if (!gameControl) {
          gameControl = installGameHook(module.ei, state, report);
        }
      }

      if (request !== generation) return;

      gameControl?.refresh();
      report('已套用');
    } catch (e) {
      if (request === generation) {
        restore();
        report('未啟用：' + e.message);
      }
    }
  }

  for (const id of Object.keys(limits)) {
    $(id).addEventListener('input', () => {
      choose(id, $(id).value);
      void applySettings();
    });
  }

  for (const id of ['god', 'fullHp']) {
    $(id).addEventListener('change', () => void applySettings());
  }

  function togglePanel() {
    $('body').classList.toggle('hidden');
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

  report('就緒 · 即時套用');
})();
