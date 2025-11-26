FROM php:8.3-fpm-bookworm

# Install system dependencies: nginx serves HTTP, aria2 handles downloads, supervisor orchestrates processes.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        aria2 \
        curl \
        ca-certificates \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Install Composer globally for dependency management.
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Allow overriding container user/group IDs at build time to avoid permission issues on mounted volumes.
ARG PUID=1000
ARG PGID=1000
RUN groupadd -g ${PGID} app \
    && useradd -m -u ${PUID} -g app app

WORKDIR /var/www

# Copy application sources into the container image.
COPY . /var/www

# Install PHP dependencies for production usage.
RUN composer install --no-dev --optimize-autoloader

# Configure nginx and supervisor.
RUN rm -f /etc/nginx/sites-enabled/default
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
RUN sed -i 's@^user .*;@user app;@' /etc/nginx/nginx.conf \
    && sed -i 's@pid /run/nginx.pid;@pid /var/www/storage/nginx.pid;@' /etc/nginx/nginx.conf \
    && sed -i 's@access_log /var/log/nginx/access.log;@access_log /var/www/storage/logs/nginx-access.log;@' /etc/nginx/nginx.conf \
    && sed -i 's@error_log /var/log/nginx/error.log;@error_log /var/www/storage/logs/nginx-error.log;@' /etc/nginx/nginx.conf

# Prepare writable directories and adjust ownership for the runtime user.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/php-error.ini /usr/local/etc/php/conf.d/php-error.ini
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN mkdir -p /downloads /library /var/www/storage/logs \
    && mkdir -p /var/lib/nginx/body /var/lib/nginx/fastcgi /var/lib/nginx/proxy \
    && mkdir -p /var/log/nginx \
    && chown -R app:app /downloads /library /var/www /var/lib/nginx /var/log/nginx

USER app

EXPOSE 8080 6800

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n"]
