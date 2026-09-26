FROM rust:1-bookworm@sha256:93ce27a88655056a51dbdd8f5f2d7ddc071c7b0070fb288a37b5a285fc83971e AS native
WORKDIR /build
COPY engines/waar-cohort/rust/Cargo.toml engines/waar-cohort/rust/Cargo.lock ./
COPY engines/waar-cohort/rust/src ./src
RUN cargo build --locked --release --features fast-impact --bin waar-cohort-cli

FROM php:8.3-apache-bookworm@sha256:d9be5f5c49e07c71b1e906cf01a0bb7216dce466d1880d170467b9ca107071dd
WORKDIR /app
COPY autoload.php ./
COPY src ./src
COPY bin/parametric-campaign.php ./bin/parametric-campaign.php
COPY experiments/campagne-coeur ./experiments/campagne-coeur
COPY experiments/campagne-coeur-optimized ./experiments/campagne-coeur-optimized
COPY experiments/campagne-coeur/reference-profile.json ./reports/campaign-manual-sol/profile.json
COPY engines/waar-cohort/rust/src ./engines/waar-cohort/rust/src
COPY --from=native /build/target/release/waar-cohort-cli ./engines/waar-cohort/rust/target/release/waar-cohort-cli
ENV WAAR_COHORT_RUNTIME=rust
ENTRYPOINT ["php", "/app/bin/parametric-campaign.php"]
