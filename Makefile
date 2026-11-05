DC = HOST_UID=$$(id -u) HOST_GID=$$(id -g) docker compose run --rm php

.PHONY: build shell install test stan fix

build:
	HOST_UID=$$(id -u) HOST_GID=$$(id -g) docker compose build

shell:
	$(DC) bash

install:
	$(DC) composer install

test:
	$(DC) vendor/bin/phpunit

stan:
	$(DC) vendor/bin/phpstan analyse --memory-limit=1G

fix:
	$(DC) vendor/bin/pint
