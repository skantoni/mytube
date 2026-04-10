// Funções para alternar entre login e cadastro
function showLogin() {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const forgotFlow = document.getElementById('forgotPasswordFlow');
    const tabs = document.querySelectorAll('.tab-btn');
    
    // Alternar formulários
    loginForm.classList.add('active');
    registerForm.classList.remove('active');
    if (forgotFlow) forgotFlow.classList.remove('active');
    
    // Alternar abas
    tabs[0].classList.add('active');
    tabs[1].classList.remove('active');
}

function showRegister() {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const forgotFlow = document.getElementById('forgotPasswordFlow');
    const tabs = document.querySelectorAll('.tab-btn');
    
    // Alternar formulários
    loginForm.classList.remove('active');
    registerForm.classList.add('active');
    if (forgotFlow) forgotFlow.classList.remove('active');
    
    // Alternar abas
    tabs[0].classList.remove('active');
    tabs[1].classList.add('active');
}

// Toggle password visibility
function togglePassword(btn) {
    const input = btn.parentElement.querySelector('input');
    const eyeIcon = btn.querySelector('.eye-icon');
    const eyeOffIcon = btn.querySelector('.eye-off-icon');
    
    if (input.type === 'password') {
        input.type = 'text';
        eyeIcon.style.display = 'none';
        eyeOffIcon.style.display = 'block';
    } else {
        input.type = 'password';
        eyeIcon.style.display = 'block';
        eyeOffIcon.style.display = 'none';
    }
    input.focus();
}

