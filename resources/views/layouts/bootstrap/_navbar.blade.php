<nav class="navbar navbar-expand-lg bg-body shadow">
    <div class="container">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav mb-2 mb-lg-0" style="width: 40%">
                <li class="nav-item">
                    <a class="nav-link {{ app('shield')->routeIsActive('dashboard.index') ? 'active' : '' }}"
                       href="{{ app('shield')->route('dashboard.index') }}"
                    >
                        @lang('security::dashboard.dashboard')
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ app('shield')->routeIsActive('auth-logs.index') ? 'active' : '' }}"
                       href="{{ app('shield')->route('auth-logs.index') }}"
                    >
                        @lang('security::dashboard.auth_logs')
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ app('shield')->routeIsActive('logs.index') ? 'active' : '' }}"
                       href="{{ app('shield')->route('logs.index') }}"
                    >
                        @lang('security::dashboard.logs')
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ app('shield')->routeIsActive('ips.index') ? 'active' : '' }}"
                       href="{{ app('shield')->route('ips.index') }}"
                    >
                        @lang('security::dashboard.ips')
                    </a>
                </li>
            </ul>
            <a class="navbar-brand mx-auto p-0"
               href="{{ app('shield')->logoHref() }}"
            >
                <img src="{{ asset('vendor/security/images/laravel-security.png') }}" alt="Logo" style="height: 38px;">
            </a>
            <ul class="navbar-nav justify-content-end" style="width: 40%">
                <li class="nav-item dropdown">
                    @include('security::layouts.bootstrap._theme-switcher')
                </li>
            </ul>
        </div>
    </div>
</nav>
