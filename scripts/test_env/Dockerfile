# Pull base image.
FROM ubuntu:18.04
ENV DEBIAN_FRONTEND noninteractive

# Upgrade system.
RUN \
  apt-get update && \
  apt-get install -y php-cli php-xml php-dom php-sqlite3 php-bcmath php-mbstring php-curl php-memcached php-zip php-pear php-dev \
                     build-essential git curl wget xvfb libxrender1 xz-utils libfontconfig ca-certificates composer && \
  apt-get clean && apt-get autoremove -y && \
  rm -rf /var/lib/apt/lists/*

# Install PHP extensions that are not available through APT.
RUN pecl install ast-0.1.6
COPY configs/20-ast.ini /etc/php/7.2/cli/conf.d/20-ast.ini

# Install hadolint.
RUN \
  wget https://github.com/hadolint/hadolint/releases/download/v1.16.3/hadolint-Linux-x86_64 -O /usr/bin/hadolint && \
  echo '7224a0304415823a8f8767c99b3f769b1f57e06f4d89d18783cc23a5fe0669c5 /usr/bin/hadolint' | sha256sum --check --quiet && \
  chmod 755 /usr/bin/hadolint

# Drop privileges.
RUN useradd -m oscar -u 999
USER oscar

CMD ["bash", "-i"]