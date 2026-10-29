# Instalación de core-saas desde cero

Guía para levantar el entorno de desarrollo local a partir del repositorio. Todo corre en Docker: no hace falta tener PHP, Composer, Node ni MySQL instalados en la máquina.

> Para el día a día (comandos, arquitectura, convenciones y pendientes) ver [develop.md](develop.md).

## 1. Requisitos

| Herramienta | Notas |
|---|---|
| Docker Desktop (o Docker Engine) con Docker Compose v2 | En Windows con WSL2: activar **Settings → Resources → WSL Integration** para la distro que uses. Comprobar con `docker --version` y `docker compose version`. |
| Git | |
| Navegador Chrome o Edge | Resuelven `*.localhost` sin configuración (los tenants usan subdominios). Firefox y `curl` necesitan entradas en el archivo hosts (ver [Problemas frecuentes](#7-problemas-frecuentes)). |

Puertos que deben estar libres en el host (se pueden cambiar en `.env`):

| Puerto | Servicio | Variable |
|---|---|---|
| 8080 | Aplicación (Nginx) | `APP_PORT` |
| 33061 | MySQL | `DB_FORWARD_PORT` |
| 63791 | Redis | `REDIS_FORWARD_PORT` |
| 5173 | Vite (dev server) | `VITE_PORT` |

## 2. Clonar el repositorio

```bash
git clone https://github.com/sebasroldanm/saas-core.git core-saas
cd core-saas
```

En WSL, clonar dentro del sistema de archivos de Linux (por ejemplo `~/projects`), no en `/mnt/c/...`: los volúmenes de Docker son mucho más lentos sobre el disco de Windows.

## 3. Configurar el `.env`

```bash
cp .env.example .env
```

Revisar que el `.env` tenga estos valores. El mismo archivo lo leen Laravel y Docker Compose (para puertos, credenciales y rutas de datos):

```dotenv
APP_NAME=core-saas
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8080
APP_LOCALE=en            # "es" para Filament en español

DB_CONNECTION=mysql
DB_HOST=core-saas-db     # nombre del contenedor, no localhost
DB_PORT=3306             # puerto interno de la red Docker
DB_DATABASE=core_saas
DB_USERNAME=core_saas
DB_PASSWORD=secret

SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis

REDIS_CLIENT=phpredis
REDIS_HOST=core-saas-redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# Opcionales (valores por defecto entre paréntesis)
# APP_PORT=8080
# DB_FORWARD_PORT=33061
# REDIS_FORWARD_PORT=63791
# VITE_PORT=5173
# DB_ROOT_PASSWORD=root
# TENANT_DB_PREFIX=tenant_
# DB_DATA_PATH=./docker/data/mysql
# REDIS_DATA_PATH=./docker/data/redis
```

**Si tu usuario de Linux no tiene UID 1000** (comprobar con `id -u`), agregar `UID` y `GID` al `.env` con tus valores para que los archivos generados por los contenedores queden a tu nombre:

```dotenv
UID=1001
GID=1001
```

**No cambiar `DB_DATABASE`, `DB_USERNAME` ni `TENANT_DB_PREFIX` después del primer arranque**: los permisos de MySQL se crean con esos valores la primera vez (ver paso 4).

## 4. Levantar los contenedores

```bash
docker compose up -d --build
```

Se crean 5 contenedores:

| Contenedor | Qué es |
|---|---|
| `core-saas-backend` | PHP 8.5 FPM con Laravel |
| `core-saas-server` | Nginx, publica la app en el puerto 8080 |
| `core-saas-db` | MySQL 9.7 |
| `core-saas-redis` | Redis 8.10 (cache, sesiones y colas) |
| `core-saas-node` | Node 26: instala dependencias npm y corre Vite |

En el primer arranque MySQL inicializa `docker/data/mysql` y ejecuta [docker/mysql/init/01-tenant-grants.sh](docker/mysql/init/01-tenant-grants.sh), que:

- da al usuario de la app permisos sobre las bases `tenant_*` (una por tenant), y
- crea la base de tests `core_saas_testing` con sus permisos.

Comprobar que todo está arriba (MySQL y Redis deben decir `healthy`):

```bash
docker compose ps
```

## 5. Instalar Laravel y preparar la base central

```bash
# Dependencias PHP
docker compose exec backend composer install

# Clave de la aplicación
docker compose exec backend php artisan key:generate

# Tablas de la base central (admins, tenants, dominios, etc.)
docker compose exec backend php artisan migrate

# Super admin del panel central (pide nombre, email y contraseña)
docker compose exec backend php artisan core:make-superadmin
```

Las dependencias npm las instala solo el contenedor `core-saas-node` al arrancar. Para ver que Vite quedó corriendo:

```bash
docker compose logs node
```

## 6. Verificar la instalación

1. Abrir http://localhost:8080/admin y entrar con el super admin.
2. En **Tenants → New tenant** («Nuevo tenant» con `APP_LOCALE=es`), crear un tenant de prueba:
   - Nombre: `Acme`, slug: `acme` (sin dominios: se usa `acme` como subdominio).
   - Datos del administrador del tenant: nombre, email y contraseña.
3. Abrir http://acme.localhost:8080/app y entrar con el administrador del tenant. Desde el panel central también se puede usar la acción **Entrar como admin**.
4. Correr los tests (usan la base `core_saas_testing`, no tocan la de desarrollo):

   ```bash
   docker compose exec backend php artisan test
   ```

Si todo lo anterior funciona, la instalación está completa. El tenant de prueba se puede eliminar y destruir desde el panel central.

## 7. Problemas frecuentes

| Síntoma | Causa y solución |
|---|---|
| `docker: command not found` en WSL | Falta la integración de Docker Desktop con la distro: **Settings → Resources → WSL Integration**. |
| `port is already allocated` al levantar | Otro proceso usa el puerto. Cambiar `APP_PORT`, `DB_FORWARD_PORT`, `REDIS_FORWARD_PORT` o `VITE_PORT` en `.env` y repetir `docker compose up -d`. |
| La app devuelve 500 con `No application encryption key` | Falta el paso `php artisan key:generate`. |
| Al crear un tenant: `Access denied ... to database 'tenant_...'` | La carpeta `docker/data/mysql` ya existía de antes y el script de permisos no corrió. Ejecutarlo a mano: `docker compose exec db sh /docker-entrypoint-initdb.d/01-tenant-grants.sh` |
| Los tests fallan con `Los tests deben correr contra una BD *_testing` | Hay variables `DB_*` definidas en el entorno del contenedor que pisan las de `phpunit.xml`. No agregar `DB_*`, `CACHE_*` ni `SESSION_*` en `environment:` de `docker-compose.yml`. |
| `acme.localhost` no resuelve (Firefox, `curl`, Postman) | Agregar al archivo hosts (`C:\Windows\System32\drivers\etc\hosts` en Windows, `/etc/hosts` en Linux/macOS) una línea por tenant: `127.0.0.1 acme.localhost`. Con `curl` también sirve `-H "Host: acme.localhost:8080" http://127.0.0.1:8080/...`. |
| Archivos creados por los contenedores aparecen como de otro usuario | Tu UID no es 1000: configurar `UID`/`GID` en `.env` (paso 3) y reconstruir con `docker compose up -d --build`. |
| `docker/data` pertenece a root | Es normal: lo crea Docker. Para empezar de cero con la base: `docker compose down` y borrar la carpeta con `sudo rm -rf docker/data` (**se pierden todos los datos**). |

## 8. Conectarse a MySQL y Redis desde el host

Para clientes como DBeaver, TablePlus o RedisInsight:

| | Host | Puerto | Usuario / contraseña |
|---|---|---|---|
| MySQL | `127.0.0.1` | `33061` | `core_saas` / `secret` (o `root` / `root`) |
| Redis | `127.0.0.1` | `63791` | sin contraseña |

Cada tenant tiene su propia base: `tenant_{slug}` (por ejemplo `tenant_acme`).
