<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Workbench login</title>
</head>
<body>
<main>
    <h1>Workbench login (strict-auth mode)</h1>

    @if ($current !== null)
        <p>Signed in as <strong>{{ $current }}</strong>. <a href="{{ $dashboard }}">Open the dashboard</a>.</p>
        <form method="POST" action="/logout">
            @csrf
            <button type="submit">Log out</button>
        </form>
    @endif

    @if ($error !== null)
        <p role="alert">{{ $error }}</p>
    @endif

    <form method="POST" action="/login">
        @csrf
        <p>
            <label for="email">Email</label>
            <select id="email" name="email">
                @foreach ($users as $email => $user)
                    <option value="{{ $email }}">{{ $email }}</option>
                @endforeach
            </select>
        </p>
        <p>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" value="">
        </p>
        <p><button type="submit">Log in</button></p>
    </form>

    <table>
        <caption>Seeded users (password: <code>{{ $password }}</code>)</caption>
        <thead><tr><th>Email</th><th>Abilities</th></tr></thead>
        <tbody>
        @foreach ($users as $email => $user)
            <tr><td>{{ $email }}</td><td>{{ $user['abilities'] === [] ? 'none' : implode(', ', $user['abilities']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
</main>
</body>
</html>
