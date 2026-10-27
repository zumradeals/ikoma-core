.PHONY: install up down test test-front e2e lint migrate-fresh-seed
install:
	docker compose build php
	sh scripts/install.sh
up:
	docker compose up -d --build --wait
down:
	docker compose down
test:
	docker compose exec -T -e DB_DATABASE=ikoma_test php php artisan migrate --force
	docker compose exec -T php composer test
test-front:
	docker compose run --rm --no-deps front npm test
e2e:
	docker compose run --rm e2e
lint:
	docker compose exec -T php composer lint
	docker compose exec -T php composer analyse
	docker compose run --rm --no-deps front npm run lint
	docker compose run --rm --no-deps front npm run typecheck
migrate-fresh-seed:
	docker compose exec php php artisan migrate:fresh --seed
