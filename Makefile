install:
	composer install

validate:
	composer validate

lint:
	composer exec --verbose phpcs -- --standard=PSR12 public
	composer exec --verbose phpstan -- --level=8 analyse public

PORT ?= 8000
start:
	php -S 0.0.0.0:$(PORT) -t public

db-reset:
	dropdb project9 || true
	createdb project9

test-db-reset:
	dropdb project9test || true
	createdb project9test

create_table-urls:
	psql project9 < database.sql

test-create_table-urls:
	psql project9test < database.sql