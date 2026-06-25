/**
 * theme.js — Toggle oscuro/claro compartido entre todas las páginas.
 * Persiste en localStorage clave 'radio_theme'.
 *
 * Uso:
 *   RadioTheme.init(buttonElement);   // aplica tema guardado + enlaza el botón
 *   RadioTheme.toggle();              // cambia manualmente
 */

(function (global) {
  'use strict';

  var KEY = 'radio_theme';

  function isLight() {
    return document.body.classList.contains('light');
  }

  function apply(light, btn) {
    if (light) {
      document.body.classList.add('light');
    } else {
      document.body.classList.remove('light');
    }
    if (btn) btn.textContent = light ? '🌙 Modo oscuro' : '☀️ Modo claro';
  }

  function init(btn) {
    var saved = localStorage.getItem(KEY);
    apply(saved === 'light', btn);

    if (btn) {
      btn.addEventListener('click', function () {
        var light = !isLight();
        apply(light, btn);
        localStorage.setItem(KEY, light ? 'light' : 'dark');
      });
    }
  }

  function toggle() {
    var light = !isLight();
    apply(light, null);
    localStorage.setItem(KEY, light ? 'light' : 'dark');
  }

  global.RadioTheme = { init: init, toggle: toggle };

}(window));
