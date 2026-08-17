# Dokku: after deploy, set clean-exit workers to restart: dokku ps:set restart-policy always && dokku ps:rebuild
#  dokku ps:scale lingua translator=1
web: vendor/bin/heroku-php-nginx -C nginx.conf -F fpm_custom.conf public/
translator: php bin/console messenger:consume target.translate --time-limit=3600 --memory-limit=512M
webhook: php bin/console messenger:consume webhook --time-limit=3600 --memory-limit=256M
