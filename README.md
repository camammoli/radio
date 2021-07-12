# radio
Escuchar la radio desde bash
Se trata de un script muy simple para escuchar la radio mientras trabajo desde la consola, las URL de las radios estan en el archivo emisoras.txt
El uso es mas simple aun: ./radio.sh sin parametros lista las emisoras disponibles, si se pasa como parametro cualquier parte del nombre se escucha dicha
emisora usando mplayer
Si se pasa como segundo parametro V se usa cvlc en lugar de mplayer
El formato del archivo de emisoras es muy sencillo tambien: cada linea contiene la url de la emisora, separada por espacios y entre comillas el nombre de la misma
Las lineas con emisoras comentadas no son tenidas en cuenta

Recomiendo crear un alias con el siguiente contenido para poder usar el script sin tener que invocar todo el path:

alias radio='/home/USUARIO/scripts/radio/radio.sh'
