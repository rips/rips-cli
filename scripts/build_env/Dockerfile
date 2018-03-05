# Pull base image.
FROM ubuntu:17.10

# Install dependencies.
RUN \
  apt-get update && \
  apt-get install -y wget git php-cli php-xml php-zip php-bz2 php-curl php-json php-bcmath php-mbstring && \
  apt-get clean && apt-get autoremove -y && \
  rm -rf /var/lib/apt/lists/*

# Install box.
RUN \
  wget https://github.com/box-project/box2/releases/download/2.7.5/box-2.7.5.phar -O /usr/bin/box && \
  echo '28b4b798ad4dcf8fbf9cd68aaff495d4bbeaec4363f5f319a222829d9b6abdfe /usr/bin/box' | sha256sum --check --quiet && \
  chmod 755 /usr/bin/box

RUN \
  sed -i "s/;phar\.readonly = On/phar\.readonly = Off/" /etc/php/7.1/cli/php.ini

# Securely install composer.
USER nobody
WORKDIR /tmp
RUN \
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
  php -r "if (hash_file('SHA384', 'composer-setup.php') !== '544e09ee996cdf60ece3804abc52599c22b1f40f4323403c44d44fdfdd586475ca9813a858088ffbc1f233e9b180f061') { unlink('composer-setup.php'); }" && \
  HOME="/tmp" php composer-setup.php && \
  php -r "unlink('composer-setup.php');"

# Drop privileges to "bamboo" user.
USER root
RUN useradd -m oscar -u 999
USER oscar

CMD ["bash", "-i"]