<nav class="navbar navbar-expand-lg bg-body-tertiary">
    <div class="container">
        <a class="navbar-brand p-0" href="#">
            <img src="{{ asset('vendor/security/images/laravel-security.png') }}" alt="Logo" style="height: 38px;">
            Laravel Security
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav ms-auto me-5 mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link {{ app('security')->routeIsActive('dashboard.index') ? 'active' : '' }}"
                       href="{{ app('security')->route('dashboard.index') }}"
                    >
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ app('security')->routeIsActive('logs.index') ? 'active' : '' }}"
                       href="{{ app('security')->route('logs.index') }}"
                    >
                        Logs
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ app('security')->routeIsActive('ips.index') ? 'active' : '' }}"
                       href="{{ app('security')->route('ips.index') }}"
                    >
                        IPs
                    </a>
                </li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    @include('security::layouts.bootstrap._theme-switcher')
                </li>
            </ul>
        </div>
    </div>
</nav>
