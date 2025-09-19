<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>ProHelper - Система управления строительными проектами</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Inter', sans-serif;
                line-height: 1.6;
                color: #1f2937;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                position: relative;
                overflow-x: hidden;
            }

            .animated-bg {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
                background-size: 400% 400%;
                animation: gradientShift 15s ease infinite;
                z-index: -1;
            }

            @keyframes gradientShift {
                0% { background-position: 0% 50%; }
                50% { background-position: 100% 50%; }
                100% { background-position: 0% 50%; }
            }

            .floating-shape {
                position: absolute;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.1);
                animation: float 6s ease-in-out infinite;
            }

            .floating-shape:nth-child(1) {
                width: 100px;
                height: 100px;
                top: 20%;
                left: 10%;
                animation-delay: 0s;
            }

            .floating-shape:nth-child(2) {
                width: 150px;
                height: 150px;
                top: 60%;
                right: 15%;
                animation-delay: -2s;
            }

            .floating-shape:nth-child(3) {
                width: 80px;
                height: 80px;
                top: 40%;
                left: 80%;
                animation-delay: -4s;
            }

            @keyframes float {
                0%, 100% { transform: translateY(0px) rotate(0deg); }
                50% { transform: translateY(-20px) rotate(180deg); }
            }

            .container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 0 1rem;
                position: relative;
                z-index: 10;
            }

            .header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 2rem 0;
            }

            .logo {
                display: flex;
                align-items: center;
                gap: 0.75rem;
                font-size: 1.5rem;
                font-weight: 700;
                color: white;
                text-decoration: none;
            }

            .logo-icon {
                width: 40px;
                height: 40px;
                background: linear-gradient(45deg, #ff6b6b, #4ecdc4);
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-weight: bold;
                font-size: 1.2rem;
            }

            .nav-links {
                display: flex;
                gap: 1.5rem;
                align-items: center;
            }

            .nav-link {
                color: rgba(255, 255, 255, 0.9);
                text-decoration: none;
                padding: 0.5rem 1rem;
                border-radius: 8px;
                transition: all 0.3s ease;
                font-weight: 500;
            }

            .nav-link:hover {
                background: rgba(255, 255, 255, 0.1);
                color: white;
            }

            .main-content {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                min-height: 70vh;
                text-align: center;
                padding: 2rem 0;
            }

            .hero-title {
                font-size: 3.5rem;
                font-weight: 700;
                color: white;
                margin-bottom: 1.5rem;
                text-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
                animation: fadeInUp 1s ease-out;
            }

            .hero-subtitle {
                font-size: 1.25rem;
                color: rgba(255, 255, 255, 0.9);
                margin-bottom: 3rem;
                max-width: 600px;
                animation: fadeInUp 1s ease-out 0.2s both;
            }

            .cta-section {
                display: flex;
                gap: 1rem;
                flex-wrap: wrap;
                justify-content: center;
                animation: fadeInUp 1s ease-out 0.4s both;
            }

            .btn {
                padding: 1rem 2rem;
                border-radius: 12px;
                text-decoration: none;
                font-weight: 600;
                font-size: 1.1rem;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                gap: 0.5rem;
                min-width: 200px;
                justify-content: center;
                position: relative;
                overflow: hidden;
            }

            .btn-primary {
                background: linear-gradient(45deg, #ff6b6b, #4ecdc4);
                color: white;
                border: none;
                box-shadow: 0 8px 25px rgba(255, 107, 107, 0.3);
            }

            .btn-primary:hover {
                transform: translateY(-3px);
                box-shadow: 0 12px 35px rgba(255, 107, 107, 0.4);
            }

            .btn-secondary {
                background: rgba(255, 255, 255, 0.15);
                color: white;
                border: 2px solid rgba(255, 255, 255, 0.3);
                backdrop-filter: blur(10px);
            }

            .btn-secondary:hover {
                background: rgba(255, 255, 255, 0.25);
                border-color: rgba(255, 255, 255, 0.5);
                transform: translateY(-2px);
            }

            .footer {
                text-align: center;
                padding: 2rem 0;
                color: rgba(255, 255, 255, 0.7);
                margin-top: 3rem;
                font-size: 0.9rem;
            }

            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            @media (max-width: 768px) {
                .hero-title {
                    font-size: 2.5rem;
                }
                
                .hero-subtitle {
                    font-size: 1.1rem;
                }
                
                .cta-section {
                    flex-direction: column;
                    align-items: center;
                }

                .nav-links {
                    display: none;
                }
            }
        </style>
    </head>
    <body>
        <div class="animated-bg"></div>
        
        <div class="floating-shape"></div>
        <div class="floating-shape"></div>
        <div class="floating-shape"></div>

        <div class="container">
            <header class="header">
                <a href="/" class="logo">
                    <div class="logo-icon">PH</div>
                    ProHelper
                </a>
                
                @if (Route::has('login'))
                    <nav class="nav-links">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="nav-link">Dashboard</a>
                        @else
                            <a href="{{ route('login') }}" class="nav-link">Войти</a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="nav-link">Регистрация</a>
                            @endif
                        @endauth
                    </nav>
                @endif
            </header>

            <main class="main-content">
                <h1 class="hero-title">ProHelper</h1>
                <p class="hero-subtitle">
                    Комплексная система управления строительными проектами. 
                    Управляйте подрядчиками, контролируйте выполнение работ и ведите документооборот в одном месте.
                </p>

                <div class="cta-section">
                    <a href="/docs" class="btn btn-primary">
                        <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9 4.804A7.968 7.968 0 005.5 4c-1.255 0-2.443.29-3.5.804v10A7.969 7.969 0 015.5 14c1.669 0 3.218.51 4.5 1.385A7.962 7.962 0 0114.5 14c1.255 0 2.443.29 3.5.804v-10A7.968 7.968 0 0014.5 4c-1.255 0-2.443.29-3.5.804V12a1 1 0 11-2 0V4.804z"/>
                        </svg>
                        API Документация
                    </a>
                    
                    <a href="#about" class="btn btn-secondary">
                        <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                        О системе
                    </a>
                </div>
            </main>

            <footer class="footer">
                ProHelper v1.0.0 | Laravel v{{ Illuminate\Foundation\Application::VERSION }} (PHP v{{ PHP_VERSION }})
            </footer>
        </div>
    </body>
</html>
