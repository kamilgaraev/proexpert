<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ProHelper API Documentation</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 60px;
            color: white;
        }

        .header h1 {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 20px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .header p {
            font-size: 1.3rem;
            opacity: 0.9;
            font-weight: 300;
        }

        .apis-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 60px;
        }

        .api-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .api-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }

        .api-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .api-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            display: block;
        }

        .api-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: #2d3748;
        }

        .api-description {
            font-size: 1rem;
            line-height: 1.6;
            color: #718096;
            margin-bottom: 25px;
        }

        .api-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .api-version {
            background: #e2e8f0;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            color: #4a5568;
        }

        .api-status {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .api-status.stable {
            background: #c6f6d5;
            color: #22543d;
        }

        .api-status.beta {
            background: #fed7d7;
            color: #742a2a;
        }

        .api-base-url {
            font-family: 'Monaco', 'Menlo', monospace;
            background: #f7fafc;
            padding: 10px 15px;
            border-radius: 10px;
            font-size: 0.9rem;
            color: #2d3748;
            margin-bottom: 25px;
            border-left: 4px solid #667eea;
        }

        .api-link {
            display: inline-block;
            background: linear-gradient(90deg, #667eea, #764ba2);
            color: white;
            text-decoration: none;
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .api-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .footer {
            text-align: center;
            color: white;
            opacity: 0.8;
        }

        .footer p {
            font-size: 1rem;
            margin-bottom: 10px;
        }

        .footer a {
            color: white;
            text-decoration: none;
            font-weight: 500;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 2.5rem;
            }
            
            .header p {
                font-size: 1.1rem;
            }
            
            .api-card {
                padding: 30px;
            }
            
            .apis-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>ProHelper API</h1>
            <p>Полная документация по всем API системы ProHelper</p>
        </div>

        <div class="apis-grid">
            @foreach($apis as $key => $api)
            <div class="api-card">
                <span class="api-icon">{{ $api['icon'] }}</span>
                <h2 class="api-title">{{ $api['title'] }}</h2>
                <p class="api-description">{{ $api['description'] }}</p>
                
                <div class="api-meta">
                    <span class="api-version">v{{ $api['version'] }}</span>
                    <span class="api-status {{ $api['status'] }}">{{ ucfirst($api['status']) }}</span>
                </div>
                
                <div class="api-base-url">{{ $api['baseUrl'] }}</div>
                
                <a href="/docs/{{ $key }}" class="api-link">Открыть документацию</a>
            </div>
            @endforeach
        </div>

        <div class="footer">
            <p>Создано с ❤️ командой ProHelper</p>
            <p>Версия системы: 1.0.0 | <a href="https://prohelper.pro" target="_blank">prohelper.pro</a></p>
        </div>
    </div>
</body>
</html>
