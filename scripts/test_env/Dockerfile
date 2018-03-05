# Pull base image.
FROM ubuntu:17.10

# Upgrade system.
RUN \
  apt-get update && \
  apt-get upgrade -y && \
  apt-get install -y php-cli php-xml php-dom php-sqlite3 php-bcmath php-mbstring php-curl php-memcached php-zip php-pear php-dev \
                     build-essential git curl wget xz-utils libfontconfig ca-certificates && \
  apt-get clean && apt-get autoremove -y && \
  rm -rf /var/lib/apt/lists/*

# Install PHP extensions that are not available through APT.
RUN pecl install ast
COPY configs/20-ast.ini /etc/php/7.1/cli/conf.d/20-ast.ini

# Install NodeJS/NPM.
RUN \
  curl -sL https://deb.nodesource.com/setup_6.x | bash - && \
  apt-get install -y nodejs

# Install docklint.
RUN npm install -g validate-dockerfile

# Securely install composer.
USER nobody
WORKDIR /tmp
RUN \
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
  php -r "if (hash_file('SHA384', 'composer-setup.php') !== '544e09ee996cdf60ece3804abc52599c22b1f40f4323403c44d44fdfdd586475ca9813a858088ffbc1f233e9b180f061') { unlink('composer-setup.php'); }" && \
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