#!/bin/bash
sudo cp -r /home/servidorweb/Documentos/citas-medicas-app/* /var/www/html/
sudo chown -R www-data:www-data /var/www/html/
echo "¡Sincronización completada!"