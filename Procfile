# Dokku: after deploy, set clean-exit workers to restart: dokku ps:set restart-policy always && dokku ps:rebuild
#  dokku ps:scale lingua translator=1
# Dokku's Procfile support overrides the Dockerfile's CMD per process type -- even
# under the dockerfile builder -- so a stale `web:` line silently keeps the old
# broken command.
web: frankenphp run --config /etc/caddy/Caddyfile
# --fetch-size=8 (Symfony 8.1): pulls 8 messages per Doctrine query instead of one per
# round trip. Worth more than usual here — the transport is doctrine://default, i.e. the
# same Postgres serving the app, and PG connection timeouts have already been seen from
# one-off containers. Fewer, larger reads is the cheapest relief available before the
# move to RabbitMQ.
#
# --no-reset=100 (Symfony 8.1): reset services every 100 messages rather than after every
# one. A translate worker holds no per-message state worth clearing that often.
translator: php bin/console messenger:consume target.translate --fetch-size=8 --no-reset=100 --time-limit=3600 --memory-limit=512M
webhook: php bin/console messenger:consume webhook --time-limit=3600 --memory-limit=256M
