<?php
/**
 * volver_arriba_button.php — botón flotante "volver arriba".
 * Mismo lado (izquierda) y sistema de offsets que telegram_button.php /
 * whatsapp_button.php, apilado arriba de los dos — ver el comentario en
 * .rp-tg-btn (player.css) sobre por qué el lado izquierdo evita chocar con
 * el toast de encuesta y el de bienvenida, que viven a la derecha.
 * Solo se incluye en listing.php (la página con la lista larga de
 * emisoras) — no en station.php, que es una sola ficha corta.
 */
?>
<button type="button" id="rp-top-btn" class="rp-top-btn" aria-label="Volver arriba">
  <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
    <path fill="#fff" d="M12 4l-8 8h5v8h6v-8h5z" />
  </svg>
</button>
