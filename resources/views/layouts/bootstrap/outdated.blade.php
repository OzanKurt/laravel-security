<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Out of Date • Laravel Security</title>
    <meta name="description" content="Laravel Security assets are outdated.">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;700&display=swap" rel="stylesheet">

    <!-- Styles -->
    <style>
        html, body {
            font-family: Nunito, sans-serif;
        }

        .container {
            margin-top: 4rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 0.25rem;
            max-width: 900px;
            margin: 0 auto;
        }

        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }

        .alert-heading {
            font-weight: bold;
        }

        .alert-content {
            margin-top: 1rem;
        }

        code {
            color: #c7254e;
            background-color: #f9f2f4;
            padding: 2px 4px;
            font-size: 90%;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="alert alert-danger" role="alert">
            <div class="alert-heading">Your Laravel Security package assets are out of date!</div>
            <div class="alert-content">
                You are using outdated versions of assets. Please re-publish the package assets by running the command:<br>
                <br>
                <code>
                    php artisan vendor:publish --provider="OzanKurt\Security\SecurityServiceProvider" --tag="security-assets"
                </code>
            </div>
        </div>
    </div>
</body>
</html>
