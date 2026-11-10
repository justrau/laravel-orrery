<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Meta Information -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('orrery.name', 'Orrery') }}</title>

    <!-- Style sheets and the application: inlined from dist/ -->
    {!! Justrau\Orrery\Orrery::css() !!}
    {!! Justrau\Orrery\Orrery::js() !!}
</head>
<body>
<div id="orrery"></div>
</body>
</html>
