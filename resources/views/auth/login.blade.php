<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SoloOps ログイン</title>
    <style>
        body {
            font: 16px system-ui;
            max-width: 1100px;
            margin: 2rem auto;
            padding: 0 1rem;
            color: #182334;
        }

        .demo {
            background: #fff4d6;
            padding: .6rem;
        }

        form {
            max-width: 24rem;
        }

        label {
            display: block;
        }

        label > input {
            box-sizing: border-box;
            display: block;
            width: 100%;
            padding: .5rem;
            margin-top: .3rem;
        }

        button {
            padding: .5rem 1rem;
        }
    </style>
</head>
<body>
<main>
    <h1>SoloOps ログイン</h1>
    <p class="demo">DEMO DATA｜想定環境｜実在企業の構成ではありません</p>
    <form method="post" action="{{ route('login.store') }}">
        @csrf
        <p><label>ユーザー名またはメールアドレス <input name="login" value="{{ old('login') }}" autocomplete="username" required></label></p>
        <p><label>パスワード <input type="password" name="password" autocomplete="current-password" required></label></p>
        <button>ログイン</button>
    </form>
    @if($errors->any())
        <p role="alert">ユーザー名またはパスワードを確認してください。</p>
    @endif
</main>
</body>
</html>
