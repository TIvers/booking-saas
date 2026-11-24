up:
	docker compose up -d --build

down:
	docker compose down

migrate:
	docker compose exec app php artisan migrate

seed:
	docker compose exec app php artisan db:seed

test:
	docker compose exec app php artisan test

lint:
	docker compose exec app ./vendor/bin/pint --test

front-test:
	cd frontend && npm test

fresh:
	docker compose exec app php artisan migrate:fresh --seed
