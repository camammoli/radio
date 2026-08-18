/**
 * RadioPlayer — componente de audio unificado para Radio Argentina v4.
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
  var PROXY_URL = '/radio/proxy.php?station=';
  var HB_MS     = 30000;   // heartbeat cada 30s
  var NP_MS     = 30000;   // now-playing poll cada 30s
  var TIMEOUT_MS = 12000;  // timeout de carga de stream
  var STALL_MS      = 15000; // sin avance de reproducción -> asumir corte silencioso
  var MAX_RECONNECT = 6;     // reintentos de reconexión silenciosa antes de mostrar error
  var SURVEY_SECS   = 180;   // 3 minutos para mostrar encuesta de emisora
  var WELCOME_SECS  = 90;    // 90s para mostrar toast de bienvenida (solo info)
  var WELCOME_KEY   = 'radio_welcome_v4';
  var SITE_SURVEY_SECS = 150; // 2:30 — antes de la encuesta de emisora (3min)
  var SITE_SURVEY_KEY  = 'radio_site_survey_v1';
  var AYUDA_DELAY_MS   = 1000;                       // casi inmediato al entrar al sitio
  var AYUDA_SNOOZE_KEY = 'radio_ayuda_snooze_until';  // timestamp (ms) hasta el cual no mostrar
  var AYUDA_NEVER_KEY  = 'radio_ayuda_never';         // '1' = no mostrar nunca más
  var AYUDA_SNOOZE_DIAS = 7;

  function RadioPlayer(opts) {
    // ── Config ──────────────────────────────────────────────────────────────
    var slug   = opts.slug   || '';
    var url    = opts.url    || '';
    var nombre = opts.nombre || '';
    var logo   = opts.logo   || '';
    var source = opts.source || 'web-listing';

    var onState      = opts.onState      || function () {};
    var onNowPlaying = opts.onNowPlaying || function () {};
    var onListeners  = opts.onListeners  || function () {};
    var onError      = opts.onError      || function () {};
    var onNextTrack  = opts.onNextTrack  || null;
    var onPrevTrack  = opts.onPrevTrack  || null;

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
    var siteSurveyTimer = 0;
    var ayudaTimer = 0;
    var destroyed  = false;
    var watchdogTimer     = 0;
    var lastProgress      = 0;
    var reconnectAttempts = 0;

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
      siteSurveyStart();
      setupMediaSession();
      lastProgress = Date.now();
      watchdogStart();
    });

    // Cortes silenciosos (timeout de proxy/hosting en el medio de la reproducción):
    // el navegador no siempre dispara 'error', a veces el stream simplemente deja
    // de avanzar sin avisar. Si no hay progreso real por STALL_MS, reconectar solo.
    audio.addEventListener('timeupdate', function () {
      lastProgress = Date.now();
      reconnectAttempts = 0;
    });

    audio.addEventListener('waiting', function () {
      if (state === 'playing') setState('buffering');
    });

    audio.addEventListener('error', function () {
      if (destroyed) return;
      var code = audio.error ? audio.error.code : 0;

      // code 1=ABORTED, 2=NETWORK — si ya veníamos reproduciendo bien, es el
      // mismo tipo de corte silencioso que cubre el watchdog: reconectar solo
      // en vez de mostrarle un error al usuario por un corte momentáneo.
      var wasPlaying = state === 'playing' || state === 'buffering';
      if (wasPlaying && code !== 3 && code !== 4 && reconnectAttempts < MAX_RECONNECT) {
        reconnectAttempts++;
        audio.src = resolveUrl(url);
        audio.play().catch(function () {});
        lastProgress = Date.now();
        return;
      }

      clearTimeout(loadTimer);
      watchdogStop();
      setState('error');
      lStop(true);
      npStop();
      survStop();
      welcomeStop();
      siteSurveyStop();
      onNowPlaying(null);
      // audio.error.code: 3=DECODE, 4=SRC_NOT_SUPPORTED — el navegador bajó el
      // stream pero no puede decodificarlo (típico con AAC+/HE-AAC en algunos
      // navegadores). Distinto de un problema de red/conexión.
      var msg = (code === 3 || code === 4)
        ? 'tu navegador no puede reproducir este formato — probá abrirla en VLC'
        : 'no disponible en web';
      onError(url, nombre, msg);
    });

    audio.addEventListener('pause', function () {
      if (destroyed) return;
      // El navegador dispara 'pause' (y luego 'ended') cuando la conexión se
      // cierra "limpio" del lado del servidor — lo interpreta como fin de
      // stream, aunque una radio en vivo nunca "termina" de verdad. Es el
      // mismo corte silencioso que cubren watchdogCheck y el handler de
      // 'error': si veníamos reproduciendo bien, reconectar antes de darlo
      // por perdido. Sin esto, el estado pasaba a 'stopped' antes de que el
      // watchdog (que corre cada 5s) llegara siquiera a notar el corte.
      if (state === 'playing' || state === 'buffering') {
        if (reconnectAttempts < MAX_RECONNECT) {
          reconnectAttempts++;
          audio.src = resolveUrl(url);
          audio.play().catch(function () {});
          lastProgress = Date.now();
          return;
        }
        watchdogStop();
        setState('error');
        onError(url, nombre, 'se perdió la conexión');
        return;
      }
      // 'pause' también se dispara por nuestro propio stop() (audio.src='')
      // y no debe pisar el estado 'connecting' de una emisora recién elegida.
      if (state !== 'idle' && state !== 'stopped' && state !== 'connecting') {
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

    // ── Media Session API (hotkeys Bluetooth / teclado) ───────────────────────
    function updateMediaMeta() {
      if (!('mediaSession' in navigator)) return;
      try {
        navigator.mediaSession.metadata = new MediaMetadata({
          title:   nombre,
          artist:  'Radio Argentina',
          artwork: logo ? [{ src: logo, sizes: '128x128', type: 'image/jpeg' }] : [],
        });
      } catch (e) {}
    }

    function setupMediaSession() {
      if (!('mediaSession' in navigator)) return;
      updateMediaMeta();
      var actions = {
        'play':          function () { if (state !== 'playing') play(); },
        'pause':         function () { stop(); },
        'stop':          function () { stop(); },
        'nexttrack':     onNextTrack,
        'previoustrack': onPrevTrack,
      };
      Object.keys(actions).forEach(function (action) {
        try { navigator.mediaSession.setActionHandler(action, actions[action]); } catch (e) {}
      });
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
      // El proxy busca la URL real server-side a partir del slug (nunca
      // recibe una URL del cliente, para no ser un proxy HTTP abierto).
      if (/\.pls(\?|$)/i.test(raw)) return PROXY_URL + encodeURIComponent(slug);
      if (/\.m3u(\?|$)/i.test(raw) && !/\.m3u8(\?|$)/i.test(raw))
        return PROXY_URL + encodeURIComponent(slug);
      // Streams HTTP desde página HTTPS: usar proxy (no upgrade directo, los certs suelen fallar)
      if (location.protocol === 'https:' && raw.indexOf('http://') === 0)
        return PROXY_URL + encodeURIComponent(slug);
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
      watchdogStop();
      if (hlsInst) { hlsInst.destroy(); hlsInst = null; }
      audio.pause();
      audio.src = '';
      setState('idle');
      lStop(true);
      npStop();
      survStop();
      welcomeStop();
      siteSurveyStop();
      onNowPlaying(null);
    }

    // ── Watchdog de cortes silenciosos ──────────────────────────────────────────
    function watchdogStart() {
      reconnectAttempts = 0;
      clearInterval(watchdogTimer);
      watchdogTimer = setInterval(watchdogCheck, 5000);
    }

    function watchdogStop() {
      clearInterval(watchdogTimer);
      watchdogTimer = 0;
    }

    function watchdogCheck() {
      if (destroyed || state !== 'playing') return;
      if (Date.now() - lastProgress < STALL_MS) return;

      if (reconnectAttempts >= MAX_RECONNECT) {
        watchdogStop();
        setState('error');
        onError(url, nombre, 'se perdió la conexión');
        return;
      }
      reconnectAttempts++;
      // Reconexión silenciosa: no pasar por setState('error'), el usuario no
      // necesita enterarse de que hubo un corte si se resuelve solo.
      audio.pause();
      audio.src = resolveUrl(url);
      audio.play().catch(function () {});
      lastProgress = Date.now();
    }

    function toggle() {
      if (state === 'idle' || state === 'stopped' || state === 'error') {
        play();
      } else {
        stop();
      }
    }

    // Cambiar emisora sin recargar la página (para el listado) y arrancar reproducción
    function setStation(newSlug, newUrl, newNombre, newLogo) {
      clearTimeout(loadTimer);
      watchdogStop();
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
      logo      = newLogo || '';
      survSecs  = 0;
      survShown = false;

      updateMediaMeta();
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

    // ── Toast bienvenida v4 — solo novedades, sin encuesta ni pedido de café ──
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
        '<h3>&#x1F3B5; &#xA1;Nueva versi&#xF3;n (v4)!</h3>' +
        '<ul>' +
          '<li>Mostramos qu&#xE9; canci&#xF3;n suena en cada radio</li>' +
          '<li>Player con control de volumen</li>' +
          '<li>Avisos por Telegram o email cuando suene tu artista favorito</li>' +
          '<li>Arreglamos cortes de audio en varias emisoras</li>' +
          '<li>M&#xE1;s de 1.200 emisoras argentinas verificadas</li>' +
        '</ul>' +
        '<div class="rp-welcome-privacy">' +
          '&#x1F512; Sin rastreo, sin datos personales guardados.' +
        '</div>' +
        '<p class="rp-welcome-footer">' +
          'Este proyecto lo hago solo, gratis y sin publicidad. Si te sirve, ' +
          'me vas a ver pedirte una mano m&#xE1;s adelante &#x2014; cualquier ' +
          'colaboraci&#xF3;n ayuda a mantenerlo online.' +
        '</p>' +
        '<button class="rp-welcome-cta">&#xA1;Listo, a escuchar! &#x2192;</button>';

      document.body.appendChild(toast);
      requestAnimationFrame(function () { toast.classList.add('rp-welcome--in'); });

      function dismiss() {
        localStorage.setItem(WELCOME_KEY, String(Date.now()));
        toast.classList.remove('rp-welcome--in');
        toast.classList.add('rp-welcome--out');
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 400);
      }

      toast.querySelector('.rp-welcome-close').addEventListener('click', dismiss);
      toast.querySelector('.rp-welcome-cta').addEventListener('click', dismiss);
    }

    // ── Pedido de ayuda para sostener el proyecto — aparece ni bien se entra
    // al sitio (no depende de reproducir), en cualquier página. A diferencia
    // de bienvenida/encuesta, NO es "una sola vez para siempre": si el
    // visitante no toca ningún botón de acción, vuelve a aparecer en la
    // próxima entrada. Solo se pausa si responde algo:
    //   OK / Contacto     → pausa AYUDA_SNOOZE_DIAS días
    //   Cafecito / No molestar → no vuelve a aparecer nunca más
    function ayudaInit() {
      if (ayudaSuprimido()) return;
      ayudaTimer = setTimeout(showAyuda, AYUDA_DELAY_MS);
    }

    function ayudaCancel() {
      clearTimeout(ayudaTimer); ayudaTimer = 0;
    }

    function ayudaSuprimido() {
      if (localStorage.getItem(AYUDA_NEVER_KEY)) return true;
      var until = parseInt(localStorage.getItem(AYUDA_SNOOZE_KEY) || '0', 10);
      return until > Date.now();
    }

    function ayudaLog(tipo) {
      try {
        fetch('/radio/api/ayuda_toast.php?tipo=' + encodeURIComponent(tipo), { keepalive: true }).catch(function () {});
      } catch (e) {}
    }

    function showAyuda() {
      if (ayudaSuprimido()) return;

      // Si el toast de bienvenida o el de encuesta de sitio siguen abiertos
      // (el visitante no los cerró), no superponer otro toast en el mismo
      // lugar de la pantalla — reintentar en 10s en vez de amontonarlos.
      if (document.querySelector('.rp-welcome')) {
        ayudaTimer = setTimeout(showAyuda, 10000);
        return;
      }

      var toast = document.createElement('div');
      toast.className = 'rp-welcome rp-welcome--ayuda';
      toast.innerHTML =
        '<button class="rp-welcome-close" aria-label="Cerrar">&#x2715;</button>' +
        '<h3>&#x1F64F; Un pedido</h3>' +
        '<p>Radio Argentina es un proyecto personal. Lo arm&#xE9; y lo mantengo solo, ' +
        'en mi tiempo libre &#x2014; no hay una empresa ni un equipo atr&#xE1;s, soy una ' +
        'persona a la que le gusta la radio y quiso que estas +1200 emisoras argentinas ' +
        'est&#xE9;n disponibles gratis, sin cuentas y sin publicidad invasiva.</p>' +
        '<p>Sostenerlo online tiene un costo real. Este sitio no es una p&#xE1;gina est&#xE1;tica: ' +
        'monitorea en tiempo real el estado de m&#xE1;s de 1200 streams, sincroniza metadata (ICY) ' +
        'para mostrarte qu&#xE9; est&#xE1; sonando en cada radio, y sostiene un sistema de alertas ' +
        'que escucha esas mismas 1200 emisoras buscando tus artistas o programas favoritos. Todo eso ' +
        'consume ancho de banda de verdad. De hecho, tuve que contratar un plan de hosting m&#xE1;s ' +
        'potente y con m&#xE1;s ancho de banda porque el anterior no daba abasto &#x2014; el consumo ' +
        'se dispar&#xF3; y me dej&#xF3; al l&#xED;mite.</p>' +
        '<p>Tengo un cafecito hace un tiempo, pero honestamente no alcanza para cubrir lo que cuesta ' +
        'sostener esto mes a mes.</p>' +
        '<p>Si 1 de cada 10 personas que escuchan la radio en un mes aportara un cafecito alguna vez ' +
        '(no todos los meses, solo cuando puedan) ya cubrir&#xED;amos bastante los gastos que genera ' +
        'el sitio. La audiencia todav&#xED;a es chica pero viene creciendo fuerte mes a mes &#x2014; ' +
        'cuanta m&#xE1;s gente escuche, menos le toca aportar a cada uno para que esto siga en pie.</p>' +
        '<p>Si te gusta el proyecto, si lo us&#xE1;s, si te parece que vale la pena que siga gratis y ' +
        'sin publicidad invasiva &#x2014; necesito una mano. No hace falta una fortuna: cualquier ' +
        'aporte, por chico que sea, ayuda a pagar el hosting y a que este proyecto siga en pie.</p>' +
        '<p>Y no es solo una cuesti&#xF3;n de plata. Si la comunidad de oyentes nos diera una mano ' +
        '&#x2014;contando qu&#xE9; falla, avisando cuando una radio se cae, sugiriendo emisoras que ' +
        'faltan, corriendo la voz&#x2014; el servicio mejorar&#xED;a much&#xED;simo m&#xE1;s r&#xE1;pido ' +
        'de lo que puedo hacerlo yo solo en mi tiempo libre. Hay proyectos de radio online mucho m&#xE1;s ' +
        'grandes (apps con miles de usuarios, directorios internacionales) que tienen cosas que a este ' +
        'todav&#xED;a le faltan: notificaci&#xF3;n autom&#xE1;tica cuando tu emisora favorita vuelve ' +
        'despu&#xE9;s de una ca&#xED;da, recomendaciones seg&#xFA;n lo que escuch&#xE1;s, guardado de ' +
        'favoritos sincronizado entre tus dispositivos, integraci&#xF3;n con podcasts, mejores filtros ' +
        'de b&#xFA;squeda por g&#xE9;nero y provincia. Ninguna de esas cosas es imposible ac&#xE1; ' +
        '&#x2014; lo que falta es tiempo, y el tiempo se estira mucho m&#xE1;s cuando no lo tengo que ' +
        'pelear solo contra el hosting cada mes.</p>' +
        '<p>Si nadie ayuda a sostenerlo, lamentablemente en alg&#xFA;n momento voy a tener que darlo ' +
        'de baja. No quiero llegar a eso &#x2014; le dedico tiempo real y lo hago con ganas &#x2014; ' +
        'pero tampoco puedo bancarlo solo indefinidamente.</p>' +
        '<p>&#x2615; <a href="https://cafecito.app/mammoli" target="_blank" rel="noreferrer">Invitame un cafecito</a></p>' +
        '<p>&#x1F4BB; <a href="https://github.com/camammoli/radio" target="_blank" rel="noreferrer">El proyecto es open source, lo pod&#xE9;s ver ac&#xE1;</a></p>' +
        '<p>Gracias por escuchar, por sugerir emisoras, por avisarme cuando algo se cae. Este proyecto ' +
        'existe gracias a la gente que lo usa &#x2014; ayudame a que siga as&#xED;.</p>' +
        '<div class="rp-ayuda-actions">' +
          '<button class="rp-welcome-cta rp-ayuda-ok">&#x1F44D; OK</button>' +
          '<a class="rp-welcome-cta rp-ayuda-cafecito" href="https://cafecito.app/mammoli" target="_blank" rel="noreferrer">&#x2615; Cafecito</a>' +
          '<a class="rp-welcome-cta rp-ayuda-contacto" href="contacto.php" target="_blank" rel="noreferrer">&#x1F4EC; Contacto</a>' +
          '<button class="rp-welcome-cta rp-ayuda-nomolestar">&#x1F6AB; No molestar m&#xE1;s</button>' +
        '</div>';

      document.body.appendChild(toast);
      requestAnimationFrame(function () { toast.classList.add('rp-welcome--in'); });
      ayudaLog('mostrado');

      function close() {
        toast.classList.remove('rp-welcome--in');
        toast.classList.add('rp-welcome--out');
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 400);
      }

      function snooze() {
        localStorage.setItem(AYUDA_SNOOZE_KEY, String(Date.now() + AYUDA_SNOOZE_DIAS * 24 * 60 * 60 * 1000));
      }

      function never() {
        localStorage.setItem(AYUDA_NEVER_KEY, '1');
      }

      // La X cierra sin fijar preferencia — vuelve a aparecer en la próxima
      // entrada, a diferencia de los 4 botones de abajo que sí la cambian.
      toast.querySelector('.rp-welcome-close').addEventListener('click', close);

      toast.querySelector('.rp-ayuda-ok').addEventListener('click', function () {
        ayudaLog('ok'); snooze(); close();
      });
      toast.querySelector('.rp-ayuda-nomolestar').addEventListener('click', function () {
        ayudaLog('no_molestar'); never(); close();
      });
      // Cafecito y Contacto abren en pestaña nueva (comportamiento nativo del
      // <a>, no se previene) y además fijan la preferencia correspondiente.
      toast.querySelector('.rp-ayuda-cafecito').addEventListener('click', function () {
        ayudaLog('cafecito'); never(); close();
      });
      toast.querySelector('.rp-ayuda-contacto').addEventListener('click', function () {
        ayudaLog('contacto'); snooze(); close();
      });
    }

    // ── Encuesta de sitio (opinión + ubicación) — separada de la bienvenida,
    // aparece un poco después para que el visitante ya haya escuchado algo
    // antes de opinar. Una sola vez por visitante. ────────────────────────────
    function siteSurveyStart() {
      if (localStorage.getItem(SITE_SURVEY_KEY)) return;
      clearTimeout(siteSurveyTimer);
      siteSurveyTimer = setTimeout(showSiteSurvey, SITE_SURVEY_SECS * 1000);
    }

    function siteSurveyStop() {
      clearTimeout(siteSurveyTimer); siteSurveyTimer = 0;
    }

    function showSiteSurvey() {
      if (localStorage.getItem(SITE_SURVEY_KEY)) return;

      var toast = document.createElement('div');
      toast.className = 'rp-welcome';
      toast.innerHTML =
        '<button class="rp-welcome-close" aria-label="Cerrar">&#x2715;</button>' +
        '<div class="rp-welcome-q">&#xBF;Qu&#xE9; te parece el sitio?</div>' +
        '<div class="rp-welcome-btns" id="_rsq1">' +
          '<button data-r="1">&#x1F44D; Me gusta</button>' +
          '<button data-r="0">&#x1F610; Regular</button>' +
          '<button data-r="-1">&#x1F44E; No me convence</button>' +
        '</div>' +
        '<div class="rp-welcome-q">&#xBF;Desde d&#xF3;nde escuch&#xE1;s?</div>' +
        '<div class="rp-welcome-btns" id="_rsq2">' +
          '<button data-l="casa">&#x1F3E0; Casa</button>' +
          '<button data-l="trabajo">&#x1F4BC; Trabajo</button>' +
          '<button data-l="viaje">&#x1F697; Viajando</button>' +
          '<button data-l="caminando">&#x1F4F1; Caminando</button>' +
        '</div>' +
        '<button class="rp-welcome-cta">Listo &#x2192;</button>';

      document.body.appendChild(toast);
      requestAnimationFrame(function () { toast.classList.add('rp-welcome--in'); });

      var selRating = null;
      var selLoc    = null;

      toast.querySelectorAll('#_rsq1 [data-r]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          toast.querySelectorAll('#_rsq1 [data-r]').forEach(function (b) { b.classList.remove('rp-sel'); });
          btn.classList.add('rp-sel');
          selRating = parseInt(btn.dataset.r, 10);
        });
      });

      toast.querySelectorAll('#_rsq2 [data-l]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          toast.querySelectorAll('#_rsq2 [data-l]').forEach(function (b) { b.classList.remove('rp-sel'); });
          btn.classList.add('rp-sel');
          selLoc = btn.dataset.l;
        });
      });

      function dismiss() {
        localStorage.setItem(SITE_SURVEY_KEY, String(Date.now()));
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
            body: JSON.stringify({ slug: '_site_v4', rating: selRating, location: selLoc })
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
      ayudaCancel();
      document.removeEventListener('visibilitychange', arguments.callee);
    }

    // Poll pasivo inicial (ver si otros escuchan aunque vos no estés)
    pollPassive();
    var passiveTimer = setInterval(function () {
      if (state !== 'playing' && state !== 'buffering') pollPassive();
    }, HB_MS);

    // Pedido de ayuda: ni bien se entra al sitio, independiente de si se
    // reproduce algo o no.
    ayudaInit();

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
