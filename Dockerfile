FROM php:7.4-cli

# php:7.4-cli is Debian 11 (EOL): its packages now live only on archive.debian.org.
RUN sed -i 's#http://deb.debian.org#http://archive.debian.org#g' /etc/apt/sources.list \
    && apt-get -o Acquire::Check-Valid-Until=false update && apt-get install -y \
    unzip \
    git \
    && docker-php-ext-install pcntl posix sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

EXPOSE 2026 2027

CMD ["sh", "-c", "composer install --no-dev --optimize-autoloader --no-interaction && php examples/chat/start.php start"]