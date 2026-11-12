# Idea: un VPS como próxima casa de Radio Argentina

*Anotado el 11/11/2026, al cerrar. Recordatorio para revisarlo: 11/03/2027.*

## Por qué no funcionó en el hosting compartido

- **Espacio:** la base MySQL llegó a 110 MB, la más grande de la cuenta (807 MB usados de 1.300), sobre todo por el historial de chequeos de señal (`stream_history`, más de 560.000 filas).
- **Tráfico y bloqueos:** verificadores, refresco ICY, reproductores, la lista M3U y la app instalada pedían todo el tiempo. El firewall del hosting bloqueaba con 429 la IP de la casa entera, y en octubre se agotó el ancho de banda del mes (509).
- **Sin control del servidor:** no había forma de usar colas, workers ni caché en memoria, y los procesos largos dependían de cron cada pocos minutos o de GitHub Actions bajando y subiendo la base por FTP.

## Qué cambiaría con un VPS

- Proceso propio para verificar señales y refrescar ICY, sin cron cada 15–20 minutos.
- MySQL/MariaDB local con historial rotado (por ejemplo, 30 días de `stream_history` + resúmenes diarios).
- Caché (Redis o archivos) para la API y la lista M3U; Cloudflare adelante para lo estático.
- Ningún bloqueo que afecte a los otros sitios: la Radio queda aislada.
- GitHub Actions solo para lo que no toca la base de producción.

## Preguntas para decidir

1. ¿Costo mensual aceptable? Un VPS chico (1–2 vCPU, 1–2 GB de RAM, 20–40 GB de disco) alcanza para empezar.
2. ¿Quién lo mantiene? Actualizaciones del sistema, copias de seguridad y monitoreo pasan a ser trabajo nuestro.
3. ¿Cómo se sostiene? Las donaciones no alcanzaron. Opciones: patrocinio de emisoras, un plan pago para la API o las listas, o ninguna (proyecto personal).
4. ¿Mismo dominio (`mammoli.ar/radio`) o uno propio?
5. ¿Vale la pena? Último dato real: unas 270 reproducciones y entre 40 y 50 oyentes distintos por día (septiembre de 2026).
