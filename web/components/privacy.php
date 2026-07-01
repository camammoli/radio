<?php /* Componente: modal de privacidad + enlace en pie de página. Incluir antes de </body>. */ ?>

<!-- ── Pie de página ──────────────────────────────────────────────────────────── -->
<footer style="text-align:center;padding:28px 16px 20px;font-size:12px;color:var(--muted,#94a3b8)">
  Radio Argentina · mammoli.ar &nbsp;·&nbsp;
  <a href="#" id="privacy-link"
     style="color:var(--muted,#94a3b8);text-decoration:none;border-bottom:1px dotted currentColor"
     onclick="document.getElementById('privacy-modal').classList.add('open');return false">
    Privacidad y uso legal
  </a>
</footer>

<!-- ── Modal / toast de privacidad ───────────────────────────────────────────── -->
<div id="privacy-modal" role="dialog" aria-modal="true" aria-label="Política de privacidad"
     style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);backdrop-filter:blur(3px);align-items:flex-end;justify-content:center">
  <div id="privacy-sheet"
       style="background:#ffffff;color:#1e293b;border:1px solid #e2e8f0;
              border-radius:18px 18px 0 0;width:100%;max-width:720px;max-height:82vh;
              display:flex;flex-direction:column;box-shadow:0 -8px 40px rgba(0,0,0,.25)">

    <!-- Handle + header -->
    <div style="padding:16px 24px 12px;border-bottom:1px solid #e2e8f0;flex-shrink:0;background:#fff;border-radius:18px 18px 0 0">
      <div style="width:36px;height:4px;background:#cbd5e1;border-radius:2px;margin:0 auto 14px"></div>
      <div style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:15px;font-weight:700;color:#1e293b">Privacidad y uso legal</span>
        <button id="privacy-close"
                style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:20px;line-height:1;padding:2px 6px"
                aria-label="Cerrar">✕</button>
      </div>
    </div>

    <!-- Contenido scrolleable -->
    <div style="overflow-y:auto;padding:20px 24px 32px;font-size:13px;line-height:1.75;color:#334155;background:#fff;border-radius:0 0 0 0">

      <p style="color:#94a3b8;font-size:11px;margin-bottom:18px">
        Última actualización: <?= date('d \d\e F \d\e Y') ?> — Aplicable a mammoli.ar/radio
      </p>

      <h3 style="font-size:13px;font-weight:700;margin:0 0 6px;color:#2563eb">1. Quiénes somos</h3>
      <p style="margin:0 0 16px">
        Radio Argentina (mammoli.ar/radio) es un directorio y reproductor web de emisoras argentinas de acceso público y gratuito,
        operado de forma independiente. No somos una empresa, no tenemos fines de lucro ni comerciales directos.
        El servicio es desarrollado y mantenido de manera personal. El código fuente está publicado en
        <a href="https://github.com/camammoli/radio" target="_blank" rel="noopener" style="color:#2563eb">GitHub</a>
        y puede ser revisado por cualquier persona.
      </p>

      <h3 style="font-size:13px;font-weight:700;margin:0 0 6px;color:#2563eb">2. Qué información registramos</h3>
      <p style="margin:0 0 8px">
        Con el único propósito de generar estadísticas de uso anónimas y mejorar el servicio, registramos:
      </p>
      <ul style="margin:0 0 16px;padding-left:18px">
        <li style="margin-bottom:6px"><strong>Reproducción de emisoras:</strong> qué emisora se escuchó y por cuánto tiempo, sin identificar al usuario.</li>
        <li style="margin-bottom:6px"><strong>Identificador de sesión efímero:</strong> una cadena aleatoria generada en cada visita, no persistente entre sesiones.</li>
        <li style="margin-bottom:6px"><strong>Hash anónimo de dirección IP:</strong> la dirección IP del dispositivo se transforma mediante un hash criptográfico (SHA-256) antes de cualquier almacenamiento, de modo que <em>no es posible recuperar la IP original</em> a partir del dato guardado. El dato en claro no se registra en ningún soporte.</li>
        <li style="margin-bottom:6px"><strong>Origen de la sesión:</strong> si la reproducción provino del directorio, de una página de emisora o de un enlace externo.</li>
        <li style="margin-bottom:6px"><strong>Encuestas voluntarias:</strong> si el usuario responde las preguntas opcionales (valoración del sitio, ubicación aproximada), esas respuestas se guardan también de forma anónima.</li>
      </ul>

      <h3 style="font-size:13px;font-weight:700;margin:0 0 6px;color:#2563eb">3. Qué información NO registramos</h3>
      <ul style="margin:0 0 16px;padding-left:18px">
        <li style="margin-bottom:6px">No utilizamos herramientas de analítica de terceros ni cookies de seguimiento de ningún tipo.</li>
        <li style="margin-bottom:6px">No almacenamos nombre, correo electrónico, teléfono, documento ni ningún otro dato personal identificable.</li>
        <li style="margin-bottom:6px">No creamos perfiles de usuario ni cruzamos información entre sesiones.</li>
        <li style="margin-bottom:6px">No utilizamos tecnologías de fingerprinting del dispositivo o navegador.</li>
        <li style="margin-bottom:6px">No accedemos al micrófono, cámara, contactos ni ningún recurso del dispositivo más allá de la reproducción de audio.</li>
        <li style="margin-bottom:6px">El Service Worker que permite el funcionamiento offline utiliza exclusivamente caché local del propio navegador; no envía datos a ningún servidor externo.</li>
      </ul>

      <h3 style="font-size:13px;font-weight:700;margin:0 0 6px;color:#2563eb">4. Uso y cesión de datos</h3>
      <p style="margin:0 0 16px">
        Los datos estadísticos anónimos son utilizados exclusivamente para conocer el funcionamiento del sitio
        (emisoras más escuchadas, horarios de mayor actividad, etc.).
        <strong>No vendemos, cedemos, alquilamos ni compartimos ningún dato con terceros</strong>,
        con fines comerciales ni de ningún otro tipo.
      </p>

      <h3 style="font-size:13px;font-weight:700;margin:0 0 6px;color:#2563eb">5. Retención y eliminación</h3>
      <p style="margin:0 0 16px">
        Los registros estadísticos anónimos se conservan por un período razonable para fines operativos y pueden ser
        eliminados periódicamente. Al no existir dato personal alguno, no aplica el derecho de acceso, rectificación
        o supresión en los términos del artículo 14 de la Ley 25.326; no obstante, ante cualquier consulta podés
        contactarnos en <a href="mailto:radio@mammoli.ar" style="color:#2563eb">radio@mammoli.ar</a>.
      </p>

      <h3 style="font-size:13px;font-weight:700;margin:0 0 6px;color:#2563eb">6. Legalidad de las transmisiones</h3>
      <p style="margin:0 0 8px">
        Las URLs de los streams de audio que integran este directorio son <strong>públicas y de libre acceso</strong>:
      </p>
      <ul style="margin:0 0 16px;padding-left:18px">
        <li style="margin-bottom:6px">Las emisoras son estaciones de radio legalmente habilitadas que transmiten su señal por internet de forma abierta, sin restricción de acceso ni autenticación.</li>
        <li style="margin-bottom:6px">Las URLs provienen de directorios públicos como <a href="https://www.radio-browser.info" target="_blank" rel="noopener" style="color:#2563eb">Radio Browser</a> y de sugerencias voluntarias de los propios oyentes.</li>
        <li style="margin-bottom:6px">No almacenamos, copiamos, redistribuimos ni modificamos el contenido de audio de ninguna emisora. El reproductor actúa únicamente como cliente HTTP estándar del stream original, de la misma manera que cualquier navegador o reproductor multimedia.</li>
        <li style="margin-bottom:6px">Los metadatos ICY (título de la canción en curso) son datos difundidos públicamente por la propia emisora en cada transmisión y son accesibles por cualquier cliente de audio sin autenticación.</li>
        <li style="margin-bottom:6px">No realizamos ninguna actividad de scraping ilegal, acceso no autorizado a sistemas, descarga o copia de contenido protegido.</li>
      </ul>
      <p style="margin:0 0 16px">
        La responsabilidad sobre los contenidos transmitidos corresponde en exclusiva a cada emisora.
        Si sos titular de una emisora y preferís no aparecer en el directorio, podés solicitarlo escribiéndonos a
        <a href="mailto:radio@mammoli.ar" style="color:#2563eb">radio@mammoli.ar</a>.
      </p>

      <h3 style="font-size:13px;font-weight:700;margin:0 0 6px;color:#2563eb">7. Marco legal aplicable</h3>
      <p style="margin:0 0 8px">Este sitio opera en el marco de la legislación argentina vigente, en particular:</p>
      <ul style="margin:0 0 16px;padding-left:18px">
        <li style="margin-bottom:6px"><strong>Ley 25.326</strong> — Protección de los Datos Personales (Argentina).</li>
        <li style="margin-bottom:6px"><strong>Ley 25.690</strong> — Proveedores de acceso a Internet.</li>
        <li style="margin-bottom:6px"><strong>Ley 11.723</strong> — Régimen Legal de la Propiedad Intelectual, respetando los derechos de los titulares de contenido.</li>
        <li style="margin-bottom:6px"><strong>Ley 27.275</strong> — Derecho de Acceso a la Información Pública (en tanto aplica al principio de transparencia).</li>
      </ul>

      <h3 style="font-size:13px;font-weight:700;margin:0 0 6px;color:#2563eb">8. Seguridad</h3>
      <p style="margin:0 0 16px">
        Los datos son almacenados con medidas de seguridad razonables. El sitio está disponible mediante HTTPS
        con cifrado TLS; se recomienda siempre acceder desde una conexión segura. Los identificadores anónimos
        son generados con algoritmos criptográficos estándar (SHA-256).
      </p>

      <h3 style="font-size:13px;font-weight:700;margin:0 0 6px;color:#2563eb">9. Cambios a esta política</h3>
      <p style="margin:0 0 16px">
        Cualquier modificación relevante a esta política será reflejada en esta misma página con la fecha de
        actualización. El uso continuado del sitio implica la aceptación de los términos vigentes.
      </p>

      <p style="margin:0;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:14px">
        Radio Argentina · mammoli.ar/radio · Desarrollado de forma independiente en Argentina ·
        <a href="https://github.com/camammoli/radio" target="_blank" rel="noopener" style="color:#94a3b8">Código en GitHub</a>
      </p>

    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('privacy-modal');
  var sheet = document.getElementById('privacy-sheet');

  function openModal() {
    modal.style.display = 'flex';
    sheet.style.transform = 'translateY(100%)';
    sheet.style.transition = 'none';
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        sheet.style.transition = 'transform .3s cubic-bezier(.4,0,.2,1)';
        sheet.style.transform  = 'translateY(0)';
      });
    });
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    sheet.style.transition = 'transform .3s cubic-bezier(.4,0,.2,1)';
    sheet.style.transform  = 'translateY(100%)';
    setTimeout(function () {
      modal.style.display = 'none';
      document.body.style.overflow = '';
    }, 300);
  }

  document.getElementById('privacy-close').addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
  document.getElementById('privacy-link').addEventListener('click', openModal);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') closeModal();
  });
}());
</script>
