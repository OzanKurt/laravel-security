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
                        @lang('shield::dashboard.dashboard')
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ app('shield')->routeIsActive('auth-logs.index') ? 'active' : '' }}"
                       href="{{ app('shield')->route('auth-logs.index') }}"
                    >
                        @lang('shield::dashboard.auth_logs')
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ app('shield')->routeIsActive('logs.index') ? 'active' : '' }}"
                       href="{{ app('shield')->route('logs.index') }}"
                    >
                        @lang('shield::dashboard.logs')
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ app('shield')->routeIsActive('acl.index') ? 'active' : '' }}"
                       href="{{ app('shield')->route('acl.index') }}"
                    >
                        @lang('shield::dashboard.acl')
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ app('shield')->routeIsActive('audit-log.index') ? 'active' : '' }}"
                       href="{{ app('shield')->route('audit-log.index') }}"
                    >
                        @lang('shield::dashboard.audit_log')
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ app('shield')->routeIsActive('cache.index') ? 'active' : '' }}"
                       href="{{ app('shield')->route('cache.index') }}"
                    >
                        Cache
                    </a>
                </li>
            </ul>
            <a class="navbar-brand mx-auto p-0"
               href="{{ app('shield')->logoHref() }}"
            >
                <img src="{{ asset('vendor/shield/images/laravel-security.png') }}" alt="Logo" style="height: 38px;">
            </a>
            <ul class="navbar-nav justify-content-end" style="width: 40%">
                <li class="nav-item dropdown">
                    @include('shield::layouts.bootstrap._theme-switcher')
                </li>
            </ul>
        </div>
    </div>
</nav>
