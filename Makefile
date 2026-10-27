.PHONY: install up down test test-front e2e lint migrate-fresh-seed
install:
	sh scripts/install.sh
up:
	docker compose up -d --build --wait
down:
	docker compose down
test:
	docker compose run --rm -e DB_DATABASE=ikoma_test migrator
	docker compose exec -T php composer test
test-front:
	docker compose run --rm --no-deps front npm test
e2e:
	docker compose exec -T -e APP_ENV=testing php sh -c 'php artisan test:screen-account > .secrets/screen-account.json'
	docker compose run --rm e2e
lint:
	docker compose exec -T php composer lint
	docker compose exec -T php composer analyse
	docker compose run --rm --no-deps front npm run lint
	docker compose run --rm --no-deps front npm run typecheck
migrate-fresh-seed:
	docker compose run --rm migrator sh -c 'DB_USERNAME=ikoma DB_PASSWORD="$$(cat .secrets/db-owner)" php artisan migrate:fresh --force && php scripts/grant-db.php && php artisan db:seed --force'
