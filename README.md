# radio

Escuchar 600+ radios argentinas desde la terminal, o desde el navegador sin instalar nada.

**🌐 Player web:** [mammoli.ar/radio](https://mammoli.ar/radio/) — funciona en el celular, buscador en tiempo real, descarga M3U para VLC/apps IPTV.

## Uso — Terminal

```bash
# Buscar y reproducir (mplayer por default)
./radio.sh mendoza
./radio.sh "104.1"
./radio.sh "vorterix"

# Elegir reproductor: m=mplayer (default), v=cvlc, p=mpv
./radio.sh mendoza p     # usar mpv
./radio.sh rock v        # usar VLC

# Sin parámetros: listar primeras emisoras
./radio.sh

# Alias recomendado (agregar al ~/.bashrc o ~/.zshrc)
alias radio='/ruta/completa/radio.sh'
```

## Requisitos

Al menos uno de estos reproductores instalado:
- `mplayer` — `sudo apt install mplayer`
- `vlc` — `sudo apt install vlc`
- `mpv` — `sudo apt install mpv`

## Formato de emisoras.txt

```
# Líneas con # al inicio son ignoradas (emisora deshabilitada)
Nombre FM 104.1 * Mendoza, Argentina
https://streaming.url/stream

Rock & Pop FM 95.9 * Buenos Aires
https://otra.url/radio.mp3
```

## Verificar URLs activas

El archivo `emisoras.txt` puede tener URLs desactualizadas. Para saber cuáles siguen funcionando:

```bash
chmod +x verificar_urls.sh
./verificar_urls.sh                          # muestra resultado en pantalla
./verificar_urls.sh --output resultado.txt   # guarda el resultado
```

## Cobertura

- **Mendoza:** +30 emisoras (AM y FM)
- **Buenos Aires:** principales emisoras nacionales
- **Argentina:** +100 ciudades y provincias
- **Total:** ~657 emisoras (verificar con `verificar_urls.sh` para estado actual)

## Licencia

CC BY-SA 4.0 — Carlos Ariel Mammoli (LU2MCA), Mendoza, Argentina
