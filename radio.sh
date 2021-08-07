#!/bin/bash
# Este script fue modificado::
# 	* Se cambiaron las url de las emisoras 
# 	* Se corrigieron errores de ejecucion
# 	* Añadida la posibilidad de usar vlc indicandolo como parametro adicional
# 	  (por defecto llama a mplayer)

# Carlos Ariel Mammoli (Chang)
# <cmammoli arroba gmail.com - 12/07/21

# Fuentes
# https://gist.github.com/pisculichi/fae88a2f5570ab22da53 (1)
# https://www.taringa.net/+linux/3-scripts-utiles-para-bash_x5nap (2)
# https://andalinux.wordpress.com/2017/08/01/obtener-el-directorio-de-ejecucion-de-un-script-bash/ (3)

# Obtiene el path desde donde se esta ejecutando el script (3)
SCRIPT=$(readlink -f "$0")
dir_base=$(dirname "$SCRIPT")

# Calcula la cantidad de emisoras que corresponden al criterio de busqueda
cuantos=$(grep -v "#" "$dir_base/emisoras.txt" | grep -n -c -i "$1")

if [[ $cuantos != "1" ]]; then
	# Si no se encontro ninguna emisora (o si por el contrario se encontro mas de 1) 
	
	# Instrucciones de uso 
	instrucciones="
	Uso: radio.sh radio [reproductor]

		radio : Parte del nombre de la estación o la frecuencia de la misma.
		reproductor : Opcional, si se indica V usara cvlc, de lo contrario usara mplayer.
	"

	# Listado de las emisoras que concuerdan
	listadoemisoras="
	Listado de emisoras disponibles ($1)

	$(grep -v "#" "$dir_base/emisoras.txt" | grep -v "://" | grep -i "$1")"

	echo "$instrucciones $listadoemisoras" | less

else 
	# Si se encontro una emisora que concuerda con el criterio
	
	# Obtiene el numero de linea donde esta en el archivo
	linea=$(grep -n -m 1 -i "$1" "$dir_base/emisoras.txt" | cut -d ":" -f1)
	linea=$((linea + 1)) # Le suma 1 para obtener el numero de linea donde esta la url de la emisorra
	radio=$(awk "NR==$linea" "$dir_base/emisoras.txt") # Obtiene la la url

	# Si no se indico nada como segundo parametro asume que el reproductor es mplayer
	if [[ $2 == "" ]]; then

		mplayer -af lavcresample=44100 -cache 64 "$radio"
	fi

	# Si se indico V se usa cvlc
	if [[ $2 == "V" ]]; then

		cvlc "$radio" 2> /dev/null;
	fi
fi