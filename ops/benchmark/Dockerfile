ARG DEMO_IMAGE=waar-engine-demo:a4b0cc3
FROM ${DEMO_IMAGE}
COPY engines/waar-cohort/autoload.php /app/engines/waar-cohort/autoload.php
COPY engines/waar-cohort/src /app/engines/waar-cohort/src
COPY engines/waar-cohort/bin/php-runtime.php /app/engines/waar-cohort/bin/php-runtime.php
COPY bin/benchmark-cohort.php /app/bin/benchmark-cohort.php
HEALTHCHECK NONE
ENTRYPOINT ["php", "/app/bin/benchmark-cohort.php"]
