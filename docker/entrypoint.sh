#!/bin/sh
set -eu

if [ "$#" -gt 0 ]; then
  exec "$@"
fi

PORT_VALUE="${PORT:-8080}"
case "$PORT_VALUE" in
  ''|*[!0-9]*) echo "PORT precisa ser numérica." >&2; exit 1 ;;
esac

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php /var/www/html/bin/migrate.php || echo "Aviso: migração falhou; confira o banco (o site sobe mesmo assim)." >&2
fi

sed -ri "s/^[[:space:]]*Listen[[:space:]]+[0-9]+/Listen ${PORT_VALUE}/" /etc/apache2/ports.conf
sed -ri "s#<VirtualHost \*:80>#<VirtualHost *:${PORT_VALUE}>#" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground
