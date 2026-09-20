FROM rust:1-bookworm@sha256:93ce27a88655056a51dbdd8f5f2d7ddc071c7b0070fb288a37b5a285fc83971e AS native
WORKDIR /build
COPY engines/waar-cohort/rust/Cargo.toml engines/waar-cohort/rust/Cargo.lock ./
COPY engines/waar-cohort/rust/src ./src
RUN cargo build --locked --release --bin waar-cohort-cli

FROM php:8.3-apache-bookworm@sha256:d9be5f5c49e07c71b1e906cf01a0bb7216dce466d1880d170467b9ca107071dd
WORKDIR /app
COPY autoload.php ./
COPY src ./src
COPY resources ./resources
COPY public/workshop ./public/workshop
COPY bin/workshop-router.php ./bin/workshop-router.php
COPY --from=native /build/target/release/waar-cohort-cli ./engines/waar-cohort/rust/target/release/waar-cohort-cli
COPY ops/demo/index.php ./public/workshop/index.php
COPY ops/demo/apache.conf /etc/apache2/sites-available/000-default.conf
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && a2dismod -f status autoindex \
    && printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/demo-security.conf \
    && printf 'memory_limit=256M\nexpose_php=Off\nlog_errors=On\ndisplay_errors=Off\n' > /usr/local/etc/php/conf.d/demo.ini
RUN mkdir -p /var/lib/waar-profiles && chown www-data:www-data /var/lib/waar-profiles && chmod 700 /var/lib/waar-profiles
ENV WAAR_COHORT_RUNTIME=rust
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/api/default-profile") === false ? 1 : 0);'
