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
    <link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/css/toastr.min.css">

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

        td {
            vertical-align: middle;
        }

        .keyword { color: #005cc5; } /* Dark Blue */
        .json-key { color: #008000; } /* Dark Green */
        .json-string { color: #A52A2A; } /* Dark Red */

        html[data-bs-theme="dark"] .keyword { color: #F92672; } /* Dark Blue */
        html[data-bs-theme="dark"] .json-key { color: #A6E22E; } /* Dark Green */
        html[data-bs-theme="dark"] .json-string { color: #E6DB74; } /* Dark Red */
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
    <script src="//cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/js/toastr.min.js"></script>

    <script>
        window.initTooltips = function () {
            let tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));

            tooltipTriggerList.map(function (tooltipTriggerEl) {
                let tooltip = bootstrap.Tooltip.getOrCreateInstance(tooltipTriggerEl);
                tooltip.dispose();
            })

            tooltipTriggerList.map(function (tooltipTriggerEl) {
                bootstrap.Tooltip.getOrCreateInstance(tooltipTriggerEl);
            })
        }

        window.drawCallback = function (settings) {
            let api = this.api();

            window.initTooltips()
        }

        window.ajax_complete_handler = async function (jqXHR) {
            let response;

            // Try to get the JSON data of the response
            if (jqXHR.hasOwnProperty('responseJSON')) {
                response = jqXHR.responseJSON;
            } else {
                try {
                    response = JSON.parse(jqXHR.responseText);
                } catch (e) {
                    return false;
                }
            }

            if (response.hasOwnProperty('actions')) {
                for (let action of response.actions) {
                    if (action.type === 'toastr') {
                        toastr[action.data.type](action.data.message, action.data?.title, action.data?.options);
                    }

                    if (action.type === 'reloadDataTable') {
                        window.DataTables = window.DataTables || {};

                        try {
                            window.DataTables[action.data.dataTableId].ajax.reload();
                        } catch (e) {
                            console.error('DataTable not found: ' + action.data.dataTableId);
                        }
                    }
                }
            }

            return response;
        }

        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });

        $(document).on('click', '.ajax-link', function (e) {
            e.preventDefault();

            window.initTooltips();

            $.ajax({
                url: $(this).attr('href'),
                type: 'POST',
                complete: window.ajax_complete_handler,
            });
        });
    </script>

    @stack('scripts')
</body>
</html>
