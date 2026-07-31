/**
 * MyTube Live Server - Configuração do Banco de Dados
 * Conexão MySQL usando mysql2 com Promise
 */

const mysql = require('mysql2/promise');
require('dotenv').config();

// Pool de conexões para melhor performance
const pool = mysql.createPool({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'mytube_db',
    port: process.env.DB_PORT || 3306,
    charset: 'utf8mb4',
    waitForConnections: true,
    connectionLimit: 20,
    queueLimit: 0,
    enableKeepAlive: true,
    keepAliveInitialDelay: 0
});

// Testar conexão
async function testConnection() {
    try {
        const connection = await pool.getConnection();
        console.log('✅ Live Server: Conectado ao banco de dados MySQL');
        connection.release();
        return true;
    } catch (error) {
        console.error('❌ Live Server: Erro ao conectar ao banco de dados:', error.message);
        return false;
    }
}

module.exports = { pool, testConnection };
