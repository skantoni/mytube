<?php
require_once 'includes/config.php';

// Testar se o token está sendo gerado
$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste CSRF</title>
    <script src="<?php echo asset('assets/js/csrf.js'); ?>"></script>
</head>
<body>
    <h1>Teste de CSRF Protection</h1>
    
    <h2>1. Token PHP</h2>
    <pre>Token da Sessão: <?php echo $token; ?></pre>
    
    <h2>2. Token JavaScript</h2>
    <button onclick="testToken()">Testar getCsrfToken()</button>
    <pre id="jsToken"></pre>
    
    <h2>3. Teste de Fetch POST</h2>
    <button onclick="testFetch()">Testar Fetch</button>
    <pre id="fetchResult"></pre>
    
    <h2>4. Teste de FormData</h2>
    <button onclick="testFormData()">Testar FormData</button>
    <pre id="formDataResult"></pre>
    
    <h2>5. Console Logs</h2>
    <p>Abra o DevTools Console (F12) para ver logs detalhados</p>
    
    <script>
        function testToken() {
            const token = getCsrfToken();
            document.getElementById('jsToken').textContent = 'Token obtido: ' + token;
            console.log('Token CSRF:', token);
        }
        
        async function testFetch() {
            try {
                const response = await fetch('api/update_views.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        video_id: 1
                    })
                });
                
                const data = await response.json();
                document.getElementById('fetchResult').textContent = 
                    'Status: ' + response.status + '\n' +
                    'Response: ' + JSON.stringify(data, null, 2);
                console.log('Fetch result:', response.status, data);
            } catch (error) {
                document.getElementById('fetchResult').textContent = 'Erro: ' + error.message;
                console.error('Fetch error:', error);
            }
        }
        
        async function testFormData() {
            try {
                const formData = new FormData();
                formData.append('video_id', '1');
                
                const response = await fetch('api/update_views.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                document.getElementById('formDataResult').textContent = 
                    'Status: ' + response.status + '\n' +
                    'Response: ' + JSON.stringify(data, null, 2);
                console.log('FormData result:', response.status, data);
            } catch (error) {
                document.getElementById('formDataResult').textContent = 'Erro: ' + error.message;
                console.error('FormData error:', error);
            }
        }
        
        // Log automático ao carregar
        console.log('=== CSRF Test Page Loaded ===');
        console.log('Meta tag:', document.querySelector('meta[name="csrf-token"]'));
        console.log('Token:', getCsrfToken());
    </script>
</body>
</html>
