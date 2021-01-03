shell :=/bin/bash

tests:
	APP_ENV=test symfony console doctrine:fixtures:load -n
	symfony php bin/phpunit
.PHONY: tests
