FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libonig-dev libpng-dev libxml2-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath pdo_mysql mbstring zip gd \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
