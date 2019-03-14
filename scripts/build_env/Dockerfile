# Pull base image.
FROM ubuntu:18.04
ENV DEBIAN_FRONTEND noninteractive

# Install dependencies.
RUN \
  apt-get update && \
  apt-get install -y wget git php-cli php-xml php-zip php-bz2 php-curl php-json php-bcmath php-mbstring composer && \
  apt-get clean && apt-get autoremove -y && \
  rm -rf /var/lib/apt/lists/*

# Securely install box.
RUN \
  wget https://github.com/box-project/box2/releases/download/2.7.5/box-2.7.5.phar -O /usr/bin/box && \
  echo '28b4b798ad4dcf8fbf9cd68aaff495d4bbeaec4363f5f319a222829d9b6abdfe /usr/bin/box' | sha256sum --check --quiet && \
  chmod 755 /usr/bin/box && \
  sed -i "s/;phar\.readonly = On/phar\.readonly = Off/" /etc/php/7.2/cli/php.ini

# Drop privileges to "bamboo" user.
RUN useradd -m oscar -u 999
USER oscar

CMD ["bash", "-i"]