// Validação em tempo real
document.addEventListener('DOMContentLoaded', function() {
    // Bloquear caracteres inválidos no campo username em tempo real
    const usernameInput = document.querySelector('input[name="reg_username"]');
    if (usernameInput) {
        usernameInput.addEventListener('input', function(e) {
            // Remove espaços, @ e caracteres não permitidos
            this.value = this.value.replace(/[^a-zA-Z0-9_\-]/g, '');
            // Limitar a 12 caracteres
            if (this.value.length > 12) {
                this.value = this.value.substring(0, 12);
            }
        });
        usernameInput.addEventListener('paste', function(e) {
            setTimeout(() => {
                this.value = this.value.replace(/[^a-zA-Z0-9_\-]/g, '').substring(0, 12);
            }, 0);
        });
    }

    // Validação de email com feedback
    const emailInput = document.querySelector('input[name="reg_email"]');
    if (emailInput) {
        emailInput.addEventListener('blur', function() {
            const val = this.value.trim();
            if (val.length > 0) {
                const hasAt = val.includes('@');
                const parts = val.split('@');
                const hasDomain = parts.length === 2 && parts[1].includes('.') && parts[1].split('.').pop().length >= 2;
                if (!hasAt || !hasDomain) {
                    this.classList.remove('valid');
                    this.classList.add('invalid');
                }
            }

    // Validação de confirmação de senha em tempo real
    const regConfirmInput = document.querySelector('input[name="reg_confirm_password"]');
    const regPwdInput = document.querySelector('input[name="reg_password"]');
    if (regConfirmInput && regPwdInput) {
        const checkMatch = function() {
            const matchError = document.getElementById('passwordMatchError');
            if (regConfirmInput.value.length > 0 && regPwdInput.value.length > 0) {
                if (regPwdInput.value !== regConfirmInput.value) {
                    regConfirmInput.classList.add('invalid');
                    regConfirmInput.classList.remove('valid');
                    if (matchError) { matchError.textContent = 'Senhas não conferem.'; matchError.style.display = 'block'; }
                } else {
                    regConfirmInput.classList.remove('invalid');
                    regConfirmInput.classList.add('valid');
                    if (matchError) matchError.style.display = 'none';
                }
            } else {
                if (matchError) matchError.style.display = 'none';
            }
        };
        regConfirmInput.addEventListener('input', checkMatch);
        regPwdInput.addEventListener('input', checkMatch);
    }
        });
    }

    const forms = document.querySelectorAll('.auth-form');
    
    forms.forEach(form => {
        const inputs = form.querySelectorAll('input');
        const submitBtn = form.querySelector('button[type="submit"]');
        
        // Adicionar efeito de loading no submit
        form.addEventListener('submit', function(e) {
            // Validação básica antes de permitir o envio
            const requiredInputs = form.querySelectorAll('input[required]');
            let isValid = true;
            
            requiredInputs.forEach(input => {
                if (input.value.trim() === '') {
                    isValid = false;
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Por favor, preencha todos os campos obrigatórios.');
                return;
            }
            
            // Validação de senhas no cadastro (client-side)
            const regPassword = form.querySelector('input[name="reg_password"]');
            const regConfirm = form.querySelector('input[name="reg_confirm_password"]');
            const matchError = document.getElementById('passwordMatchError');
            
            if (regPassword && regConfirm) {
                if (regPassword.value !== regConfirm.value) {
                    e.preventDefault();
                    if (matchError) {
                        matchError.style.display = 'block';
                    }
                    regConfirm.classList.add('invalid');
                    regConfirm.focus();
                    return;
                } else if (regPassword.value.length < 6) {
                    e.preventDefault();
                    if (matchError) {
                        matchError.textContent = 'Senha deve ter pelo menos 6 caracteres.';
                        matchError.style.display = 'block';
                    }
                    regPassword.focus();
                    return;
                } else {
                    if (matchError) matchError.style.display = 'none';
                }
            }
            
            // Efeito visual de loading
            submitBtn.classList.add('loading');
            
            // Guardar o texto original
            if (!submitBtn.dataset.originalText) {
                submitBtn.dataset.originalText = submitBtn.textContent;
            }
            
            submitBtn.textContent = 'Processando...';
            
            // Não desabilitar o botão para permitir o envio do formulário
            // O reset será feito apenas se houver erro (página recarregar)
            setTimeout(() => {
                if (document.contains(submitBtn)) {
                    submitBtn.classList.remove('loading');
                    submitBtn.textContent = submitBtn.dataset.originalText;
                }
            }, 3000);
        });
        
        // Validação dos campos
        inputs.forEach(input => {
            input.addEventListener('input', function() {
                validateField(input);
            });
            
            input.addEventListener('blur', function() {
                validateField(input);
            });
        });
    });
    
    // Verificar se há parâmetros na URL (ex: ?register=1)
    // Mas não redirecionar para cadastro se já houve sucesso no registo
    const urlParams = new URLSearchParams(window.location.search);
    const hasSuccess = document.querySelector('.alert-success') !== null;
    if (urlParams.get('register') === '1' && !hasSuccess) {
        showRegister();
    }
});

function validateField(field) {
    const value = field.value.trim();
    const type = field.type;
    const name = field.name;
    
    // Remover classes de validação anteriores
    field.classList.remove('valid', 'invalid');
    
    // Validações específicas
    let isValid = true;
    
    if (name === 'reg_username') {
        isValid = value.length >= 3 && value.length <= 12 && /^[a-zA-Z0-9_\-]+$/.test(value);
    } else if (name === 'reg_email') {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        isValid = emailRegex.test(value);
    } else if (name === 'reg_password') {
        isValid = value.length >= 6;
    } else if (name === 'reg_confirm_password') {
        const passwordField = document.querySelector('input[name="reg_password"]');
        isValid = value === passwordField.value && value.length >= 6;
    } else if (type === 'email') {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        isValid = emailRegex.test(value);
    }
    
    // Aplicar classe de validação
    if (value.length > 0) {
        field.classList.add(isValid ? 'valid' : 'invalid');
    }
}

// Animação suave para mudança de altura do card
function adjustCardHeight() {
    const card = document.querySelector('.auth-card');
    const activeForm = document.querySelector('.auth-form.active');
    
    if (activeForm) {
        const height = activeForm.scrollHeight;
        card.style.minHeight = height + 'px';
    }
}

// Efeitos visuais adicionais
document.addEventListener('DOMContentLoaded', function() {
    // Efeito parallax removido.

    // Efeito de digitação no placeholder
    const inputs = document.querySelectorAll('input');
    inputs.forEach(input => {
        const originalPlaceholder = input.placeholder;
        
        input.addEventListener('focus', function() {
            this.placeholder = '';
            typeEffect(this, originalPlaceholder);
        });
        
        input.addEventListener('blur', function() {
            if (this.value === '') {
                this.placeholder = originalPlaceholder;
            }
        });
    });
});

function typeEffect(element, text) {
    let i = 0;
    const speed = 50;
    
    function type() {
        if (i < text.length && document.activeElement === element) {
            element.placeholder += text.charAt(i);
            i++;
            setTimeout(type, speed);
        }
    }
    
    setTimeout(type, 200);
}

// Detecção de dispositivo móvel
function isMobile() {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
}

// ============================
// FORGOT PASSWORD FLOW
// ============================
let resetEmail = '';
let resetToken = '';
let countdownInterval = null;

function showForgotPassword() {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const forgotFlow = document.getElementById('forgotPasswordFlow');
    const tabs = document.querySelectorAll('.tab-btn');
    
    loginForm.classList.remove('active');
    registerForm.classList.remove('active');
    forgotFlow.classList.add('active');
    
    tabs.forEach(t => t.classList.remove('active'));
    
    // Reset para etapa 1
    showForgotStep(1);
    clearForgotMessage();
    
    // Focar no campo de email
    setTimeout(() => {
        const emailInput = document.getElementById('resetEmail');
        if (emailInput) emailInput.focus();
    }, 300);
}

function backToLogin() {
    const loginForm = document.getElementById('loginForm');
    const forgotFlow = document.getElementById('forgotPasswordFlow');
    const tabs = document.querySelectorAll('.tab-btn');
    
    forgotFlow.classList.remove('active');
    loginForm.classList.add('active');
    
    tabs[0].classList.add('active');
    
    // Reset completo
    resetEmail = '';
    resetToken = '';
    clearCountdown();
    clearForgotMessage();
    resetCodeInputs();
    
    const emailInput = document.getElementById('resetEmail');
    if (emailInput) emailInput.value = '';
    const newPwd = document.getElementById('newPassword');
    if (newPwd) newPwd.value = '';
    const confirmPwd = document.getElementById('confirmNewPassword');
    if (confirmPwd) confirmPwd.value = '';
}

function backToStep1() {
    showForgotStep(1);
    clearForgotMessage();
    clearCountdown();
}

function showForgotStep(step) {
    document.querySelectorAll('.forgot-step').forEach(s => s.classList.remove('active'));
    const target = document.getElementById('forgotStep' + step);
    if (target) target.classList.add('active');
}

function showForgotMessage(msg, type = 'error') {
    const el = document.getElementById('forgotMessage');
    el.textContent = msg;
    el.className = 'forgot-message msg-' + type;
    el.style.display = 'block';
    
    // Auto-hide after 5s
    setTimeout(() => {
        if (el.style.display !== 'none') {
            el.style.display = 'none';
        }
    }, 5000);
}

function clearForgotMessage() {
    const el = document.getElementById('forgotMessage');
    if (el) {
        el.style.display = 'none';
        el.textContent = '';
    }
}

// STEP 1: Send code — rate limit helpers
const RESET_CODE_COOLDOWN_MS = 60000; // 60 seconds
const RESET_CODE_COOLDOWN_KEY = 'resetCodeLastSent';
let _resetCodeCooldownTimer = null;

function _getRemainingCooldown() {
    const last = parseInt(localStorage.getItem(RESET_CODE_COOLDOWN_KEY) || '0', 10);
    return Math.max(0, Math.ceil((last + RESET_CODE_COOLDOWN_MS - Date.now()) / 1000));
}

function _startSendCodeCooldownUI() {
    const btn = document.getElementById('btnSendCode');
    if (!btn) return;
    if (_resetCodeCooldownTimer) clearInterval(_resetCodeCooldownTimer);
    _resetCodeCooldownTimer = setInterval(() => {
        const remaining = _getRemainingCooldown();
        if (remaining <= 0) {
            clearInterval(_resetCodeCooldownTimer);
            _resetCodeCooldownTimer = null;
            btn.disabled = false;
            btn.textContent = 'Enviar Código';
        } else {
            btn.disabled = true;
            btn.textContent = 'Aguardar ' + remaining + 's';
        }
    }, 500);
}

// On page load, restore cooldown if still active
(function() {
    if (_getRemainingCooldown() > 0) {
        _startSendCodeCooldownUI();
    }
})();

async function sendResetCode() {
    const emailInput = document.getElementById('resetEmail');
    const btn = document.getElementById('btnSendCode');
    const email = emailInput.value.trim();

    clearForgotMessage();

    // Frontend rate limit check
    const remaining = _getRemainingCooldown();
    if (remaining > 0) {
        showForgotMessage('Aguarde ' + remaining + ' segundos antes de tentar novamente.');
        return;
    }
    
    if (!email) {
        showForgotMessage('Por favor, insira seu e-mail.');
        emailInput.focus();
        return;
    }
    
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        showForgotMessage('Insira um e-mail válido.');
        emailInput.focus();
        return;
    }
    
    // Loading state
    btn.disabled = true;
    btn.textContent = 'Enviando...';
    btn.classList.add('loading');
    
    try {
        const formData = new FormData();
        formData.append('email', email);
        
        const response = await fetch('api/send_reset_code.php', {
            method: 'POST',
            body: formData
        });
        
        // Ler resposta como texto primeiro para evitar erro de parse JSON
        const responseText = await response.text();
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseErr) {
            console.error('Resposta não-JSON do servidor:', responseText.substring(0, 500));
            showForgotMessage('Erro no servidor. Verifique os logs ou tente novamente.');
            return;
        }
        
        if (data.success) {
            resetEmail = email;

            // Record timestamp and start cooldown
            localStorage.setItem(RESET_CODE_COOLDOWN_KEY, Date.now().toString());
            _startSendCodeCooldownUI();
            
            // Mostrar email no step 2
            document.getElementById('emailDisplay').textContent = email;
            
            showForgotMessage(data.message, 'success');
            
            // Se está em dev e tem debug_code, mostrar no console
            if (data.debug_code) {
                console.log('%c[DEV] Código de reset: ' + data.debug_code, 'color: #10b981; font-weight: bold; font-size: 14px;');
            }
            
            // Ir para step 2 após breve delay
            setTimeout(() => {
                showForgotStep(2);
                clearForgotMessage();
                startCountdown();
                
                // Focar no primeiro digit
                const firstDigit = document.querySelector('.code-digit[data-index="0"]');
                if (firstDigit) firstDigit.focus();
            }, 1000);
        } else {
            showForgotMessage(data.message);
        }
    } catch (error) {
        showForgotMessage('Erro de conexão. Tente novamente.');
        console.error('Reset error:', error);
    } finally {
        // Only re-enable if not in cooldown (cooldown is started on success)
        if (_getRemainingCooldown() <= 0) {
            btn.disabled = false;
            btn.textContent = 'Enviar Código';
            btn.classList.remove('loading');
        } else {
            btn.classList.remove('loading');
        }
    }
}

