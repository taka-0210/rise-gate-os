<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $mailSubject }}</title>
</head>
<body>
    <h1>{{ $mailSubject }}</h1>
    <p>{{ $intro }}</p>
    @if ($actionUrl && $actionLabel)
        <p><a href="{{ $actionUrl }}">{{ $actionLabel }}</a></p>
        <p>このURLは転送せず、心当たりがない場合は操作しないでください。</p>
    @endif
</body>
</html>
