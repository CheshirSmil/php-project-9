install:
	composer install

validate:
	composer validate

lint:
	composer exec --verbose phpcs -- --standard=PSR12 public src
	composer exec --verbose phpstan -- --level=8 analyse public

PORT ?= 8000
start:
	php -S 0.0.0.0:$(PORT) -t public