// Code digit inputs behavior
document.addEventListener('DOMContentLoaded', function() {
    const codeInputs = document.querySelectorAll('.code-digit');
    
    codeInputs.forEach((input, index) => {
        // Only allow digits
        input.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
            
            if (this.value.length === 1) {
                this.classList.add('filled');
                // Move to next
                if (index < 5) {
                    codeInputs[index + 1].focus();
                }
            } else {
                this.classList.remove('filled');
            }
            
            // Remove error state
            this.classList.remove('error');
        });
        
        // Handle backspace
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace') {
                if (this.value === '' && index > 0) {
                    codeInputs[index - 1].focus();
                    codeInputs[index - 1].value = '';
                    codeInputs[index - 1].classList.remove('filled');
                }
                this.classList.remove('filled', 'error');
            }
            
            // Enter to verify
            if (e.key === 'Enter') {
                verifyResetCode();
            }
        });
        
        // Handle paste
        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
            
            for (let i = 0; i < Math.min(pasted.length, 6); i++) {
                codeInputs[i].value = pasted[i];
                codeInputs[i].classList.add('filled');
            }
            
            // Focus last filled or last input
            const focusIndex = Math.min(pasted.length, 5);
            codeInputs[focusIndex].focus();
        });
        
        // Focus behavior
        input.addEventListener('focus', function() {
            this.select();
        });
    });
});

