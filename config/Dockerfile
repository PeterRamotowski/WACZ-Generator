# Base stage: Install dependencies and tools (cache this heavily)
FROM php:8.3-apache-bullseye AS base

ENV APP_HOME /var/www
ARG HOST_UID=1000
ARG HOST_GID=1000
ENV USERNAME=www-data
ENV DEBIAN_FRONTEND noninteractive
ENV DEBCONF_NOWARNINGS="yes"

# Install system packages
RUN apt-get update -y && apt-get upgrade -y && apt-get install -y --no-install-recommends \
    ssh libgd-dev libpng-dev libjpeg-dev libjpeg62-turbo-dev libwebp-dev libxpm-dev libfreetype6-dev \
    libmemcached-dev zlib1g-dev libbz2-dev libz-dev libzip-dev libpq-dev libonig-dev git unzip bzip2 \
    subversion ca-certificates libicu-dev libxml2-dev libmcrypt-dev libtidy-dev sendmail cron g++ \
    gettext openssl nano sudo mailutils msmtp apt-utils curl wget procps htop rsync default-mysql-client supervisor \
    && apt-get autoremove -y && apt-get clean && rm -rf /var/lib/apt/lists/

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --enable-gd --with-jpeg --with-webp --with-xpm --with-freetype && \
    docker-php-ext-configure hash --with-mhash && \
    docker-php-ext-configure pdo_mysql --with-pdo-mysql=mysqlnd && \
    docker-php-ext-configure intl

RUN NUMPROC=$(grep -c ^processor /proc/cpuinfo 2>/dev/null || 1) \
    && docker-php-ext-install -j${NUMPROC} gd

RUN docker-php-ext-install bcmath bz2 calendar dba exif gettext iconv intl mysqli pgsql pdo pdo_mysql pdo_pgsql opcache soap tidy zip sockets \
    && pecl bundle -d /usr/src/php/ext apcu \
    && docker-php-ext-install /usr/src/php/ext/apcu

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN chmod +x /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1

# Install Node.js
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && \
    apt-get update && apt-get install -y nodejs

# Final stage: Set up runtime config and user
FROM base

# Create document root and fix permissions
RUN mkdir -p $APP_HOME/public && \
    mkdir -p /home/$USERNAME && chown $USERNAME:$USERNAME /home/$USERNAME && \
    usermod -o -u $HOST_UID $USERNAME -d /home/$USERNAME && \
    groupmod -o -g $HOST_GID $USERNAME && \
    chmod -R 2775 $APP_HOME && \
    chown -R ${USERNAME}:${USERNAME} $APP_HOME

# Disable default site and enable custom
RUN a2dissite 000-default.conf
COPY ./config/default-host.conf /etc/apache2/sites-available/default-host.conf
RUN a2ensite default-host.conf

# Enable Apache modules
RUN a2enmod rewrite && a2enmod ssl

# Disable Apache auto-start
RUN update-rc.d apache2 disable

# Copy and set up Supervisor
COPY ./config/supervisord.conf /etc/supervisord.conf
COPY ./config/supervisor/ /etc/supervisor/conf.d/

# Copy worker startup script
COPY ./config/start-worker.sh /var/start-worker.sh
RUN chmod +x /var/start-worker.sh

# Create Apache Supervisor config
RUN echo "[program:apache2]\n\
command=/usr/sbin/apache2ctl -D FOREGROUND\n\
process_name=apache2\n\
autostart=true\n\
autorestart=true\n\
startsecs=0\n\
startretries=3\n\
user=root\n\
redirect_stderr=true\n\
stdout_logfile=/var/log/apache2.log\n\
stdout_logfile_maxbytes=10MB\n\
stdout_logfile_backups=5" > /etc/supervisor/conf.d/apache2.conf

# Create log directories
RUN mkdir -p /var/log/supervisor

# Restart Apache (though this might not be needed in container)
RUN service apache2 restart

WORKDIR $APP_HOME
USER root

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
