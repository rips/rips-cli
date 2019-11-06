# Pull base image.
FROM ubuntu:18.04
ENV DEBIAN_FRONTEND noninteractive
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Install dependencies.
RUN \
  apt-get update && \
  apt-get install -y wget git php-cli php-xml php-zip php-bz2 php-curl php-json php-bcmath php-mbstring composer && \
  apt-get clean && apt-get autoremove -y && \
  rm -rf /var/lib/apt/lists/*

# Securely install box.
RUN \
  wget https://github.com/humbug/box/releases/download/3.8.3/box.phar -O /usr/bin/box && \
  echo 'c3e5e8ca5e72566e36879fc6e2f77350b5cb11b93d2f9202c24ca59f9bc40623 /usr/bin/box' | sha256sum --check --quiet && \
  chmod 755 /usr/bin/box && \
  sed -i "s/;phar\.readonly = On/phar\.readonly = Off/" /etc/php/7.2/cli/php.ini

# Drop privileges to "bamboo" user.
RUN useradd -m oscar -u 999
USER oscar

CMD ["bash", "-i"]