function getCodeValue() {
    const digits = document.querySelectorAll('.code-digit');
    return Array.from(digits).map(d => d.value).join('');
}

function resetCodeInputs() {
    document.querySelectorAll('.code-digit').forEach(d => {
        d.value = '';
        d.classList.remove('filled', 'error');
    });
}

// Countdown timer
function startCountdown() {
    clearCountdown();
    let seconds = 60;
    
    const timerEl = document.getElementById('resendTimer');
    const countdownEl = document.getElementById('countdown');
    const resendLink = document.getElementById('resendLink');
    
    timerEl.style.display = 'inline';
    resendLink.style.display = 'none';
    countdownEl.textContent = seconds;
    
    countdownInterval = setInterval(() => {
        seconds--;
        countdownEl.textContent = seconds;
        
        if (seconds <= 0) {
            clearCountdown();
            timerEl.style.display = 'none';
            resendLink.style.display = 'inline';
        }
    }, 1000);
}

function clearCountdown() {
    if (countdownInterval) {
        clearInterval(countdownInterval);
        countdownInterval = null;
    }
}

async function resendCode() {
    clearForgotMessage();
    
    const formData = new FormData();
    formData.append('email', resetEmail);
    
    const resendLink = document.getElementById('resendLink');
    resendLink.textContent = 'Enviando...';
    resendLink.style.pointerEvents = 'none';
    
    try {
        const response = await fetch('api/send_reset_code.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showForgotMessage('Novo código enviado!', 'success');
            resetCodeInputs();
            startCountdown();
            
            if (data.debug_code) {
                console.log('%c[DEV] Novo código: ' + data.debug_code, 'color: #10b981; font-weight: bold; font-size: 14px;');
            }
        } else {
            showForgotMessage(data.message);
        }
    } catch (error) {
        showForgotMessage('Erro de conexão. Tente novamente.');
    } finally {
        resendLink.textContent = 'Reenviar código';
        resendLink.style.pointerEvents = 'auto';
    }
}

