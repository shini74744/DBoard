FROM redis:8.4.2-alpine AS redis-runtime

FROM phpswoole/swoole:php8.3-alpine

LABEL org.opencontainers.image.source="https://github.com/shini74744/DBoard"
LABEL org.opencontainers.image.title="DBoard"
LABEL org.opencontainers.image.description="DBoard panel runtime"

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=redis-runtime /usr/local/bin/redis-server /usr/local/bin/redis-server
COPY --from=redis-runtime /usr/local/bin/redis-cli /usr/local/bin/redis-cli

RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    install-php-extensions zip redis && \
    apk --no-cache add \
      shadow sqlite mysql-client mysql-dev mariadb-connector-c \
      git patch supervisor caddy libstdc++ openssl && \
    addgroup -S -g 1000 www && \
    adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

WORKDIR /www

COPY . /www
COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini
COPY .docker/entrypoint.sh /entrypoint.sh

RUN composer install --no-cache --no-dev --no-interaction \
      --prefer-dist --optimize-autoloader --no-security-blocking && \
    php artisan storage:link || true && \
    mkdir -p /data storage/logs storage/app storage/backup storage/theme public/theme plugins && \
    chown -R www:www /www && \
    chown -R redis:redis /data && \
    chmod +x /entrypoint.sh

ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_WS_SERVER=true \
    ENABLE_SCHEDULER=true \
    ENABLE_CADDY=true

EXPOSE 7001

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
