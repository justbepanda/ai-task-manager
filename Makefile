# Makefile

SHELL = /bin/bash

ifndef VERBOSE
MAKEFLAGS += --no-print-directory
endif

include $(PWD)/.env.example
-include $(PWD)/.env

.PHONY: help install install-backend build up up-backend up-backend-force down init-env clear-cache composer-install migrate test artisan wait-db docker-exec docker-compose-exec php db seed fresh-seed

.SILENT: php db

help:
	@printf '		'
	@make print-color-blue TEXT='** Available commands **\n\n'
	@make print-color-green TEXT='install'
	@printf '			build images, start stack, composer install, migrate, key\n'
	@make print-color-green TEXT='install-backend'
	@printf '		backend only install\n'
	@make print-color-green TEXT='build'
	@printf '			build PHP Docker image\n'
	@make print-color-green TEXT='up'
	@printf '			start project (web, php, db, redis, mailpit, queue, scheduler)\n'
	@make print-color-green TEXT='up-backend'
	@printf '		start web, php, db, redis\n'
	@make print-color-green TEXT='down'
	@printf '			stop all containers\n'
	@make print-color-green TEXT='clear-cache'
	@printf '		reset all backend caches\n'
	@make print-color-green TEXT='composer-install'
	@printf '		composer install inside PHP container\n'
	@make print-color-green TEXT='migrate'
	@printf '			run database migrations\n'
	@make print-color-green TEXT='seed'
	@printf '			run database seeders\n'
	@make print-color-green TEXT='fresh-seed'
	@printf '		migrate:fresh --seed\n'
	@make print-color-green TEXT='test'
	@printf '			run automated tests\n'
	@make print-color-green TEXT='artisan'
	@printf '			run artisan: make artisan CMD="migrate:status"\n'
	@echo '---------'
	@make print-color-green TEXT='php'
	@printf '			connect to PHP container\n'
	@make print-color-green TEXT='db'
	@printf '			connect to Database container\n\n'

install: init-env build up
	@make install-backend

install-backend: init-env up-backend
	@make wait-db
	@make composer-install
	@make docker-exec CONTAINER="php" CONTAINER_CMD="sh -c 'test -n \"$$(grep ^APP_KEY= .env | cut -d= -f2)\" || php artisan key:generate'"
	@make docker-exec CONTAINER="php" CONTAINER_CMD="sh -c 'php artisan storage:link --force --relative || true'"
	@make migrate

build:
	@make docker-compose-exec COMPOSE_CMD="build php queue scheduler"

up: up-backend
	@make docker-compose-exec COMPOSE_CMD="up -d mailpit queue scheduler"

up-backend:
	@make docker-compose-exec COMPOSE_CMD="up -d web db redis php"

up-backend-force:
	@make print-color-green TEXT='Stop containers: \n'
	@docker stop $$(docker ps -q) && printf '\033[32mdone\033[0m\n' || printf '\033[31mStop containers failed\033[0m\n'
	@make docker-compose-exec COMPOSE_CMD="up -d web db redis php"

down:
	@make docker-compose-exec COMPOSE_CMD="down"

init-env:
	@test -f .env || cp .env.example .env

clear-cache: init-env up-backend
	@make docker-exec CONTAINER="php" CONTAINER_CMD="php artisan optimize:clear"

composer-install:
	@make docker-exec CONTAINER="php" CONTAINER_CMD="composer install -n"

migrate:
	@make docker-exec CONTAINER="php" CONTAINER_CMD="php artisan migrate --force"

seed:
	@make docker-exec CONTAINER="php" CONTAINER_CMD="php artisan db:seed --force"

fresh-seed:
	@make docker-exec CONTAINER="php" CONTAINER_CMD="php artisan migrate:fresh --seed --force"

test:
	@make docker-exec CONTAINER="php" CONTAINER_CMD="php artisan test"

artisan:
	@make docker-exec CONTAINER="php" CONTAINER_CMD="php artisan $(CMD)"

wait-db:
	@printf '=== Waiting for DB'
	@while ! docker exec $(APP_NAME)-db mysqladmin ping -h127.0.0.1 -u$${DB_USERNAME} -p$${DB_PASSWORD} --silent 2>/dev/null; \
		do printf '.'; \
		sleep 2; \
		done;
	@printf '\n'
	@make print-color-green TEXT='DB is ready\n'

# Use -it only when a TTY is attached (interactive); otherwise -i for scripts/CI.
docker-exec:
	@if [ -t 0 ]; then \
		docker exec -it $(APP_NAME)-$(CONTAINER) $(CONTAINER_CMD); \
	else \
		docker exec -i $(APP_NAME)-$(CONTAINER) $(CONTAINER_CMD); \
	fi

docker-compose-exec:
	@docker compose $(COMPOSE_CMD)

print-color-green:
	@printf '\033[0;32m${TEXT}\033[0m'

print-color-blue:
	@printf '\033[0;36m${TEXT}\033[0m'

php:
	@make docker-exec CONTAINER="php" CONTAINER_CMD="bash"

db:
	@make docker-exec CONTAINER="db" CONTAINER_CMD="bash"