// STEP 2: Verify code
async function verifyResetCode() {
    const btn = document.getElementById('btnVerifyCode');
    const code = getCodeValue();
    
    clearForgotMessage();
    
    if (code.length !== 6) {
        showForgotMessage('Insira o código completo de 6 dígitos.');
        document.querySelectorAll('.code-digit').forEach(d => {
            if (!d.value) d.classList.add('error');
        });
        return;
    }
    
    btn.disabled = true;
    btn.textContent = 'Verificando...';
    btn.classList.add('loading');
    
    try {
        const formData = new FormData();
        formData.append('email', resetEmail);
        formData.append('code', code);
        
        const response = await fetch('api/verify_reset_code.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            resetToken = data.reset_token;
            showForgotMessage(data.message, 'success');
            clearCountdown();
            
            setTimeout(() => {
                showForgotStep(3);
                clearForgotMessage();
                
                const newPwd = document.getElementById('newPassword');
                if (newPwd) newPwd.focus();
            }, 1000);
        } else {
            showForgotMessage(data.message);
            
            // Shake animation on code inputs
            document.querySelectorAll('.code-digit').forEach(d => d.classList.add('error'));
            setTimeout(() => {
                document.querySelectorAll('.code-digit').forEach(d => d.classList.remove('error'));
            }, 500);
        }
    } catch (error) {
        showForgotMessage('Erro de conexão. Tente novamente.');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Verificar';
        btn.classList.remove('loading');
    }
}

// STEP 3: Reset password
async function resetPassword() {
    const btn = document.getElementById('btnResetPassword');
    const newPwd = document.getElementById('newPassword').value;
    const confirmPwd = document.getElementById('confirmNewPassword').value;
    
    clearForgotMessage();
    
    if (!newPwd || !confirmPwd) {
        showForgotMessage('Preencha todos os campos.');
        return;
    }
    
    if (newPwd.length < 6) {
        showForgotMessage('A senha deve ter pelo menos 6 caracteres.');
        return;
    }
    
    if (newPwd !== confirmPwd) {
        showForgotMessage('As senhas não conferem.');
        return;
    }
    
    btn.disabled = true;
    btn.textContent = 'Redefinindo...';
    btn.classList.add('loading');
    
    try {
        const formData = new FormData();
        formData.append('reset_token', resetToken);
        formData.append('new_password', newPwd);
        formData.append('confirm_password', confirmPwd);
        
        const response = await fetch('api/reset_password.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showForgotStep(4);
        } else {
            showForgotMessage(data.message);
        }
    } catch (error) {
        showForgotMessage('Erro de conexão. Tente novamente.');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Redefinir Senha';
        btn.classList.remove('loading');
    }
}

// Ajustes específicos para mobile
if (isMobile()) {
    document.body.classList.add('mobile');
    
    // Evitar zoom no iOS quando o input é focado
    const inputs = document.querySelectorAll('input');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            if (parseFloat(getComputedStyle(input).fontSize) < 16) {
                input.style.fontSize = '16px';
            }
        });
    });
}

// Funcionalidade de mostrar/ocultar senha (legacy - mantido para compatibilidade)
// A função principal togglePassword está definida no topo do arquivo

// Feedback visual melhorado
function showMessage(message, type = 'info') {
    const messageDiv = document.createElement('div');
    messageDiv.className = `message message-${type}`;
    messageDiv.textContent = message;
    
    messageDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 16px 24px;
        border-radius: 8px;
        color: white;
        font-weight: 600;
        z-index: 9999;
        animation: slideInRight 0.3s ease-out;
        max-width: 300px;
    `;
    
    const colors = {
        success: '#10b981',
        error: '#ef4444',
        warning: '#f59e0b',
        info: '#3b82f6'
    };
    
    messageDiv.style.backgroundColor = colors[type] || colors.info;
    
    document.body.appendChild(messageDiv);
    
    setTimeout(() => {
        messageDiv.style.animation = 'slideOutRight 0.3s ease-in forwards';
        setTimeout(() => {
            document.body.removeChild(messageDiv);
        }, 300);
    }, 3000);
}