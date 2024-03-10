<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Dashboard') • Laravel Security</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;700&display=swap" rel="stylesheet">

    <!-- Font Awesome 5 -->
    <link rel="stylesheet" media="screen, print" href="{{ asset('vendor/smartadmin/css/fontawesome.bundle.css') }}">
    <link rel="stylesheet" media="screen, print" href="{{ asset('vendor/security/plugins/fontawesome/css/fa-regular.css') }}">

    <!-- Styles -->
    <link rel="stylesheet" href="{{ asset('vendor/security/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/security/plugins/DataTables/datatables.min.css') }}">

    <style>
        html, body {
            font-family: Nunito, sans-serif;
            background: url("{{ asset('vendor/security/images/cloud-bg.png') }}");
        }
        html[data-bs-theme="dark"], [data-bs-theme="dark"] body {
            background: url("{{ asset('vendor/security/images/cloud-bg-dark.png') }}");
        }

        .dtr-details {width: 100%;}
        .widget-icon {position: absolute; bottom: -1rem; right: 1rem; color: #e9e9e9;}
    </style>

    <!-- Head -->
    @stack('head')
</head>
<body>
    <!-- Navbar -->
    @include('security::layouts.bootstrap._navbar')

    <!-- Container -->
    <div class="container mb-5">
        <!-- Content -->
        @yield('content')
    </div>

    <!-- Scripts -->
    <script src="{{ asset('vendor/security/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('vendor/security/plugins/DataTables/datatables.min.js') }}"></script>

    @stack('scripts')
</body>
</html>
