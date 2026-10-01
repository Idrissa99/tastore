<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Tontine App')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a href="{{ route('tontines.index') }}" class="brand">
                <span class="wheel-dot">●</span> Tontine App
            </a>

            <input type="checkbox" id="nav-toggle" class="nav-toggle">
            <label for="nav-toggle" class="nav-toggle-label" aria-label="Ouvrir le menu">☰</label>

            <nav class="main-nav">
                <a href="{{ route('products.show-all') }}">Produits</a>

                @auth
                    <a href="{{ auth()->user()->isAdmin() ? route('admin.dashboard') : route('dashboard') }}">Mon tableau de bord</a>

                    @if (auth()->user()->isMerchant() && auth()->user()->merchant?->isApproved())
                        <a href="{{ route('merchant.dashboard') }}">Espace commerçant</a>
                    @endif

                    @if (auth()->user()->isAdmin() || (auth()->user()->isMerchant() && auth()->user()->merchant?->isApproved()))
                        <a href="{{ route('tontines.create') }}">Créer une tontine</a>
                    @endif

                    <a href="{{ route('contributions.index') }}">Mes cotisations</a>
                    <a href="{{ route('installment-purchases.index') }}">Mes achats</a>
                    <a href="{{ route('notifications.index') }}">
                        Notifications
                        @php $unread = auth()->user()->unreadNotifications()->count(); @endphp
                        @if ($unread > 0)
                            <span class="badge-count">{{ $unread }}</span>
                        @endif
                    </a>
                    <a href="{{ route('profile.edit') }}">Mon profil</a>
                    <span class="nav-greeting">{{ auth()->user()->name }}</span>
                    <form action="{{ route('logout') }}" method="POST" style="display:inline">
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--sable-clair); color:var(--sable-clair);">Déconnexion</button>
                    </form>
                @else
                    <a href="{{ route('login') }}">Connexion</a>
                    <a href="{{ route('register') }}" class="btn btn-primary btn-sm">S'inscrire</a>
                @endauth
            </nav>
        </div>
    </header>

    <main class="page-content">
        <div class="container">
            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            @if (session('error'))
                <div class="alert alert-error">{{ session('error') }}</div>
            @endif

            @yield('content')
        </div>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p>&copy; {{ date('Y') }} Tontine App — plateforme de commerce par tontine d'achat.</p>
            <div>
                <a href="{{ route('products.show-all') }}">Produits</a> ·
                <a href="{{ route('tontines.index') }}">Tontines</a>
            </div>
        </div>
    </footer>
</body>
</html>
