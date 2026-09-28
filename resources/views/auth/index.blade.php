<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('common.dashboard') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
  </head>
  <body>
    <div class="container">
        <h1>{{ __('common.dashboard') }}</h1>
        <table class="table table-striped">
            <thead>
                <th>{{ __('common.name') }}</th>
                <th>{{ __('auth.email') }}</th>
                <th>{{ __('common.action') }}</th>
            </thead>
            <tbody>
                <td>{{$data->name}}</td>
                <td>{{$data->email}}</td>
                <td><a href="logout">{{ __('auth.logout') }}</a></td>
            </tbody>
        </table>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    </div>
  </body>
</html>
