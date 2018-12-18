## CI Scripts
This scripts and their Docker environments are used by the CI tools to automatically test and build the application.
They can also be used to build the application locally though.

### Build
    docker build -t jetty.ripstech.com/ci_rcli_build build_env
    docker run -u `id -u` --rm -v `pwd`/..:/var/www jetty.ripstech.com/ci_rcli_build /var/www/scripts/build.sh

### Test
    docker build -t jetty.ripstech.com/ci_rcli_test test_env
    docker run -u `id -u` --rm -v `pwd`/..:/var/www jetty.ripstech.com/ci_rcli_test /var/www/scripts/validate.sh

### Metrics
    docker build -t jetty.ripstech.com/ci_rcli_metrics test_env
    docker run -u `id -u` --rm -v `pwd`/..:/var/www jetty.ripstech.com/ci_rcli_metrics /var/www/scripts/metrics.sh
