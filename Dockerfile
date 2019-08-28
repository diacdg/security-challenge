FROM php:7-fpm

# preliminary build phase
RUN apt-get update && apt-get install -y \
    git \
    zip \
  && rm -rf /var/lib/apt/lists/*

COPY composer* /var/www/
RUN cd /var/www && php composer.phar install

COPY . /var/www/
