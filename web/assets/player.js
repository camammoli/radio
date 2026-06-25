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
  var SURVEY_SECS   = 180;   // 3 minutos para mostrar encuesta
  var WELCOME_SECS  = 90;    // 90s para mostrar toast de bienvenida v2
  var WELCOME_KEY   = 'radio_welcome_v2';

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
    var welcomeTimer = 0;
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
      welcomeStart();
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
      welcomeStop();
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

    // ── HLS.js lazy loader ────────────────────────────────────────────────────
    var hlsJsLoading = false;
    var hlsJsCallbacks = [];

    function loadHlsJs(cb) {
      if (typeof Hls !== 'undefined') { cb(); return; }
      hlsJsCallbacks.push(cb);
      if (hlsJsLoading) return;
      hlsJsLoading = true;
      var s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/hls.js@latest/dist/hls.min.js';
      s.onload = function () {
        hlsJsLoading = false;
        hlsJsCallbacks.forEach(function (fn) { fn(); });
        hlsJsCallbacks = [];
      };
      s.onerror = function () {
        hlsJsLoading = false;
        hlsJsCallbacks.forEach(function (fn) { fn(); });
        hlsJsCallbacks = [];
      };
      document.head.appendChild(s);
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

      if (isHls) {
        loadHlsJs(function () {
          if (destroyed) return;
          if (typeof Hls !== 'undefined' && Hls.isSupported()) {
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
            // HLS nativo del navegador (Safari) o fallo de carga — intentar directo
            audio.src = resolved;
            audio.play().catch(function () {
              clearTimeout(loadTimer);
              setState('error');
              onError(url, nombre, 'no disponible');
            });
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
      welcomeStop();
      onNowPlaying(null);
    }

    function toggle() {
      if (state === 'idle' || state === 'stopped' || state === 'error') {
        play();
      } else {
        stop();
      }
    }

    // Cambiar emisora sin recargar la página (para el listado) y arrancar reproducción
    function setStation(newSlug, newUrl, newNombre) {
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

      // No pasar por 'idle' — ir directo a play() para no resetear activeEl en los callbacks
      play();
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

    // Fetch ICY directo desde el browser (streams HTTPS con CORS).
    // onOk(title|null) si se pudo parsear; onFail() si hay error de red/CORS.
    function fetchIcyBrowser(rawUrl, onOk, onFail) {
      if (/\.(pls|m3u)(\?|$)/i.test(rawUrl)) { onFail(); return; }
      if (/\.m3u8(\?|$)/i.test(rawUrl))       { onFail(); return; }
      // HTTP en página HTTPS → mixed content, no se puede fetchear directo
      if (location.protocol === 'https:' && rawUrl.indexOf('http://') === 0) { onFail(); return; }

      var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var done = false;

      var tmo = setTimeout(function () {
        if (done) return;
        done = true;
        if (ctrl) ctrl.abort();
        onFail();
      }, 15000);

      fetch(rawUrl, {
        headers: { 'Icy-MetaData': '1' },
        signal:  ctrl ? ctrl.signal : undefined,
        cache:   'no-store',
      }).then(function (resp) {
        if (!resp.ok || !resp.body) { clearTimeout(tmo); onFail(); return; }
        var metaint = parseInt(resp.headers.get('icy-metaint') || '0', 10);
        if (!metaint) { clearTimeout(tmo); resp.body.cancel(); onFail(); return; }

        var reader  = resp.body.getReader();
        var buf     = new Uint8Array(0);
        var attempt = 0;

        function finish(title) {
          if (done) return;
          done = true;
          clearTimeout(tmo);
          reader.cancel().catch(function () {});
          onOk(title);
        }

        function concat(a, b) {
          var c = new Uint8Array(a.length + b.length);
          c.set(a); c.set(b, a.length);
          return c;
        }

        function pump() {
          reader.read().then(function (r) {
            if (done) return;
            if (r.done) { clearTimeout(tmo); done = true; onFail(); return; }
            buf = concat(buf, r.value);

            // Procesar bloques completos disponibles en buf
            while (buf.length >= metaint + 1) {
              var metaLen = buf[metaint] * 16;

              if (metaLen === 0) {
                // Bloque vacío — avanzar al siguiente
                buf = buf.slice(metaint + 1);
                attempt++;
                if (attempt >= 4) { finish(null); return; }
                continue;
              }

              if (buf.length < metaint + 1 + metaLen) break; // esperar más datos

              var metaBytes = buf.slice(metaint + 1, metaint + 1 + metaLen);
              var metaStr   = new TextDecoder('utf-8').decode(metaBytes).replace(/\x00+$/, '');
              var m         = metaStr.match(/StreamTitle='([^']*)'/);
              finish(m ? m[1].trim() || null : null);
              return;
            }

            pump();
          }).catch(function () {
            if (!done) { clearTimeout(tmo); done = true; onFail(); }
          });
        }

        pump();

      }).catch(function () {
        clearTimeout(tmo);
        if (!done) { done = true; onFail(); }
      });
    }

    // Fetch via servidor (para HTTP streams o cuando browser fetch falla)
    function fetchNPServer() {
      fetch(API_BASE + '/nowplaying?slug=' + encodeURIComponent(slug))
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          onNowPlaying((d && d.ok && d.data && d.data.title) ? d.data.title : null);
        })
        .catch(function () {});
    }

    function fetchNP() {
      if (!slug) return;
      // HTTP en HTTPS → el browser no puede fetchear directo → server hace el real-time fetch
      var serverOnly = location.protocol === 'https:' && url.indexOf('http://') === 0;
      if (serverOnly) {
        fetchNPServer();
      } else {
        // HTTPS stream → intentar directo desde el browser; si falla, usar server
        fetchIcyBrowser(url, function (title) { onNowPlaying(title); }, fetchNPServer);
      }
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

    // ── Toast bienvenida v2 ───────────────────────────────────────────────────
    function welcomeStart() {
      if (localStorage.getItem(WELCOME_KEY)) return;
      clearTimeout(welcomeTimer);
      welcomeTimer = setTimeout(showWelcome, WELCOME_SECS * 1000);
    }

    function welcomeStop() {
      clearTimeout(welcomeTimer); welcomeTimer = 0;
    }

    function showWelcome() {
      if (localStorage.getItem(WELCOME_KEY)) return;

      var toast = document.createElement('div');
      toast.className = 'rp-welcome';
      toast.innerHTML =
        '<button class="rp-welcome-close" aria-label="Cerrar">&#x2715;</button>' +
        '<h3>&#x1F3B5; &#xA1;El sitio se renov&#xF3;!</h3>' +
        '<ul>' +
          '<li>Ves qu&#xE9; canci&#xF3;n est&#xE1; sonando en cada emisora</li>' +
          '<li>Player con control de volumen</li>' +
          '<li>Carga mucho m&#xE1;s r&#xE1;pido que antes</li>' +
          '<li>M&#xE1;s de 1.200 radios argentinas, todas verificadas</li>' +
        '</ul>' +
        '<div class="rp-welcome-privacy">' +
          '&#x1F512; No te rastreamos ni guardamos tus datos personales. ' +
          'Lo de abajo es an&#xF3;nimo y nos ayuda a mejorar.' +
        '</div>' +
        '<div class="rp-welcome-q">&#xBF;Qu&#xE9; te parece el sitio?</div>' +
        '<div class="rp-welcome-btns" id="_rwq1">' +
          '<button data-r="1">&#x1F44D; Me gusta</button>' +
          '<button data-r="0">&#x1F610; Regular</button>' +
          '<button data-r="-1">&#x1F44E; No me convence</button>' +
        '</div>' +
        '<div class="rp-welcome-q">&#xBF;Desde d&#xF3;nde escuch&#xE1;s?</div>' +
        '<div class="rp-welcome-btns" id="_rwq2">' +
          '<button data-l="casa">&#x1F3E0; Casa</button>' +
          '<button data-l="trabajo">&#x1F4BC; Trabajo</button>' +
          '<button data-l="viaje">&#x1F697; Viajando</button>' +
          '<button data-l="caminando">&#x1F4F1; Caminando</button>' +
        '</div>' +
        '<button class="rp-welcome-cta">&#xA1;Listo, a escuchar! &#x2192;</button>' +
        '<p class="rp-welcome-footer">' +
          'No te volvemos a molestar hasta que tengamos m&#xE1;s novedades para contarte.' +
        '</p>';

      document.body.appendChild(toast);
      requestAnimationFrame(function () { toast.classList.add('rp-welcome--in'); });

      var selRating = null;
      var selLoc    = null;

      toast.querySelectorAll('#_rwq1 [data-r]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          toast.querySelectorAll('#_rwq1 [data-r]').forEach(function (b) { b.classList.remove('rp-sel'); });
          btn.classList.add('rp-sel');
          selRating = parseInt(btn.dataset.r, 10);
        });
      });

      toast.querySelectorAll('#_rwq2 [data-l]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          toast.querySelectorAll('#_rwq2 [data-l]').forEach(function (b) { b.classList.remove('rp-sel'); });
          btn.classList.add('rp-sel');
          selLoc = btn.dataset.l;
        });
      });

      function dismiss() {
        localStorage.setItem(WELCOME_KEY, String(Date.now()));
        toast.classList.remove('rp-welcome--in');
        toast.classList.add('rp-welcome--out');
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 400);
      }

      toast.querySelector('.rp-welcome-close').addEventListener('click', dismiss);
      toast.querySelector('.rp-welcome-cta').addEventListener('click', function () {
        if (selRating !== null) {
          fetch(API_BASE + '/survey', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ slug: '_welcome_v2', rating: selRating, location: selLoc })
          }).catch(function () {});
        }
        dismiss();
      });
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
      getAudio:   function () { return audio; },
      destroy:    function () {
        destroyed = true;
        clearInterval(passiveTimer);
        stop();
      },
    };
  }

  global.RadioPlayer = RadioPlayer;

}(window));
