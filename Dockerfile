FROM php:8.4-fpm-alpine

ARG dir="/var/www/"

ENV BUILD_ENV="prod"
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV TZ="UTC"
ENV INCLUDE_EXAMPLES="false"

ENV BASE_PKG="gnupg tzdata nodejs npm" \
    PHP_PKG="zlib-dev icu-dev libzip-dev"

RUN apk add --update --no-cache \
    $BASE_PKG \
    $PHP_PKG
RUN npm i -g yarn

RUN docker-php-ext-install intl \
    && docker-php-ext-install zip \
    && docker-php-ext-install pdo_mysql



RUN mkdir -p $dir
WORKDIR $dir

COPY .docker/php/bin bin/
RUN bin/composer-install.sh

RUN mv composer.phar /usr/local/bin/composer

 # PROJECT
RUN chmod +x bin/entrypoint.sh

COPY composer.* ./
COPY webpack.config.js ./
COPY package.json ./
COPY yarn.lock ./

COPY assets assets/
COPY config config/
COPY public public/
COPY src src/
COPY templates templates/
COPY user user/
COPY .env* ./

RUN mkdir -p var/cache \
    && mkdir -p var/logs \
    && mkdir vendor/ \
    && mkdir -p .cache/yarn \
    && mkdir node_modules \
    && mkdir -p public/media \
    && mkdir -p public/build \
    && chown -R www-data: var/  \
    && chown -R www-data: vendor \
    && chown -R www-data: node_modules \
    && chown -R www-data: .cache/yarn \
    && chown -R www-data: public/build \
    && chown -R www-data: public/media \
;

USER www-data:

RUN bin/composer.sh $BUILD_ENV
RUN yarn install


USER root

WORKDIR $dir/public

# PHP-FPM runs as www-data by default
ENTRYPOINT ["/var/www/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]
