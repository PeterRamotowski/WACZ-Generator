FROM php:8.3-apache-bullseye

# set main params
ENV APP_HOME /var/www/src
ARG HOST_UID=1000
ARG HOST_GID=1000
ENV USERNAME=www-data

ENV DEBIAN_FRONTEND noninteractive
ENV DEBCONF_NOWARNINGS="yes"

RUN apt-get update -y && apt-get upgrade -y && apt-get install -y --no-install-recommends ssh libgd-dev libpng-dev libjpeg-dev libjpeg62-turbo-dev libwebp-dev libxpm-dev libfreetype6-dev libmemcached-dev zlib1g-dev libbz2-dev libz-dev libzip-dev libpq-dev libonig-dev git unzip bzip2 subversion ca-certificates libicu-dev libxml2-dev libmcrypt-dev libtidy-dev sendmail cron g++ gettext openssl nano sudo mailutils msmtp apt-utils curl wget procps htop rsync default-mysql-client && apt-get autoremove -y && apt-get clean && rm -rf /var/lib/apt/lists/

RUN docker-php-ext-configure gd --enable-gd --with-jpeg --with-webp --with-xpm --with-freetype && \
    docker-php-ext-configure hash --with-mhash && \
    docker-php-ext-configure pdo_mysql --with-pdo-mysql=mysqlnd && \
    docker-php-ext-configure intl

RUN NUMPROC=$(grep -c ^processor /proc/cpuinfo 2>/dev/null || 1) \
    && docker-php-ext-install -j${NUMPROC} gd

RUN docker-php-ext-install bcmath bz2 calendar dba exif gettext iconv intl && \
    docker-php-ext-install mysqli pgsql pdo pdo_mysql pdo_pgsql && \
    docker-php-ext-install opcache soap tidy zip sockets \
    && pecl bundle -d /usr/src/php/ext apcu \
    && docker-php-ext-install /usr/src/php/ext/apcu

# create document root, fix permissions for www-data user and change owner to www-data
RUN mkdir -p $APP_HOME/public && \
    mkdir -p /home/$USERNAME && chown $USERNAME:$USERNAME /home/$USERNAME && \
    usermod -o -u $HOST_UID $USERNAME -d /home/$USERNAME && \
    groupmod -o -g $HOST_GID $USERNAME && \
    chmod -R 2775 $APP_HOME && \
    chown -R ${USERNAME}:${USERNAME} $APP_HOME

# disable default site
RUN a2dissite 000-default.conf

# put default apache config, enable sites
COPY ./config/default-host.conf /etc/apache2/sites-available/default-host.conf
RUN a2ensite default-host.conf

# enable apache modules
RUN a2enmod rewrite
RUN a2enmod ssl

# install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN chmod +x /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1

# Install node
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && \
    apt-get update && apt-get install -y nodejs

RUN service apache2 restart

WORKDIR $APP_HOME

USER root
