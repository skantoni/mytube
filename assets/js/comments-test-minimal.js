// TESTE MINIMALISTA 1
console.log('🔴🔴🔴 TESTE 1: Script carregado!');
console.log('🔴🔴🔴 Timestamp:', new Date().toISOString());

// Testar se pode criar classe
try {
    class TestClass {
        constructor() {
            console.log('🔴🔴🔴 TESTE 2: Classe criada!');
        }
    }
    
    const test = new TestClass();
    console.log('🔴🔴🔴 TESTE 3: Instância criada!');
} catch (error) {
    console.error('🔴🔴🔴 ERRO ao criar classe:', error);
}

// Testar event listener
document.addEventListener('DOMContentLoaded', () => {
    console.log('🔴🔴🔴 TESTE 4: DOMContentLoaded disparado!');
});

console.log('🔴🔴🔴 TESTE 5: Final do script alcançado!');
