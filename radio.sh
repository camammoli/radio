#!/bin/bash
# Este script fue modificado::
# 	* Se cambiaron las url de las emisoras 
# 	* Se corrigieron errores de ejecucion
# 	* Añadida la posibilidad de usar vlc indicandolo como parametro adicional
# 	  (por defecto llama a mplayer)

# Carlos Ariel Mammoli (Chang)
# <cmammoli arroba gmail.com - 10072021

# Fuentes
# https://gist.github.com/pisculichi/fae88a2f5570ab22da53
# https://www.taringa.net/+linux/3-scripts-utiles-para-bash_x5nap

# Truco para obtener el path donde esta el script (https://andalinux.wordpress.com/2017/08/01/obtener-el-directorio-de-ejecucion-de-un-script-bash/)
SCRIPT=$(readlink -f $0)
dir_base=`dirname $SCRIPT`

radio=`grep -v "#" $dir_base/emisoras.txt | grep -m 1 -i $1 | cut -d " " -f1`

if [[ $radio == "" ]]; then
	#statements
	
	#estaciones=`grep -v "# " $dir_base/emisoras.txt | cut -d "\"" -f2`

	echo "
	Uso: radio.sh radio [reproductor]

		radio : Parte del nombre de la estación o la frecuencia de la misma.
		reproductor : Opcional, si se indica V usara cvlc, de lo contrario usara mplayer.

	"

	#echo $estaciones
	grep -v "# " $dir_base/emisoras.txt | cut -d "\"" -f2
else 
	#statements
	if [[ $2 == "" ]]; then
		# Por defecto se usa mplayer
		mplayer -af lavcresample=44100 -cache 64 $radio
	fi
	if [[ $2 == "V" ]]; then
		# Se usa cvlc
		cvlc $radio 2> /dev/null;
	fi
fi