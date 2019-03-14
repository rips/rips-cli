# Pull base image.
FROM ubuntu:18.04
ENV DEBIAN_FRONTEND noninteractive

# Upgrade system.
RUN \
  apt-get update && \
  apt-get install -y php-cli php-xml php-dom php-sqlite3 php-bcmath php-mbstring php-curl php-memcached php-zip php-pear php-dev \
                     build-essential git curl wget xvfb libxrender1 xz-utils libfontconfig ca-certificates && \
  apt-get clean && apt-get autoremove -y && \
  rm -rf /var/lib/apt/lists/*

# Install PHP extensions that are not available through APT.
RUN pecl install ast-0.1.6
COPY configs/20-ast.ini /etc/php/7.2/cli/conf.d/20-ast.ini

# Install NodeJS/NPM.
RUN \
  curl -sL https://deb.nodesource.com/setup_10.x | bash - && \
  apt-get install -y nodejs

# Install docklint.
RUN npm install -g validate-dockerfile

# Securely install composer.
USER nobody
WORKDIR /tmp
RUN \
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
  php -r "if (hash_file('SHA384', 'composer-setup.php') !== '48e3236262b34d30969dca3c37281b3b4bbe3221bda826ac6a9a62d6444cdb0dcd0615698a5cbe587c3f0fe57a54d8f5') { unlink('composer-setup.php'); }" && \
  HOME="/tmp" php composer-setup.php && \
  php -r "unlink('composer-setup.php');"

USER root
RUN \
  chown root:root composer.phar && \
  chmod 755 composer.phar && \
  mv composer.phar /usr/bin/composer

# Drop privileges.
RUN useradd -m oscar -u 999
USER oscar

CMD ["bash", "-i"]