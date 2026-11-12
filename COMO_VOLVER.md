# Cómo volver a levantar Radio Argentina

Radio Argentina estuvo en línea en `mammoli.ar/radio` hasta el **11 de noviembre de 2026**. Se dio de baja porque se llevaba la mayor parte del espacio y del tráfico de un hosting compartido con otros proyectos, y provocaba bloqueos (429) por exceso de pedidos.

Este archivo cuenta **qué se apagó y cómo se enciende de nuevo**. Nada se perdió: el código está acá y los datos están archivados fuera del repositorio.

## Estado al cerrar

| Pieza | Estado | Cómo volver |
|---|---|---|
| Sitio en `mammoli.ar/radio/` | Reemplazado por un `.htaccess`: 301 a `mammoli.ar/proyectos/radio-argentina/` y 410 para API, M3U, `sw.js` y endpoints | Borrar ese `.htaccess` y subir `web/` (ver más abajo) |
| Base MySQL `mammoli_radio` | Volcada: 21 tablas, 630.785 filas, verificada fila por fila contra el servidor | Crear la base e importar el volcado |
| 7 workflows de GitHub Actions | **Desactivados** (no borrados) | `gh workflow enable <archivo> -R camammoli/radio` |
| 3 cron del hosting (cPanel) | Borrados | Volver a cargarlos (tabla de abajo) |
| Cron de la PC de desarrollo (`trafico_github.py`) | Quitado | `0 8 */3 * *` si se quiere medir el tráfico del repo |
| Monitor de novedades (caídas y oyentes) | Quitado del monitor | Volver a agregar `check_caida` y el chequeo de oyentes |
| Donaciones (Cafecito y Mercado Pago) | **Eliminadas**: la cuenta de Mercado Libre/Mercado Pago se dio de baja; `api/donar.php` y `api/mp_webhook.php` se borraron (siguen en el historial de git) | Sacar el botón «Colaborar» y el toast de ayuda, o conectar otro medio de pago |
| Portfolio de MG Digital, firmas y clics | Trabajo despublicado; sitio quitado de firmas y de herramientas | Volver a publicar desde el panel de MG Digital |
| Gist con el directorio (TSV) | Congelado con la última lista | Reactivar `gist-sync.yml` |

## Datos archivados (repo privado)

Los datos van en el repo **privado** `camammoli/radio-archivo`: tienen información de usuarios y no pueden ir acá. Al cerrar quedó preparado; si todavía no está en GitHub, la copia está en la PC de desarrollo, en `~/Archivo/radio-archivo`.
- `volcado/`: volcado final de MySQL y conteo de filas por tabla;
- `servidor_final/radio/`: lo que había en el servidor (claves tachadas);
- `no_versionado/`: lo que no estaba en git (SQLite local, reportes, scripts v1);
- `documentos/`, `logs/`, `capturas/`, `estado_al_apagar/`;
- `SECRETOS.md`: qué claves usaba y dónde están hoy, sin valores.

## Workflows de GitHub Actions (desactivados)

| Archivo | Horario (UTC) | Qué hacía |
|---|---|---|
| `check-streams-v2.yml` | `0 */6 * * *` | Verificaba todas las señales y avisaba cambios por Telegram |
| `close-orphan-sessions.yml` | `*/15 * * * *` | Cerraba sesiones de oyentes sin cierre |
| `competitor-scan.yml` | `0 11 * * 1` | Comparaba con Radio Browser para encontrar emisoras nuevas |
| `dedupe-streamtheworld.yml` | `30 11 * * 1` | Eliminaba duplicados de StreamTheWorld |
| `enrich-v2.yml` | `0 10 1,15 * *` | Completaba metadatos desde Radio Browser |
| `gist-sync.yml` | `0 12 * * 1` | Exportaba el directorio al gist público |
| `learn-patterns.yml` | `0 7 * * 1` | Aprendía los horarios de los programas |

Secretos que usaban (siguen cargados en el repo, cifrados; con los workflows desactivados y el repo archivado no se usan. Si se borran, volver a cargarlos con `gh secret set`): `CRAWLER_TOKEN`, `FTP_PASS`, `RADIO_ADMIN_KEY`, `TELEGRAM_TOKEN`, `TG_CHAT_ID`, `TG_TOKEN`.

## Cron del servidor (borrados)

```cron
*/21 * * * * php <raíz>/radio/crawlers/icy_refresh.php >> <home>/logs/icy.log 2>&1
*/24 * * * * php <raíz>/radio/crawlers/notify_subscribers.php >> /tmp/radio_notify.log 2>&1
0 4 * * 1    php <raíz>/radio/crawlers/learn_patterns.php >> /tmp/radio_patterns.log 2>&1
```

Ojo: en producción el último estaba como `* 4 * * 1` (60 corridas seguidas los lunes). El correcto es `0 4 * * 1`, y además ya lo hacía `learn-patterns.yml`: elegir uno de los dos.

## Pasos para volver a levantarla

1. **Servidor.** Ideal: uno propio (ver [docs/VPS_PROXIMA_CASA.md](docs/VPS_PROXIMA_CASA.md)). Necesita PHP 8.2 o más nuevo con PDO MySQL, y MySQL o MariaDB.
2. **Base.** Crear la base y su usuario, e importar: `zcat mammoli_radio.sql.gz | mysql -u <usuario> -p <base>`. Comparar los conteos con `conteos.txt`.
3. **Código.** Subir `web/` a la carpeta pública. Copiar `web/config.example.php` como `config.php` y completarlo. En producción además estaban `MYSQL_HOST`, `MYSQL_DB`, `MYSQL_USER`, `MYSQL_PASS` y `RADIO_DB_ENGINE = 'mysql'`.
4. **Sacar el aviso de despedida.** En `web/assets/player.js`, `var DESPEDIDA = true;` → `false`.
5. **Donaciones.** Quitar o reemplazar el botón «Colaborar» y el toast de ayuda (hoy apuntan a medios que ya no existen).
6. **Cron y workflows.** Cargar los cron de arriba; reactivar los workflows necesarios y sus secretos. **Ajustar las frecuencias** a lo que el servidor aguante: la verificación cada 6 horas de más de 1.500 emisoras y el refresco ICY eran lo que más consumía.
7. **Redirecciones.** Borrar el `.htaccess` de despedida de `/radio/` y, si se vuelve al mismo dominio, la ficha de `mammoli.ar` puede quedar como historia.
8. **Monitor.** Volver a agregar la Radio al monitor de novedades y a Uptime Kuma.
