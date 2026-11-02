# Desarrollo de core-saas

Fuente de verdad del proyecto: arquitectura, funcionalidades, comandos, convenciones y pendientes. **Si cambias el comportamiento del sistema, actualiza este documento en el mismo commit.**

Para instalar el entorno desde cero ver [install.md](install.md).

**Índice**

1. [Stack](#1-stack)
2. [Arquitectura](#2-arquitectura)
3. [Funcionalidades](#3-funcionalidades)
4. [Comandos frecuentes](#4-comandos-frecuentes)
5. [Recetas](#5-recetas)
6. [Tests](#6-tests)
7. [Convenciones y trampas conocidas](#7-convenciones-y-trampas-conocidas)
8. [Pendientes](#8-pendientes)

---

## 1. Stack

| Componente | Versión | Rol |
|---|---|---|
| PHP | 8.5 (FPM Alpine) | Runtime |
| Laravel | 13 | Framework |
| Filament | 5 (Livewire 4) | Paneles central y de tenant |
| stancl/tenancy | 3.10 | Multi-tenancy con una BD por tenant |
| spatie/laravel-permission | 8.3 | Roles y permisos de los usuarios del tenant |
| bezhansalleh/filament-shield | 4.3 | Pantalla de roles y policies en Filament |
| laravel/sanctum | 4.3 | Tokens de la API de customers |
| spatie/laravel-activitylog | 5.1 | Auditoría (central y por tenant) |
| MySQL | 9.7 LTS | BD central y de tenants |
| Redis | 8.10 | Cache, sesiones y colas |
| Node | 26 LTS | Vite / Tailwind 4 |
| Nginx | stable-alpine | Servidor web |

## 2. Arquitectura

### 2.1 Contenedores

| Servicio | Contenedor | Puerto host | Notas |
|---|---|---|---|
| `backend` | `core-saas-backend` | — | PHP-FPM. Imagen en [docker/php/Dockerfile](docker/php/Dockerfile); corre con el UID/GID del host. |
| `server` | `core-saas-server` | 8080 | Nginx ([docker/nginx/default.conf](docker/nginx/default.conf)); acepta cualquier host (central y tenants). |
| `db` | `core-saas-db` | 33061 | Datos en `docker/data/mysql` (ignorado por git). |
| `redis` | `core-saas-redis` | 63791 | Datos en `docker/data/redis`. |
| `node` | `core-saas-node` | 5173 | Corre `npm install && npm run dev` al arrancar. |
| `queue` | `core-saas-queue` | — | `php artisan queue:listen`: procesa las colas de Redis (correos y notificaciones). Sin él, **ningún correo en cola se envía**. |

La configuración de Laravel sale **solo del `.env`**. No se inyectan variables `DB_*`/`CACHE_*` en `docker-compose.yml` porque pisarían las de `phpunit.xml` (ver [7](#7-convenciones-y-trampas-conocidas)).

### 2.2 Contexto central y contexto tenant

Todo pasa por el mismo Laravel; lo que cambia es el **host** de la petición:

| | Contexto central | Contexto tenant |
|---|---|---|
| Hosts | `localhost`, `127.0.0.1` (`config/tenancy.php` → `central_domains`) | Cualquier otro: `{slug}.localhost` o dominio propio |
| Panel Filament | `/admin` (id `admin`) | `/app` (id `app`) |
| Base de datos | `core_saas` | `tenant_{slug}` |
| Quién entra | Super admins (`App\Models\Admin`, guard `admin`) | Usuarios del backoffice (`App\Models\User`, guard `web`) |
| Rutas | [routes/web.php](routes/web.php) (limitadas a los dominios centrales) + panel admin | [routes/tenant.php](routes/tenant.php) + panel app |

El tenant se identifica con `InitializeTenancyByDomainOrSubdomain`:

- valor **sin punto** en la tabla `domains` (`acme`) → subdominio del primer dominio central: `acme.localhost`;
- valor **con punto** (`acme.test`) → dominio propio completo.

Al identificarlo, stancl cambia la conexión por defecto a la BD del tenant y aísla cache (tags), archivos (`storage/tenant_{slug}`) y colas.

### 2.3 Bases de datos

**Central (`core_saas`)** — migraciones en `database/migrations`:

| Tabla | Contenido |
|---|---|
| `admins` | Super admins del panel central |
| `tenants` | `id` (= slug), `name`, `status`, `status_changed_at`, `data` (JSON de stancl, sin uso por ahora) |
| `domains` | Dominios/subdominios de cada tenant (FK con cascada) |
| `tenant_user_impersonation_tokens` | Tokens de un solo uso de "Entrar como admin" |
| `activity_log` | Auditoría del panel central. `subject_id` es texto (el id de un tenant es su slug) |
| `sessions`, `cache`, `jobs`… | Tablas por defecto de Laravel (sesiones y cache están en Redis) |

**Tenant (`tenant_{slug}`)** — migraciones en `database/migrations/tenant`:

| Tabla | Contenido |
|---|---|
| `users`, `password_reset_tokens` | Usuarios del backoffice del tenant (`users.avatar_path`: ruta del avatar) |
| `roles`, `permissions`, `model_has_*`, `role_has_permissions` | spatie/permission |
| `customers` | Clientes del tenant |
| `personal_access_tokens` | Tokens Sanctum de los customers |
| `activity_log` | Auditoría del tenant |

**Archivos**: cada tenant tiene su carpeta `storage/tenant_{slug}/` (ver [3.6](#36-archivos-y-avatares)).

**Tests**: BD central `core_saas_testing`, tenants con BD y carpeta de storage `tenant_test_*` (prefijo `TENANT_DB_PREFIX` en [phpunit.xml](phpunit.xml)).

**Permisos MySQL** ([docker/mysql/init/01-tenant-grants.sh](docker/mysql/init/01-tenant-grants.sh)): el usuario de la app tiene `ALL` sobre `core_saas`, `core_saas_testing` y `tenant\_%`, nada más. El script solo corre cuando `docker/data/mysql` está vacío.

### 2.4 Modelos

| Modelo | Conexión | Notas |
|---|---|---|
| `Admin` | Central (`CentralConnection`) | Sin roles. Acceso total al panel central. |
| `Tenant` | Central | Extiende el de stancl. El id es el slug y **no se genera** (`getIncrementing() = false`). Helpers `url()`, `host()`, `changeStatus()`, `databaseNameFor()`. Auditoría explícita (sin `LogsActivity`, ver [7](#7-convenciones-y-trampas-conocidas)). |
| `Domain` | Central | Extiende el de stancl para auditar altas/bajas de dominios. |
| `User` | Por defecto (= tenant) | `HasRoles`, `HasAvatar`, `LogsActivity`. Constante `ADMIN_ROLE = 'Administrador'`. |
| `Customer` | Por defecto (= tenant) | `HasApiTokens`, `LogsActivity`. No entra al panel. |
| `Role` | Por defecto (= tenant) | Extiende el de spatie (`config/permission.php`) para auditar el rol y sus cambios de permisos. |

Regla: **modelos del tenant sin conexión explícita; modelos centrales con `CentralConnection`**.

### 2.5 Mapa de código

| Ruta | Qué hay |
|---|---|
| `app/Filament/Admin/` | Panel central: recurso Tenants, acciones de ciclo de vida ([TenantActions.php](app/Filament/Admin/Resources/Tenants/Actions/TenantActions.php)), creación ([CreateTenant.php](app/Filament/Admin/Resources/Tenants/Pages/CreateTenant.php)), destrucción en dos pasos ([InteractsWithTenantDestruction.php](app/Filament/Admin/Resources/Tenants/Pages/Concerns/InteractsWithTenantDestruction.php)) |
| `app/Filament/App/` | Panel del tenant: recursos Users, Customers y Auditoría; perfil ([EditProfile.php](app/Filament/App/Pages/Auth/EditProfile.php)). La pantalla de Roles la aporta Shield |
| `app/Filament/Shared/` | Tabla y detalle de auditoría compartidos por los dos paneles |
| `app/Providers/Filament/` | Configuración de los dos paneles |
| [app/Providers/TenancyServiceProvider.php](app/Providers/TenancyServiceProvider.php) | Pipeline al crear/borrar tenants, cache de permisos por tenant, contexto de logs y URL del disco `public` |
| [app/Providers/AppServiceProvider.php](app/Providers/AppServiceProvider.php) | Bypass del rol Administrador (`Gate::before`), policy de auditoría, auditoría de logins y rate limiter de la API |
| [app/Support/RoleAssignment.php](app/Support/RoleAssignment.php) | Reglas anti-escalada de privilegios al asignar roles |
| [app/Support/Audit.php](app/Support/Audit.php) | Etiquetas de auditoría (módulos, eventos, sujetos) y registro de cambios de roles |
| [app/Http/Controllers/TenantMediaController.php](app/Http/Controllers/TenantMediaController.php) | Sirve los archivos públicos del tenant en `/media` |
| [app/Http/Controllers/ImpersonationController.php](app/Http/Controllers/ImpersonationController.php) | Consume el token de "Entrar como admin" y lo audita en el tenant |
| [app/Jobs/DeleteTenantStorage.php](app/Jobs/DeleteTenantStorage.php) | Borra la carpeta de archivos del tenant al destruirlo |
| `app/Policies/` | Policies generadas por Shield (UserPolicy ajustada a mano) |
| [app/Http/Middleware/EnsureTenantIsActive.php](app/Http/Middleware/EnsureTenantIsActive.php) | 403 si el tenant no está activo |
| `app/Http/Controllers/Api/V1/` | API de customers |
| [database/seeders/TenantRolesSeeder.php](database/seeders/TenantRolesSeeder.php) | Permisos y roles base de cada tenant |
| `tests/Concerns/InteractsWithTenants.php` | Helpers de tests con tenants reales |

## 3. Funcionalidades

### 3.1 Panel central (`http://localhost:8080/admin`)

Solo super admins (guard `admin`). Recurso **Tenants**:

| Acción | Qué hace técnicamente |
|---|---|
| **Crear** | Valida nombre, slug (`^[a-z][a-z0-9-]*$`, máx. 40, único, y que no exista ya la BD `tenant_{slug}`), dominios (únicos, no centrales) y datos del admin. `Tenant::create()` dispara el pipeline de stancl: `CreateDatabase` → `MigrateDatabase` → `SeedDatabase` (`TenantDatabaseSeeder`). Luego crea el `User` admin con rol Administrador. Si eso falla, borra el tenant y su BD. Sin dominios, crea uno igual al slug. La página **no usa transacción** porque `CREATE DATABASE` hace commit implícito. |
| **Editar** | Nombre y dominios. El slug no se puede cambiar. |
| **Entrar como admin** | Genera un token de impersonación (stancl `UserImpersonation`, 60 s, un solo uso) para el Administrador más antiguo del tenant y redirige a `{tenant}/impersonate/{token}`, que hace login en el guard `web` y redirige a `/app`. Solo existe en el panel central. Mientras dura, el panel del tenant muestra una **barra fija** ("Estás en X como Y, ingresaste desde el panel central como Z") con el botón **Volver al panel central**: cierra la sesión del tenant y redirige a la ficha del tenant en `/admin`, cuya sesión sigue abierta (otra cookie, otro dominio). |
| **Abrir login** | Abre `/app/login` del tenant en otra pestaña. |
| **Restablecer contraseña del admin** | Elige un usuario con rol Administrador del tenant (el select solo ofrece administradores y la acción aborta con 403 si se fuerza otro) y: **enviar enlace por email** (recomendado; mismo flujo que "¿Olvidaste tu contraseña?", broker `users` en la BD del tenant, URL firmada en el dominio del tenant, vence en `auth.passwords.users.expire` minutos, 1 envío por minuto) o **definir contraseña** (se cambia en la BD del tenant, rota `remember_token`, Filament cierra sus otras sesiones y se le avisa por email sin la contraseña). La contraseña nunca pasa por la BD central. Se audita en ambas BD (`admin_password_reset`). Código: [TenantAdminAccess](app/Support/TenantAdminAccess.php). |
| **Suspender / Reactivar** | Cambia `status`. Suspendido → 403 en panel, rutas y API. Datos intactos. |
| **Eliminar** | Confirmación Sí/No. `status = deleted`: sin acceso, BD conservada. Reversible con **Restaurar**. |
| **Destruir** | Solo para eliminados. Paso 1: "¿Desea destruir toda la data?" Sí/No. Paso 2 (`replaceMountedAction('confirmDestroy')`): escribir el slug. `Tenant::delete()` → stancl `DeleteDatabase`; dominios y tokens caen en cascada. Irreversible. |

Estados (`App\Enums\TenantStatus`): `active`, `suspended`, `deleted`.

### 3.2 Panel del tenant (`http://{slug}.localhost:8080/app`)

Usuarios del backoffice (guard `web`, tabla `users` de la BD del tenant). Middlewares del panel, **persistentes** para que también apliquen a las peticiones de Livewire: identificación del tenant → bloqueo de dominios centrales → `EnsureTenantIsActive`.

| Recurso | Funcionalidad |
|---|---|
| **Usuarios** | Listado, detalle, alta, edición y borrado con roles y avatar. Contraseña opcional en edición. |
| **Clientes** | Listado, detalle, alta, edición y borrado de customers (nombre, email, teléfono, contraseña, activo). |
| **Roles** (Shield, grupo Administración) | Crear roles y marcar permisos por recurso. |
| **Auditoría** (grupo Administración) | Solo lectura. Ver [3.5](#35-auditoría). |
| **¿Olvidaste tu contraseña?** (login) | Envía el enlace de restablecimiento por correo (`->passwordReset()` del panel). Al completarlo se audita `password_reset`. |
| **Perfil** (menú de usuario) | Cada usuario edita nombre, email, contraseña y avatar sin necesitar permisos sobre Usuarios. |

### 3.3 Roles y permisos

- Permisos por recurso con formato `Acción:Modelo`, derivados por Shield de los recursos del panel `app` (19 en total):
  - `User`, `Customer`: `ViewAny`, `View`, `Create`, `Update`, `Delete`, `DeleteAny` (métodos por defecto, `config/filament-shield.php` → `policies.methods`);
  - `Role`: `ViewAny`, `View`, `Create`, `Update`, `Delete`; `Activity` (auditoría): `ViewAny`, `View` (definidos en `resources.manage`, con `policies.merge = false`).
- Roles base que crea [TenantRolesSeeder](database/seeders/TenantRolesSeeder.php) (`BASE_ROLES`):

  | Rol | Permisos |
  |---|---|
  | Administrador | Todos, vía `Gate::before` (no tiene permisos asignados) |
  | Colaborador | `ViewAny:User`, `View:User`, `Create:User`, `Update:User` |
  | Validador | `ViewAny:User`, `View:User` |

- El Administrador del tenant puede crear roles y cambiar permisos. El seeder es idempotente y **solo asigna permisos a un rol base cuando lo crea**: re-ejecutarlo no pisa cambios del tenant.
- **Bypass del Administrador** ([AppServiceProvider](app/Providers/AppServiceProvider.php)): propio, no el de Shield (`define_via_gate = false`), porque el de Shield llama `hasRole()` sobre cualquier usuario y rompería el panel central. Además **nadie** puede editar ni borrar el rol Administrador.
- **Anti-escalada** ([RoleAssignment](app/Support/RoleAssignment.php) + [UserPolicy](app/Policies/UserPolicy.php)):
  - un usuario solo puede asignar roles cuyos permisos ya tiene (el Administrador, todos);
  - solo puede editar o borrar usuarios cuyos roles podría asignar;
  - nadie cambia sus propios roles ni se borra a sí mismo.
- **Cache por tenant**: spatie/permission guarda la cache en un store sin tags. En `TenancyBootstrapped` se cambia la clave a `spatie.permission.cache.tenant.{slug}` y se vacía la colección en memoria; en `RevertedToCentralContext` se restaura. Lo cubre un test.

### 3.4 API de customers

Base: `http://{slug}.localhost:8080/api/v1` (rutas en [routes/tenant.php](routes/tenant.php), controlador [CustomerAuthController](app/Http/Controllers/Api/V1/CustomerAuthController.php)). Enviar siempre `Accept: application/json`.

| Método | Ruta | Auth | Respuesta |
|---|---|---|---|
| `POST` | `/auth/login` | — | `{ token, token_type: "Bearer", customer }`. Body: `email`, `password`, `device_name` (opcional). |
| `GET` | `/auth/me` | Bearer | `{ data: customer }` |
| `POST` | `/auth/logout` | Bearer | `204`; revoca el token actual |

- Guard `customer` (driver `sanctum`, provider `customers`). `config/sanctum.php` → `guard = []`: sin auth stateful de SPA, solo tokens.
- Los tokens viven en la BD del tenant: un token de `acme` no sirve en `beta` aunque tengan el mismo id (se valida el hash).
- Credenciales erróneas, email inexistente y customer inactivo devuelven el mismo `422` en `email`.
- Rate limit `customer-login`: 5 intentos/minuto por tenant + email + IP → `429`.
- Desactivar un customer o cambiarle la contraseña revoca todos sus tokens (`Customer::booted()`).
- Tenant suspendido o eliminado → `403`. Dominio central → `404`.

Los logins, logins fallidos y logouts de la API quedan en la auditoría del tenant (guard `customer`).

Ejemplo:

```bash
curl -X POST http://acme.localhost:8080/api/v1/auth/login \
  -H "Accept: application/json" \
  -d email=cliente@acme.test -d password=secreto123 -d device_name=postman

curl http://acme.localhost:8080/api/v1/auth/me \
  -H "Accept: application/json" -H "Authorization: Bearer {token}"
```

### 3.5 Auditoría

spatie/laravel-activitylog. Cada registro se guarda en la tabla `activity_log` **de la BD del contexto actual**: lo que pasa en el panel central va a la central y lo que pasa en un tenant, a la BD de ese tenant. Quién lo hizo (`causer`) sale del guard activo; en un tenant puede ser un `User` o un `Admin` central (impersonación, alta del tenant).

| Módulo (`log_name`) | Dónde | Qué se registra | Cómo |
|---|---|---|---|
| `tenant` | Central | Alta, cambio de nombre, cambio de estado (`Estado: Activo → Suspendido`), destrucción; alta/baja de dominios | Registros explícitos en `CreateTenant`, `EditTenant`, `Tenant::changeStatus()`, `confirmDestroy`; `LogsActivity` en `Domain` |
| `auth` | Central y tenant | Login, login fallido (con email), logout de los paneles y de la API; "Entrar como admin" | Listeners `Login`/`Failed`/`Logout` en `AppServiceProvider`; `CustomerAuthController`; `TenantActions::impersonate` + `ImpersonationController` |
| `user` | Tenant | Alta/cambios/baja de usuarios (nombre, email, avatar; **nunca la contraseña**) y cambios de roles (agregados/quitados) | `LogsActivity` en `User`; `Audit::rolesChanged()` en las páginas de usuario y en el alta del tenant |
| `role` | Tenant | Alta/cambios/baja de roles y cambios de permisos (agregados/quitados) | `LogsActivity` en `Role` + `Role::syncPermissions()` |
| `customer` | Tenant | Alta/cambios/baja de clientes (nombre, email, teléfono, activo) | `LogsActivity` en `Customer` |

- **Impersonación**: el panel central registra qué super admin entró, el hash del token y la URL de regreso. Al consumir el token ([ImpersonationController](app/Http/Controllers/ImpersonationController.php)), el tenant busca ese registro en la BD central, deja un "Acceso desde el panel central" cuyo `causer` es el `Admin` y guarda en la sesión (`impersonation`) quién es y a dónde volver; de ahí sale la barra (hook `BODY_START` en `AppPanelProvider`, vista `resources/views/filament/app/impersonation-banner.blade.php`). Al salir —botón de la barra o "Cerrar sesión" de Filament— el listener de `Logout` registra "Fin del acceso desde el panel central", también a nombre del `Admin`. Ni la entrada ni la salida cuentan como login/logout del usuario suplantado.
- **Paquetes de impersonación evaluados** (`stechstudio/filament-impersonate`, `lab404/laravel-impersonate`): no sirven aquí; suplantan dentro de la misma app, sesión y BD, y este caso cruza BD, guard, dominio y cookie.
- **Pantallas**: "Auditoría" en ambos paneles (`/admin/auditoria`, `/app/auditoria`), solo lectura, con filtros por módulo, evento y fechas, y detalle con valores antes/después. En el tenant requiere `ViewAny:Activity` (por defecto solo el Administrador); en el central la ven todos los super admins ([ActivityPolicy](app/Policies/ActivityPolicy.php)).
- **Retención**: `config/activitylog.php` → `clean_after_days` (365). La limpieza (`activitylog:clean`) aún no está programada (ver pendientes).

### 3.6 Archivos y avatares

**Dónde se guardan**: stancl (`FilesystemTenancyBootstrapper`) cambia la raíz de `storage_path()` y de los discos `local` y `public` mientras hay un tenant inicializado:

| Disco | Central | Dentro del tenant `acme` |
|---|---|---|
| `local` | `storage/app/private` | `storage/tenant_acme/app/private/` |
| `public` | `storage/app/public` | `storage/tenant_acme/app/public/` |
| `livewire-tmp` | `storage/app/livewire-tmp` | igual (no se separa, ver abajo) |

El código usa siempre `Storage::disk('public')` sin pensar en el tenant: la separación la hace la tenancy. El prefijo de carpeta es el mismo que el de la BD (`TENANT_DB_PREFIX`).

**Cómo se sirven**: los archivos del tenant no están bajo `public/storage`, así que se sirven por la ruta `GET /media/{path}` del dominio del tenant ([TenantMediaController](app/Http/Controllers/TenantMediaController.php)): identifica el tenant, exige que esté activo, lee solo dentro de la raíz del disco `public` de ese tenant (bloquea `../`) y responde con `nosniff`. Al inicializar un tenant, `filesystems.disks.public.url` pasa a `http://{tenant}/media`, por eso `Storage::disk('public')->url($path)` devuelve la URL correcta (Filament la usa para mostrar imágenes). En el dominio central `/media` da 404.

**Subidas de Livewire/Filament**: el archivo temporal se guarda en el disco `livewire-tmp` (raíz fija, común a todos) porque la ruta de subida de Livewire no inicializa la tenancy. Por el mismo motivo su rate limit (`throttle:livewire-upload`) no usa el usuario de la sesión. Al guardar el formulario —petición que sí tiene tenancy— Filament lo mueve al disco `public` del tenant.

**Avatar del usuario**: campo [AvatarUpload](app/Filament/App/Resources/Users/Schemas/Components/AvatarUpload.php) en el formulario de usuarios y en el perfil. JPG, PNG o WebP (sin SVG, que puede tener scripts), máx. 2 MB, en `avatars/`. `User::getFilamentAvatarUrl()` lo muestra en el panel. Al reemplazarlo, quitarlo o borrar el usuario se elimina el archivo anterior.

**Al destruir un tenant** se borra su carpeta `storage/tenant_{slug}` ([DeleteTenantStorage](app/Jobs/DeleteTenantStorage.php), después de borrar la BD).

**Archivos privados** (documentos, adjuntos): usar el disco `local` (también separado por tenant) y servirlos con una ruta propia que verifique permisos, nunca por `/media`.

### 3.7 Logs y errores con contexto de tenant

Al inicializar un tenant se agrega `tenant` al [Context](https://laravel.com/docs/context) de Laravel y se quita al volver al contexto central. Laravel lo adjunta a cada línea de log y a los errores reportados, y lo propaga a los jobs encolados:

```
[2026-10-29 22:30:00] local.ERROR: fallo dentro del tenant {"exception":...} {"tenant":"acme"}
```

Si se integra Sentry o Flare (pendiente), reciben el mismo contexto.

## 4. Comandos frecuentes

Todos los comandos de PHP corren **dentro del contenedor**. Conviene un alias:

```bash
alias art="docker compose exec backend php artisan"
```

### Docker

| Comando | Para qué |
|---|---|
| `docker compose up -d` | Levantar todo |
| `docker compose up -d --build` | Levantar reconstruyendo la imagen PHP (tras cambiar el Dockerfile o `php.ini`) |
| `docker compose down` | Parar y borrar contenedores (los datos de `docker/data` se conservan) |
| `docker compose ps` | Estado y salud de los servicios |
| `docker compose logs -f backend` | Logs (también `server`, `db`, `redis`, `node`, `queue`) |
| `docker compose logs -f queue` | Ver los jobs y correos que se procesan (`RUNNING` / `DONE` / `FAIL`) |
| `docker compose exec backend sh` | Shell en el contenedor PHP |

### Laravel / Composer

| Comando | Para qué |
|---|---|
| `docker compose exec backend composer install` | Dependencias PHP |
| `docker compose exec backend composer require vendor/paquete` | Agregar un paquete |
| `art migrate` | Migraciones de la **BD central** |
| `art tenants:migrate` | Migraciones de **todas las BD de tenants** (`--tenants=acme` para uno) |
| `art tenants:list` | Listar tenants y dominios |
| `art tenants:run "comando" --tenants=acme` | Correr un comando artisan dentro de un tenant |
| `art tenants:seed --class="Database\Seeders\TenantRolesSeeder"` | Sincronizar permisos en todos los tenants (ver receta 5.1) |
| `art core:make-superadmin` | Crear o actualizar un super admin (`--name --email --password` para no interactivo) |
| `art optimize:clear` | Limpiar caches de config, rutas y vistas |
| `art queue:failed` / `art queue:retry all` | Ver y reintentar jobs fallidos (p. ej. correos con SMTP mal configurado) |
| `art filament:assets` | Republicar los assets de Filament (tras actualizar Filament) |
| `art tinker` | Consola. Dentro de un tenant: `tenancy()->initialize(App\Models\Tenant::find('acme'));` |
| `art activitylog:clean` | Borra registros de auditoría más viejos que `clean_after_days` (BD central; en tenants: `art tenants:run activitylog:clean`) |

### Calidad

| Comando | Para qué |
|---|---|
| `art test` | Toda la suite (contra `core_saas_testing`) |
| `art test --filter=CustomerApiTest` | Un archivo o test |
| `docker compose exec backend vendor/bin/pint` | Formatear código (estilo Laravel). Correr antes de cada commit. |

### Frontend

| Comando | Para qué |
|---|---|
| `docker compose logs -f node` | Ver Vite (HMR en `localhost:5173`) |
| `docker compose exec node npm run build` | Compilar assets de producción en `public/build` |
| `docker compose exec node npm install paquete` | Agregar una dependencia npm |

## 5. Recetas

### 5.1 Nuevo recurso en el panel del tenant

Ejemplo: `Product`, con datos en la BD de cada tenant.

1. Migración **en la carpeta de tenant**:
   ```bash
   art make:migration create_products_table --path=database/migrations/tenant
   ```
2. Modelo **sin conexión explícita** (usa la del tenant):
   ```bash
   art make:model Product
   ```
3. Recurso Filament en el panel `app` (queda en `app/Filament/App/Resources`):
   ```bash
   art make:filament-resource Product --panel=app --view
   ```
4. Policy con Shield, **solo policies** (con permisos los escribiría en la BD central):
   ```bash
   art shield:generate --resource=ProductResource --option=policies --panel=app
   ```
5. Aplicar la migración y crear los permisos nuevos en los tenants existentes (los tenants nuevos ya los reciben al crearse):
   ```bash
   art tenants:migrate
   art tenants:seed --class="Database\Seeders\TenantRolesSeeder"
   ```
6. Si algún rol base debe tener los permisos nuevos, agregarlos en `TenantRolesSeeder::BASE_ROLES`. **Solo aplica a tenants nuevos**: en los existentes los asigna el Administrador desde Roles (o una migración de datos si se quiere forzar).
7. Auditoría: agregar `LogsActivity` al modelo con `logOnly([...])` (nunca contraseñas ni tokens), `useLogName(...)` y `setDescriptionForEvent(fn ($e) => Audit::modelDescription('Producto', $e))`; registrar el módulo y el sujeto en [Audit](app/Support/Audit.php) (`LOGS`, `SUBJECTS`).
8. Archivos: `Storage::disk('public')` para públicos (se sirven por `/media`), `Storage::disk('local')` para privados.
9. Labels en español (`$modelLabel`, `$pluralModelLabel`, `->label()`), tests en `tests/Feature` y actualizar este documento (secciones 3 y 8).

### 5.2 Nuevo recurso en el panel central

1. Migración en `database/migrations` y modelo con `use Stancl\Tenancy\Database\Concerns\CentralConnection;`.
2. `art make:filament-resource Nombre --panel=admin` (queda en `app/Filament/Admin/Resources`).
3. Sin Shield ni permisos: el panel central no usa roles.
4. `art migrate`.

### 5.3 Nueva migración sobre tablas existentes

- Tabla central → `art make:migration ...` y `art migrate`.
- Tabla de tenant → `art make:migration ... --path=database/migrations/tenant` y `art tenants:migrate`.

Las migraciones de tenant corren una vez por tenant: deben ser compatibles hacia atrás y no asumir datos.

### 5.4 Nuevo endpoint en la API de customers

1. Ruta en `routes/tenant.php`, dentro del grupo `api/v1` (hereda tenancy y `EnsureTenantIsActive`). Si requiere login: dentro de `auth:customer`.
2. Controlador en `app/Http/Controllers/Api/V1`, respuesta con un `JsonResource` en `app/Http/Resources`.
3. El customer autenticado: `$request->user('customer')`.
4. Test en `tests/Feature` usando el helper `api()` de `CustomerApiTest` como referencia.

### 5.5 Nuevo rol base

Agregarlo a `TenantRolesSeeder::BASE_ROLES` con sus permisos y correr `art tenants:seed --class="Database\Seeders\TenantRolesSeeder"`. Si el rol ya existe en un tenant, no se tocan sus permisos.

### 5.6 Cambiar puertos

Definir en `.env` `APP_PORT`, `DB_FORWARD_PORT`, `REDIS_FORWARD_PORT` o `VITE_PORT` y `docker compose up -d`. Si cambia `APP_PORT`, actualizar también `APP_URL`: de ahí sale el puerto de las URLs de los tenants.

## 6. Tests

- Corren contra **MySQL real**: `core_saas_testing` + BD `tenant_test_*` que se crean y destruyen en cada test. `phpunit.xml` fuerza (`force="true"`) BD, cache `array`, sesión `array` y cola `sync`.
- [tests/TestCase.php](tests/TestCase.php) aborta si la BD no termina en `_testing`, para no tocar nunca la de desarrollo.
- Usar `DatabaseMigrations`, **no** `RefreshDatabase`: `CREATE DATABASE` hace commit implícito y rompe la transacción.
- Trait `Tests\Concerns\InteractsWithTenants`:
  - `createTenant('acme')` — crea el tenant por el formulario real (BD, roles y admin);
  - `actingInTenant($tenant, 'Colaborador')` — entra al tenant y al panel `app` con un usuario nuevo de ese rol (o el admin si se omite);
  - `backToCentral()` — vuelve al contexto central;
  - `destroyTenants()` — llamarlo en `tearDown()` **antes** de `parent::tearDown()`.
- Entre peticiones HTTP a distintos tenants: `tenancy()->end()` y `$this->app['auth']->forgetGuards()`.

| Archivo | Cubre |
|---|---|
| `TenantLifecycleTest` | Alta, validaciones, dominios, aislamiento de paneles, impersonación, suspender/eliminar/restaurar, destrucción en dos pasos |
| `TenantPermissionsTest` | Roles base, Administrador, Validador, anti-escalada del Colaborador, roles personalizados, cache de permisos por tenant |
| `CustomerApiTest` | Login/me/logout, credenciales inválidas, aislamiento de tokens, revocación, tenant inactivo, rate limit |
| `AuditTest` | Auditoría central del ciclo de vida, impersonación en ambas BD, acciones del tenant (usuarios, roles, permisos, clientes), logins, permiso de la pantalla, contexto de logs |
| `TenantAdminPasswordResetTest` | Enlace desde el panel central (dominio del tenant, firma, auditoría sin datos sensibles), completar el restablecimiento, throttling, contraseña manual (aviso, sesiones), solo administradores, "¿Olvidaste tu contraseña?" del tenant |
| `ImpersonationBannerTest` | Barra visible solo al entrar desde el panel central, "Volver al panel central" (redirección, cierre de sesión, auditoría), logout de Filament auditado como fin del acceso |
| `AvatarTest` | Subida real por HTTP a la ruta de Livewire con sesión, subida desde el perfil a la carpeta del tenant, URL `/media`, aislamiento entre tenants y path traversal, tipos rechazados, borrado de archivos y de la carpeta al destruir |

## 7. Convenciones y trampas conocidas

**Convenciones**

- Textos de interfaz y comentarios en español; código (clases, métodos, variables) en inglés.
- Estructura de recursos de Filament 5: `Resource` + `Pages/` + `Schemas/` (Form, Infolist) + `Tables/`.
- Pint antes de cada commit. Tests para cada funcionalidad nueva.
- Este archivo se actualiza junto con el código.

**Trampas**

| Trampa | Detalle |
|---|---|
| Variables de entorno en `docker-compose.yml` | No agregar `DB_*`, `CACHE_*`, `SESSION_*` en `environment:` del backend: tienen prioridad sobre `phpunit.xml` y los tests correrían contra la BD de desarrollo. |
| Transacciones al crear tenants | `CREATE DATABASE` hace commit implícito. Cualquier página o job que cree tenants debe ir sin transacción. |
| `shield:generate` | Usar siempre `--resource=XResource --option=policies --panel=app`. Con permisos los escribe en la BD central (los de tenant los crea `TenantRolesSeeder`). Con `--all` regenera todas las policies y **pisa `UserPolicy`**, que tiene reglas anti-escalada hechas a mano. |
| `shield:super-admin` | No usarlo: asigna roles en la BD actual (la central). El admin del tenant recibe su rol al crearse el tenant. |
| URLs del panel central desde consola o jobs | El panel admin está en dos dominios: fuera de una petición HTTP hay que llamar `Filament::currentDomain('localhost')` antes de generar URLs (`LogicException` si no). |
| Middlewares del panel de tenant | Deben ir con `isPersistent: true`; si no, las peticiones de Livewire (`/livewire-*/update`) se ejecutarían contra la BD central. |
| `asset_helper_tenancy` | Está en `false`: los assets de Filament/Vite son compartidos. Activarlo rompe CSS/JS en los tenants. |
| `LogsActivity` en `Tenant` | No usarlo: stancl asocia sus eventos (`TenantCreated`, `TenantDeleted`) a `created`/`deleted` y, como sus listeners devuelven valores, Laravel no dispara el evento Eloquent que usa el trait. La auditoría del tenant se registra explícitamente. |
| Rutas globales sin tenancy | Las rutas que no identifican al tenant (subida y preview de Livewire, assets) **no deben resolver el usuario de la sesión**: lo buscarían en la BD central (`Table 'core_saas.users' doesn't exist`). Ej.: el `throttle` por defecto llama `$request->user()`; por eso la subida usa el limitador `livewire-upload` (host + IP, `AppServiceProvider`). |
| Tenants existentes tras un pull | Si llegan migraciones o permisos nuevos de tenant, correr `art tenants:migrate` y `art tenants:seed --class="Database\Seeders\TenantRolesSeeder"`; si no, aparecen errores como `Table 'tenant_x.activity_log' doesn't exist`. |
| Correos y colas | Las notificaciones de Filament y las propias implementan `ShouldQueue`: van a Redis y las envía el contenedor `queue`. Si no está corriendo, no sale ningún correo (sin error visible). En tests la cola es `sync` y se usa `Notification::fake()`. |
| URLs de tenant generadas desde el central | Al generar una URL del panel `app` fuera de una petición del tenant (p. ej. el enlace de restablecimiento desde el panel central), forzar la raíz con `URL::forceRootUrl($tenant->url('/'))` y el panel con `Filament::setCurrentPanel('app')`, y restaurarlos después (ver `TenantAdminAccess`). |
| Disco temporal de Livewire | Debe seguir siendo `livewire-tmp` (`config/livewire.php`): si fuera `local`, la subida (sin tenancy) y el guardado (con tenancy) usarían carpetas distintas. |
| Shield `policies.merge` | En `false`: los recursos de `resources.manage` usan solo sus métodos. Si se pasa a `true`, la auditoría ganaría permisos de crear/editar sin sentido. |
| Prefijo de tests | `TENANT_DB_PREFIX=tenant_test_` separa BD **y** carpetas de storage de los tests: no cambiarlo en `phpunit.xml`, o un test podría borrar archivos de un tenant de desarrollo. |
| Orden de `central_domains` | El primero (`localhost`) es la base de los subdominios de tenants. |
| `UID` en bash | Es una variable de solo lectura y no se exporta: Compose usa 1000 salvo que `UID`/`GID` estén en `.env`. |
| `docker/data` | Lo crea Docker como root. El script de permisos de MySQL solo corre con la carpeta vacía; si ya existía, ejecutarlo a mano (ver install.md). |
| `CLAUDE.md` / `AGENTS.md` | Traen las instrucciones genéricas de Laravel Boost (instalar PHP local y Boost). En este proyecto PHP corre en Docker: no instalar PHP en el host. |

## 8. Pendientes

Funcionalidad acordada para más adelante o detectada durante el desarrollo:

| Tema | Detalle |
|---|---|
| Idioma | `APP_LOCALE=es` en `.env`/`.env.example` y activar `localization` de Shield para mostrar los permisos en español. Publicar traducciones de validación (`lang/es`). |
| `.env.example` | Documentar `DB_FORWARD_PORT=33061`, `REDIS_FORWARD_PORT=63791`, `APP_PORT`, `VITE_PORT` y la configuración MySQL/Redis del paso 3 de install.md. |
| Colas | Contenedor `core-saas-queue` hecho (correos). Falta: pipeline de creación/destrucción de tenants en cola (`shouldBeQueued(true)`), Horizon para monitoreo y `queue:work` + supervisor en producción. |
| Scheduler | Contenedor o proceso para `schedule:work`. |
| Correo | Configurado por `.env` (Mailtrap en desarrollo). Remitente por tenant (nombre/marca) pendiente con B-03. |
| Seguridad | 2FA para super admins (Filament lo trae: `->multiFactorAuthentication([...])` en el panel), más adelante para usuarios de tenant. |
| Errores | Integrar Sentry o Flare (ya reciben el contexto `tenant`). |
| Auditoría | Programar `activitylog:clean` (central y `tenants:run`) cuando exista el scheduler; exportar auditoría. |
| Archivos | Cuotas por tenant, S3 en producción (mismo esquema de prefijo por tenant), avatar de customers por la API. |
| Recuperación de contraseña | Backoffice: hecho. Customers: endpoints en la API (`forgot-password` / `reset-password`). |
| API de customers | Registro propio (hoy solo los crea el tenant), cambio de contraseña, documentación OpenAPI, expiración de tokens (`sanctum.expiration` es `null`). |
| Roles del panel central | Hoy todos los super admins tienen acceso total. |
| Planes, módulos y cobro por tenant | Fuera de alcance por ahora. |
| Configuración por tenant | Logo, colores, zona horaria, etc. |
| Backups | Respaldo y restauración por BD de tenant. |
| Auditoría | Registro de acciones (alta/baja de tenants, cambios de roles). |
| Producción | HTTPS, dominios reales, `SESSION_DOMAIN`, `APP_DEBUG=false`, `TrustProxies`, despliegue y migraciones de tenants en el deploy. |
| CI | Pipeline con tests, Pint y Larastan. |

**Hoja de ruta acordada** (orden sugerido): 1) correo, colas y recuperación de contraseña; 2) 2FA y Sentry/Flare (auditoría y contexto de logs ya hechos); 3) CI y backups; 4) planes con límites y funciones por tenant (Pennant); 5) cobro (Cashier) cuando el modelo comercial esté definido. Decisiones abiertas: alta self-service de tenants, proveedor de cobro y moneda, plataforma de despliegue.

### 8.1 Backlog priorizado (ideas de `tomatophp/filament-tenancy` y propuestas propias)

Evaluado el paquete [tomatophp/filament-tenancy](https://github.com/tomatophp/filament-tenancy) 5.0.0 (2026-11): **no se instala** —reemplazaría recurso, modelo, login e impersonación propios por versiones más básicas, con problemas de seguridad (impersona siempre al usuario id 1, la ruta `/login/url` sobrescribe nombre/email/contraseña del usuario antes de validar el token, duplica la contraseña del dueño en la BD central, `is_active` no bloquea nada, borrar destruye la BD sin confirmaciones)—. Se toman solo ideas.

| ID | Mejora | Origen | Valor | Esfuerzo | Depende de |
|---|---|---|---|---|---|
| B-01 | ✅ Restablecer la contraseña del admin del tenant desde el panel central (hecho, ver 3.1) | tomatophp | Alto | S | — |
| B-02 | Zona horaria por tenant (BD en UTC) | Propia | Alto | M | — |
| B-03 | Marca por tenant: logo, colores, favicon | tomatophp (colores) + propia | Alto | M | — |
| B-04 | Datos de contacto del tenant (email y teléfono del responsable) | tomatophp | Medio | S | — |
| B-05 | Página de detalle del tenant en el panel central | tomatophp | Medio | S | — |
| B-06 | Traducciones (`lang/es`, `lang/en`) e idioma por tenant | tomatophp | Medio | M | B-02 (misma pantalla de configuración) |
| B-07 | "¿Cuál es mi espacio?" en el dominio central | tomatophp | Medio | S | Correo |
| B-08 | Alta self-service de tenants con verificación | tomatophp | Según negocio | L | Correo, colas, decisión abierta |
| B-09 | Registro/login social (Socialite) | tomatophp | Bajo | M | B-08 |

Descartado de tomatophp: modo base de datos única (se eligió una BD por tenant), sincronizar la contraseña del dueño con la BD central, códigos de verificación por webhook de Discord, el interruptor "activo" (ya cubierto por Suspender/Reactivar) y los helpers de modelo genéricos (`HasTeam`, `HasDocStatus`…).

#### B-01 Restablecer la contraseña del admin del tenant

- Acción "Restablecer contraseña" en la tabla y la ficha del tenant (panel central): elige un usuario con rol Administrador del tenant y pide la nueva contraseña con confirmación.
- Se escribe con `$tenant->run(...)` en la BD del tenant. **Nunca** se guarda una copia en la BD central.
- Revoca sus sesiones (`remember_token` nuevo) y se audita en ambas BD (`password_reset_by_admin`, sin el valor).
- Mientras no exista correo, es la única recuperación posible si el admin pierde su contraseña.

#### B-02 Zona horaria por tenant

Sí es posible y es la práctica recomendada: **guardar todo en UTC y convertir solo al mostrar o al recibir datos.**

- **Almacenamiento**: `config/app.php` ya usa `timezone = UTC` y no se cambia nunca en tiempo de ejecución (si se cambiara, se guardarían horas locales mezcladas). Las columnas `timestamp`/`datetime` quedan en UTC.
- **Configuración**: columna `timezone` en `tenants` (central), con nombres IANA (`America/Bogota`, `America/Argentina/Buenos_Aires`), **no** offsets fijos tipo `GMT-5`: los nombres IANA aplican el horario de verano solos. En el selector se muestra la etiqueta con el offset actual: `(GMT-05:00) America/Bogota`. Validación con `timezone_identifiers_list()`. Valor por defecto configurable (`TENANT_DEFAULT_TIMEZONE`).
- **Aplicación en el panel**: en `TenancyBootstrapped`, `FilamentTimezone::set($tenant->timezone)`. Desde ahí todas las columnas, entradas de detalle y selectores `DateTimePicker` de Filament muestran la hora local del tenant y convierten a UTC al guardar, sin tocar cada recurso.
- **Fuera de Filament**:
  - API: seguir devolviendo ISO 8601 en UTC (`...Z`) y exponer la zona del tenant (por ejemplo en `/api/v1/auth/me` o un `/api/v1/tenant`) para que la app cliente convierta.
  - Correos, PDF y exportaciones: helper o macro de Carbon (`->inTenantTimezone()`) para formatear.
- **Cuidados**:
  - Filtros por fecha: "Desde/Hasta" debe convertir el rango local a UTC antes de consultar (hoy el filtro de Auditoría compara fechas UTC).
  - Campos de solo fecha (nacimiento, vencimientos) van en columnas `date` y **no** se convierten.
  - Tareas programadas "a las 8:00 del tenant": calcular con su zona.
- **Extensión futura**: zona por usuario (`users.timezone`) con prioridad usuario > tenant > por defecto.
- **Quién la edita**: el super admin en el formulario del tenant y el Administrador del tenant en "Configuración" (B-03 comparte esa pantalla).

#### B-03 Marca por tenant

- **Qué se personaliza**: nombre visible, color primario y secundario, logo claro, logo para modo oscuro (opcional) y favicon.
- **Dónde se guarda**: atributos del tenant en la BD central (virtuales en la columna `data` de stancl, sin migración por cada ajuste nuevo). Los archivos van en el disco `public` del tenant (`branding/`) y se sirven por `/media`, que ya existe.
- **Cómo se aplica** (todo por request, ya soportado por Filament 5):
  - `->brandLogo(fn () => ...)`, `->darkModeBrandLogo(...)` y `->favicon(...)` en `AppPanelProvider`;
  - `FilamentColor::register(['primary' => Color::hex($tenant->primary_color)])` en `TenancyBootstrapped` (es la idea del `ApplyPanelColorsMiddleware` de tomatophp, que en el paquete quedó sin registrar);
  - `->brandName()` ya usa el nombre del tenant.

  Como el tenant se identifica antes del login, **la pantalla de login también sale con su marca**.
- **Quién la edita**: el Administrador del tenant en una página "Configuración → Marca", con un permiso propio de Shield (`Manage:Branding`), y el super admin desde el panel central.
- **Seguridad y calidad**:
  - Logos en PNG/WebP/JPG. SVG solo si se sanea (un SVG servido desde el dominio del tenant puede ejecutar scripts).
  - Límite de tamaño.
  - Aviso de contraste si el color primario es muy claro (Filament genera la paleta desde un solo color).
  - Cambios auditados.
- **API**: exponer logo y colores en `/api/v1/tenant` para que las apps de los customers usen la misma marca.

#### B-04 a B-09 (resumen)

- **B-04 Contacto**: `contact_email` y `contact_phone` en el tenant (central), visibles en la tabla y la ficha. Base para soporte y facturación.
- **B-05 Detalle del tenant**: página de solo lectura con dominios, BD, cantidad de usuarios y customers, último acceso, zona horaria, marca y su auditoría.
- **B-06 Traducciones**: mover los textos del código a `lang/` y agregar idioma por tenant (y luego por usuario). Activar `localization` de Shield para los permisos.
- **B-07 "¿Cuál es mi espacio?"**: en `localhost/` (hoy redirige a `/admin`), un formulario que recibe el email y envía por correo los enlaces a los tenants donde existe. No inicia sesión desde el central (tomatophp sí lo hace, con la contraseña duplicada).
- **B-08 Alta self-service**: registro público que crea el tenant en cola (pipeline de stancl), con verificación por email, límite de altas por IP y plan inicial (enlaza con planes y cobro).
- **B-09 Social**: Socialite como alternativa de alta y login del dueño, solo si se aprueba B-08.
