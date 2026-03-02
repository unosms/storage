<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Storage Manager') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('lightwave.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: #f5f7fb;
            color: #0f172a;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding: 24px 40px 0;
        }

        .link-btn {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #0f172a;
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
        }

        .hero {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card {
            width: min(900px, 100%);
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 28px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            text-align: center;
        }

        .title {
            margin: 0 0 8px;
            font-size: 34px;
            line-height: 1.1;
            font-weight: 800;
        }

        .subtitle {
            margin: 0;
            color: #475569;
            font-size: 18px;
        }

        .brand {
            color: #0ea5e9;
            font-weight: 800;
        }

        .logo-box {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
            max-width: 520px;
            margin: 0 auto;
            padding: 24px;
        }

        .logo-box img {
            width: 100%;
            height: auto;
            display: block;
            object-fit: contain;
        }

        @media (max-width: 860px) {
            .topbar {
                padding: 20px 20px 0;
            }

            .card {
                padding: 20px;
            }

            .title {
                font-size: 28px;
            }

            .subtitle {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
<div class="page">
    @if (Route::has('login'))
        <div class="topbar">
            @auth
                <a href="{{ url('/dashboard') }}" class="link-btn">Dashboard</a>
            @else
                <a href="{{ route('login') }}" class="link-btn">Log in</a>
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="link-btn">Register</a>
                @endif
            @endauth
        </div>
    @endif

    <main class="hero">
        <section class="card">
            <div>
                <h1 class="title">Storage Manager</h1>
                <p class="subtitle">by <span class="brand">Lightwave</span></p>
            </div>
            <div class="logo-box">
                <img src="{{ asset('lightwave.png') }}" alt="Lightwave Logo">
            </div>
        </section>
    </main>
</div>
</body>
</html>
