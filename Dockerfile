FROM php:7.4-cli

RUN apt-get update && apt-get install -y \
    unzip \
    git \
    && docker-php-ext-install pcntl posix sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

EXPOSE 2026 2027

CMD ["sh", "-c", "composer install --no-dev --optimize-autoloader --no-interaction && php examples/chat/start.php start"]