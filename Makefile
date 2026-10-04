.DEFAULT_GOAL := help

DOCKER_COMPOSE ?= docker compose
PORT ?= 9000
export PORT

.PHONY: help build up down restart logs ps shell check download rebuild translations translation-merge db-env db-check db-migrate db-seed cms-install cms-upgrade

help:
	@echo "make up              Start local MySQL, migrate, seed, and serve on localhost:$(PORT)"
	@echo "make down            Stop containers and retain the local database"
	@echo "make build           Build the PHP/Apache image"
	@echo "make restart         Restart the website"
	@echo "make logs            Follow Apache logs"
	@echo "make ps              Show container status"
	@echo "make shell           Open a shell inside the container"
	@echo "make check           Verify downloaded files and HTTP routes"
	@echo "make download        Resume the website downloader"
	@echo "make rebuild         Rebuild local pages from their originals"
	@echo "make translations    Extract the English text dictionary"
	@echo "make db-check        Check the configured MySQL connection"
	@echo "make db-env          Generate private local database settings in .env"
	@echo "make db-migrate      Apply pending database migrations"
	@echo "make db-seed         Seed missing pages, media, and the initial administrator"
	@echo "make cms-install     Install portal tables and index the website"
	@echo "make cms-upgrade     Upgrade CMS page management tables"
	@echo "make translation-merge FILES='translated/fr.json translated/es.json'"

build:
	$(DOCKER_COMPOSE) build web

up: db-env
	$(DOCKER_COMPOSE) up -d --wait --wait-timeout 180 web

db-env: build
	$(DOCKER_COMPOSE) run --rm --no-deps web php tools/local-db-setup.php

down:
	$(DOCKER_COMPOSE) down

restart:
	$(DOCKER_COMPOSE) restart web

logs:
	$(DOCKER_COMPOSE) logs -f --tail=100 web

ps:
	$(DOCKER_COMPOSE) ps

shell:
	$(DOCKER_COMPOSE) exec web sh

check:
	$(DOCKER_COMPOSE) exec -T web php tools/verify.php http://127.0.0.1

download:
	$(DOCKER_COMPOSE) exec -T web php tools/mirror.php $(ARGS)

rebuild:
	$(DOCKER_COMPOSE) exec -T web php tools/mirror.php --rebuild

translations:
	$(DOCKER_COMPOSE) exec -T web php tools/translations.php extract

db-check:
	$(DOCKER_COMPOSE) exec -T web php tools/db-check.php

db-migrate:
	$(DOCKER_COMPOSE) exec -T web php tools/db-migrate.php

db-seed:
	$(DOCKER_COMPOSE) exec -T web php tools/db-seed.php $(ARGS)

cms-install:
	$(DOCKER_COMPOSE) exec -T web php tools/cms-install.php $(ARGS)

cms-upgrade:
	$(DOCKER_COMPOSE) exec -T web php tools/cms-upgrade.php

translation-merge:
	$(if $(strip $(FILES)),,$(error Set FILES to the translated JSON paths inside this project))
	$(DOCKER_COMPOSE) exec -T web php tools/translations.php merge $(FILES)
