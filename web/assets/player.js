/**
 * RadioPlayer — componente de audio unificado para Radio Argentina v2.
 *
 * Uso:
 *   var p = RadioPlayer({
 *     slug:   'la-brujula-24',
 *     url:    'http://...',
 *     nombre: 'La Brújula 24',
 *     source: 'web-listing',   // o 'web-station'
 *
 *     // Callbacks opcionales — el player no toca el DOM
 *     onState:     function(state) {},            // idle/connecting/playing/buffering/error
 *     onNowPlaying: function(title) {},            // string o null
 *     onListeners: function(total, station) {},    // conteos
 *     onError:     function(rawUrl, nombre, msg){},// para mostrar botón VLC etc.
 *   });
 *
 *   p.play();
 *   p.stop();
 *   p.toggle();
 *   p.setStation(slug, url, nombre);   // cambiar emisora (listing)
 *   p.getState();                      // → string
 *   p.destroy();                       // limpiar timers y listeners
 *
 * El player NO renderiza HTML. Las páginas definen su propio DOM
 * y actualizan la UI en los callbacks.
 */

(function (global) {
  'use strict';

  var API_BASE  = '/radio/api';
  var PROXY_URL = '/radio/proxy.php?url=';
  var HB_MS     = 30000;   // heartbeat cada 30s
  var NP_MS     = 30000;   // now-playing poll cada 30s
  var TIMEOUT_MS = 12000;  // timeout de carga de stream
  var SURVEY_SECS = 180;   // 3 minutos para mostrar encuesta

  function RadioPlayer(opts) {
    // ── Config ──────────────────────────────────────────────────────────────
    var slug   = opts.slug   || '';
    var url    = opts.url    || '';
    var nombre = opts.nombre || '';
    var source = opts.source || 'web-listing';

    var onState      = opts.onState      || function () {};
    var onNowPlaying = opts.onNowPlaying || function () {};
    var onListeners  = opts.onListeners  || function () {};
    var onError      = opts.onError      || function () {};

    // ── Estado ───────────────────────────────────────────────────────────────
    var state      = 'idle';
    var audio      = new Audio();
    var hlsInst    = null;
    var loadTimer  = 0;
    var hbTimer    = 0;
    var npTimer    = 0;
    var survTimer  = 0;
    var survSecs   = 0;
    var survShown  = false;
    var destroyed  = false;

    // SID persistido en sessionStorage: un SID por pestaña del navegador
    var sid = sessionStorage.getItem('radio_sid_v2');
    if (!sid) {
      sid = Math.random().toString(36).slice(2) + Date.now().toString(36);
      sessionStorage.setItem('radio_sid_v2', sid);
    }

    // ── Audio events ─────────────────────────────────────────────────────────
    audio.addEventListener('playing', function () {
      clearTimeout(loadTimer);
      setState('playing');
      lStart();
      npStart();
      survStart();
    });

    audio.addEventListener('waiting', function () {
      if (state === 'playing') setState('buffering');
    });

    audio.addEventListener('error', function () {
      if (destroyed) return;
      clearTimeout(loadTimer);
      setState('error');
      lStop(true);
      npStop();
      survStop();
      onNowPlaying(null);
      onError(url, nombre, 'no disponible en web');
    });

    audio.addEventListener('pause', function () {
      // 'pause' se dispara también al hacer audio.src = '' — ignorar si ya estamos en idle
      if (state !== 'idle' && state !== 'stopped') {
        setState('stopped');
      }
    });

    // Page Visibility API: ping inmediato al volver al foco (móvil pausa setInterval)
    document.addEventListener('visibilitychange', function () {
      if (document.hidden || state !== 'playing') return;
      lPing();
      fetchNP();
    });

    window.addEventListener('beforeunload', function () {
      lStop(false);   // sendBeacon: no esperar respuesta
    });

    // ── Estado interno ────────────────────────────────────────────────────────
    function setState(s) {
      state = s;
      onState(s);
    }

    // ── Play / Stop ───────────────────────────────────────────────────────────
    function resolveUrl(raw) {
      if (/\.pls(\?|$)/i.test(raw)) return PROXY_URL + encodeURIComponent(raw);
      if (/\.m3u(\?|$)/i.test(raw) && !/\.m3u8(\?|$)/i.test(raw))
        return PROXY_URL + encodeURIComponent(raw);
      // Streams HTTP desde página HTTPS: usar proxy (no upgrade directo, los certs suelen fallar)
      if (location.protocol === 'https:' && raw.indexOf('http://') === 0)
        return PROXY_URL + encodeURIComponent(raw);
      return raw;
    }

    function play() {
      if (destroyed) return;
      setState('connecting');

      clearTimeout(loadTimer);
      loadTimer = setTimeout(function () {
        if (state === 'connecting' || state === 'buffering') {
          setState('error');
          audio.pause();
          onError(url, nombre, 'sin señal (timeout)');
        }
      }, TIMEOUT_MS);

      var resolved = resolveUrl(url);
      var isHls    = /\.m3u8(\?|$)/i.test(url);

      if (isHls && typeof Hls !== 'undefined' && Hls.isSupported()) {
        if (hlsInst) { hlsInst.destroy(); hlsInst = null; }
        hlsInst = new Hls({ maxBufferLength: 20 });
        hlsInst.loadSource(resolved);
        hlsInst.attachMedia(audio);
        hlsInst.on(Hls.Events.MANIFEST_PARSED, function () {
          audio.play().catch(function () {
            clearTimeout(loadTimer);
            setState('error');
            onError(url, nombre, 'no disponible');
          });
        });
        hlsInst.on(Hls.Events.ERROR, function (_, d) {
          if (d.fatal) {
            clearTimeout(loadTimer);
            setState('error');
            onError(url, nombre, 'no disponible');
          }
        });
      } else {
        audio.src = resolved;
        audio.play().catch(function () {
          clearTimeout(loadTimer);
          setState('error');
          onError(url, nombre, 'no disponible en web');
        });
      }
    }

    function stop() {
      clearTimeout(loadTimer);
      if (hlsInst) { hlsInst.destroy(); hlsInst = null; }
      audio.pause();
      audio.src = '';
      setState('idle');
      lStop(true);
      npStop();
      survStop();
      onNowPlaying(null);
    }

    function toggle() {
      if (state === 'idle' || state === 'stopped' || state === 'error') {
        play();
      } else {
        stop();
      }
    }

    // Cambiar emisora sin recargar la página (para el listado)
    function setStation(newSlug, newUrl, newNombre) {
      var wasPlaying = (state === 'playing' || state === 'buffering' || state === 'connecting');

      clearTimeout(loadTimer);
      if (hlsInst) { hlsInst.destroy(); hlsInst = null; }
      audio.pause();
      audio.src = '';
      lStop(true);
      npStop();
      survStop();
      onNowPlaying(null);

      slug      = newSlug;
      url       = newUrl;
      nombre    = newNombre;
      survSecs  = 0;
      survShown = false;

      setState('idle');
      if (wasPlaying) play();
    }

    // ── Heartbeat / oyentes ───────────────────────────────────────────────────
    function lPing() {
      fetch(API_BASE + '/listeners?action=ping'
        + '&sid='     + encodeURIComponent(sid)
        + '&station=' + encodeURIComponent(slug)
        + '&source='  + encodeURIComponent(source))
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !d.ok) return;
          onListeners(d.data.count, d.data.listeners_station);
        })
        .catch(function () {});
    }

    function lStart() {
      clearInterval(hbTimer);
      lPing();
      hbTimer = setInterval(lPing, HB_MS);
    }

    function lStop(beacon) {
      clearInterval(hbTimer); hbTimer = 0;
      if (beacon !== false) {
        navigator.sendBeacon(API_BASE + '/listeners?action=stop&sid=' + encodeURIComponent(sid));
      }
    }

    // Poll pasivo: actualizar contador aunque no estemos reproduciendo
    function pollPassive() {
      fetch(API_BASE + '/listeners')
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (d && d.ok) onListeners(d.data.count, 0);
        })
        .catch(function () {});
    }

    // ── Now playing (ICY) ─────────────────────────────────────────────────────
    function fetchNP() {
      if (!slug) return;
      fetch(API_BASE + '/nowplaying?slug=' + encodeURIComponent(slug))
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          var title = (d && d.ok && d.data && d.data.title) ? d.data.title : null;
          onNowPlaying(title);
        })
        .catch(function () {});
    }

    function npStart() {
      clearInterval(npTimer);
      fetchNP();
      npTimer = setInterval(fetchNP, NP_MS);
    }

    function npStop() {
      clearInterval(npTimer); npTimer = 0;
    }

    // ── Survey ────────────────────────────────────────────────────────────────
    function survStart() {
      clearInterval(survTimer);
      survTimer = setInterval(function () {
        survSecs += 5;
        if (survSecs >= SURVEY_SECS) {
          clearInterval(survTimer);
          showSurvey();
        }
      }, 5000);
    }

    function survStop() {
      clearInterval(survTimer); survTimer = 0;
    }

    function showSurvey() {
      if (survShown) return;
      var key = 'survey_v2_' + slug;
      var stored = localStorage.getItem(key);
      if (stored && (Date.now() - parseInt(stored, 10)) / 86400000 < 30) return;
      survShown = true;

      var toast = document.createElement('div');
      toast.className = 'rp-survey';
      toast.innerHTML =
        '<span class="rp-survey-q">&#x1F3B5; ' + esc(nombre) + ' &#8212; &#191;qu&#233; te pareci&#243;?</span>' +
        '<div class="rp-survey-btns">' +
          '<button data-r="1"  title="Me gusta">&#128077;</button>' +
          '<button data-r="0"  title="Regular">&#128528;</button>' +
          '<button data-r="-1" title="No me gusta">&#128078;</button>' +
        '</div>' +
        '<button class="rp-survey-skip">Ahora no</button>' +
        '<button class="rp-survey-close" aria-label="Cerrar">&#x2715;</button>';
      document.body.appendChild(toast);

      // Animar entrada
      requestAnimationFrame(function () { toast.classList.add('rp-survey--in'); });

      function dismiss(days) {
        localStorage.setItem(key, String(Date.now() - (30 - days) * 86400000));
        toast.classList.remove('rp-survey--in');
        toast.classList.add('rp-survey--out');
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 400);
      }

      toast.querySelectorAll('[data-r]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          fetch(API_BASE + '/survey', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ slug: slug, rating: parseInt(btn.dataset.r, 10) })
          }).catch(function () {});
          dismiss(30);
        });
      });
      toast.querySelector('.rp-survey-skip').addEventListener('click', function () { dismiss(7); });
      toast.querySelector('.rp-survey-close').addEventListener('click', function () { dismiss(7); });
    }

    // ── Utilidades ────────────────────────────────────────────────────────────
    function esc(s) {
      return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
                      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ── API pública ───────────────────────────────────────────────────────────
    function destroy() {
      destroyed = true;
      stop();
      document.removeEventListener('visibilitychange', arguments.callee);
    }

    // Poll pasivo inicial (ver si otros escuchan aunque vos no estés)
    pollPassive();
    var passiveTimer = setInterval(function () {
      if (state !== 'playing' && state !== 'buffering') pollPassive();
    }, HB_MS);

    return {
      play:       play,
      stop:       stop,
      toggle:     toggle,
      setStation: setStation,
      getState:   function () { return state; },
      getSlug:    function () { return slug; },
      destroy:    function () {
        destroyed = true;
        clearInterval(passiveTimer);
        stop();
      },
    };
  }

  global.RadioPlayer = RadioPlayer;

}(window));
