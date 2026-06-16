build: ## Construir contenedores
	docker-compose build --no-cache

up: ## Iniciar contenedores
	docker-compose up -d

down: ## Detener contenedores
	docker-compose down

down-volumes: ## Detener y eliminar volúmenes
	docker-compose down -v

restart: ## Reiniciar contenedores
	docker-compose restart

logs: ## Ver logs de todos los servicios
	docker-compose logs -f

logs-app: ## Ver logs de app
	docker-compose logs -f $(APP_CONTAINER)

logs-nginx: ## Ver logs de nginx
	docker-compose logs -f $(NGINX_CONTAINER)

logs-mysql: ## Ver logs de mysql
	docker-compose logs -f $(MYSQL_CONTAINER)

shell: ## Entrar al contenedor app
	docker-compose exec app sh

shell-root: ## Entrar al contenedor app como root
	docker-compose exec -u root app sh

composer: ## Ejecutar composer install
	docker-compose exec app composer install

composer-update: ## Actualizar dependencias
	docker-compose exec app composer update

composer-dump: ## Regenerar autoload
	docker-compose exec app composer dump-autoload

artisan: ## Ejecutar comando artisan (uso: make artisan CMD="migrate")
	docker-compose exec app php artisan $(CMD)

migrate: ## Ejecutar migraciones
	docker-compose exec app php artisan migrate

migrate-fresh: ## Migraciones frescas con seed
	docker-compose exec app php artisan migrate:fresh --seed

rollback: ## Rollback última migración
	docker-compose exec app php artisan migrate:rollback

seed: ## Ejecutar seeders
	docker-compose exec app php artisan db:seed

test: ## Ejecutar tests
	docker-compose exec app php artisan test

test-coverage: ## Ejecutar tests con coverage
	docker-compose exec app php artisan test --coverage

tinker: ## Ejecutar tinker
	docker-compose exec app php artisan tinker

cache-clear: ## Limpiar todos los caches
	docker-compose exec app php artisan cache:clear
	docker-compose exec app php artisan config:clear
	docker-compose exec app php artisan route:clear
	docker-compose exec app php artisan view:clear

optimize: ## Optimizar aplicación para producción
	docker-compose exec app php artisan config:cache
	docker-compose exec app php artisan route:cache
	docker-compose exec app php artisan view:cache
	docker-compose exec app php artisan event:cache

optimize-clear: ## Limpiar optimizaciones
	docker-compose exec app php artisan optimize:clear

key-generate: ## Generar APP_KEY
	docker-compose exec app php artisan key:generate

storage-link: ## Crear symlink storage
	docker-compose exec app php artisan storage:link
