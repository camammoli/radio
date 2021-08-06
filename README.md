# radio
Escuchar la radio desde bash.

Se trata de un script muy simple para escuchar la radio mientras trabajo desde la consola, las URL de las radios estan en el archivo emisoras.txt

El uso es mas simple aun: ./radio.sh sin parametros lista las emisoras disponibles, si se pasa como parametro cualquier parte del nombre se escucha dicha emisora usando mplayer.

Si se pasa como segundo parametro V se usa cvlc en lugar de mplayer.

El formato del archivo de emisoras es muy sencillo tambien: una linea que contiene el nombre de la emisora con el formato:

[#] NOMBRE EMISORA [BANDA MHz] [* PRVINCIA, PAIS]

[#] 				Opcional, si existe antes del nombre de la emisora indica que la misma esta deshabilitada
					y no se tendra en cuenta en el script

NOMBRE EMISORA 		Indica el nombre de la emisora

[BANDA MHz]			Opcional, si esta disponible indica la banda de frecuenta (AM / FM) y la frecuencia en MHz

[* PRVINCIA, PAIS]	Opcional, un * para separar seguido de un espacio y la provincia y pais de la emisora

En la linea siguiente se encuentra la url de la emisora


Recomiendo crear un alias con el siguiente contenido para poder usar el script sin tener que invocar todo el path:

alias radio='/home/USUARIO/scripts/radio/radio.sh'
