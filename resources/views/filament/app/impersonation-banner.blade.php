{{--
    Barra de "Entrar como admin" (AppPanelProvider, hook BODY_START). Estilos en línea: el CSS
    precompilado de Filament no incluye clases de Tailwind propias.
--}}
<div
    role="status"
    style="position: sticky; top: 0; z-index: 50; display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: .5rem 1rem; padding: .5rem 1rem; background: #b45309; color: #fff; font-size: .875rem; line-height: 1.25rem;"
>
    <span>
        Estás en <strong>{{ tenant('name') }}</strong> como <strong>{{ auth()->user()?->name }}</strong>,
        ingresaste desde el panel central como <strong>{{ $impersonation['admin_name'] }}</strong>.
    </span>

    <form method="POST" action="{{ route('tenant.impersonate.leave') }}" style="margin: 0;">
        @csrf
        <button
            type="submit"
            style="padding: .25rem .75rem; border-radius: .375rem; background: #fff; color: #92400e; font-weight: 600; cursor: pointer;"
        >
            Volver al panel central
        </button>
    </form>
</div>
