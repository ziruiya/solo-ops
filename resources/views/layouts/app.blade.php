<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SoloOps</title>
<style>body{font:16px system-ui;max-width:1100px;margin:2rem auto;padding:0 1rem;color:#182334}nav{display:flex;flex-wrap:wrap;gap:1rem;align-items:center;border-bottom:1px solid #ccc;padding:1rem 0}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #ddd;padding:.6rem;text-align:left}input,select,textarea{padding:.5rem;margin:.3rem}button{padding:.5rem 1rem}.demo{background:#fff4d6;padding:.6rem}</style>
</head>
<body>
<nav aria-label="メインメニュー">
<a href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>ダッシュボード</a>
<a href="{{ route('incidents.index') }}">障害管理</a>
<a href="{{ route('work-records.index') }}">運用記録</a>
<a href="{{ route('audit-events.index') }}">監査記録</a>
<a href="{{ route('assets.index') }}">IT資産</a>
<a href="{{ route('vulnerabilities.index') }}">脆弱性管理</a>
<a href="{{ route('update-plans.index') }}">更新・変更管理</a>
@if(auth()->user()->roles()->where('name', 'Admin')->exists())<a href="{{ route('settings.users.index') }}">ユーザー管理</a>@endif
@can('manage-system-settings')
    <a href="{{ route('settings.system.index') }}" @if(request()->routeIs('settings.system.index')) aria-current="page" @endif>システム設定</a>
@endcan
<form method="post" action="{{ route('logout') }}">@csrf<button>ログアウト</button></form>
</nav>
<p class="demo">DEMO DATA｜想定環境｜実在企業の構成ではありません</p>
@if(session('status'))<p>{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert"><p>入力内容を確認してください。</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</body>
</html>
