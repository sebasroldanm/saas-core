#!/bin/sh
# Permite al usuario de la app crear, migrar y eliminar las BD de tenants (prefijo TENANT_DB_PREFIX)
# y usar la BD central de tests ({MYSQL_DATABASE}_testing, ver phpunit.xml).
# El entrypoint de MySQL lo ejecuta solo en la primera inicialización del directorio de datos.
set -e

PREFIX="${TENANT_DB_PREFIX:-tenant_}"
# En los patrones de GRANT, "_" es comodín: se escapa para que coincida literalmente
PATTERN="$(printf '%s' "$PREFIX" | sed 's/_/\\_/g')%"

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
GRANT ALL PRIVILEGES ON \`${PATTERN}\`.* TO '${MYSQL_USER}'@'%';
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}_testing\`;
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}_testing\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
