<?php

/*
|--------------------------------------------------------------------------
| core-saas
|--------------------------------------------------------------------------
|
| Valores por defecto de cada tenant cuando no definió los suyos
| (ver "Configuración" en el panel del tenant y la ficha del tenant en el central).
|
*/

return [
    'tenant' => [
        // Zona horaria IANA. La BD y la app siempre trabajan en UTC (config/app.php)
        'default_timezone' => env('TENANT_DEFAULT_TIMEZONE', 'UTC'),

        // Color primario del panel del tenant (Filament genera la paleta a partir de este)
        'default_primary_color' => env('TENANT_DEFAULT_PRIMARY_COLOR', '#f59e0b'),
    ],
];
