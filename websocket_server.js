const WebSocket = require('ws');
const mysql = require('mysql2/promise');

// WebSocket server configuration
const PORT = 8080;
const wss = new WebSocket.Server({ port: PORT });

// Database configuration (match your PHP config)
const dbConfig = {
    host: 'localhost',
    user: 'root',
    password: '',
    database: 'goglam'
};

// Store connected clients: { chat_id: [WebSocket connections] }
const clients = new Map();

// Create database connection pool
let dbPool;

async function initDatabase() {
    try {
        dbPool = mysql.createPool({
            ...dbConfig,
            waitForConnections: true,
            connectionLimit: 10,
            queueLimit: 0
        });
        console.log('Database connection pool created');
    } catch (error) {
        console.error('Database connection failed:', error);
        process.exit(1);
    }
}

// Initialize database on startup
initDatabase();

wss.on('connection', (ws, req) => {
    console.log('New client connected');
    
    let chatId = null;
    let userId = null;
    let userType = null;
    
    ws.on('message', async (message) => {
        try {
            const data = JSON.parse(message.toString());
            
            if (data.type === 'join') {
                // Client joining a chat
                chatId = data.chat_id;
                userId = data.user_id;
                userType = data.user_type;
                
                if (!clients.has(chatId)) {
                    clients.set(chatId, []);
                }
                clients.get(chatId).push(ws);
                
                console.log(`User ${userId} (${userType}) joined chat ${chatId}`);
                
                // Send confirmation
                ws.send(JSON.stringify({
                    type: 'joined',
                    chat_id: chatId
                }));
                
            } else if (data.type === 'message') {
                // New message received
                const { chat_id, message_text, sender_type, user_id } = data;
                
                // Verify user has access to this chat
                const connection = await dbPool.getConnection();
                try {
                    let verifyQuery;
                    if (sender_type === 'customer') {
                        verifyQuery = 'SELECT chat_id FROM chat WHERE chat_id = ? AND customer_id = ?';
                    } else {
                        verifyQuery = 'SELECT chat_id FROM chat WHERE chat_id = ? AND saloon_id = ?';
                    }
                    
                    const [rows] = await connection.execute(verifyQuery, [chat_id, user_id]);
                    
                    if (rows.length === 0) {
                        ws.send(JSON.stringify({
                            type: 'error',
                            message: 'Access denied'
                        }));
                        return;
                    }
                    
                    // Insert message into database
                    const [result] = await connection.execute(
                        'INSERT INTO message (chat_id, message_text, sender_type) VALUES (?, ?, ?)',
                        [chat_id, message_text, sender_type]
                    );
                    
                    const messageId = result.insertId;
                    const [timeRow] = await connection.execute(
                        'SELECT time_stamp FROM message WHERE message_id = ?',
                        [messageId]
                    );
                    const timeStamp = timeRow[0].time_stamp;
                    
                    // Broadcast message to all clients in this chat
                    const messageData = {
                        type: 'new_message',
                        message_id: messageId,
                        chat_id: chat_id,
                        message_text: message_text,
                        sender_type: sender_type,
                        time_stamp: timeStamp
                    };
                    
                    if (clients.has(chat_id)) {
                        clients.get(chat_id).forEach((client) => {
                            if (client.readyState === WebSocket.OPEN) {
                                client.send(JSON.stringify(messageData));
                            }
                        });
                    }
                    
                } finally {
                    connection.release();
                }
            }
            
        } catch (error) {
            console.error('Error handling message:', error);
            ws.send(JSON.stringify({
                type: 'error',
                message: 'Server error'
            }));
        }
    });
    
    ws.on('close', () => {
        console.log('Client disconnected');
        
        // Remove client from chat
        if (chatId && clients.has(chatId)) {
            const chatClients = clients.get(chatId);
            const index = chatClients.indexOf(ws);
            if (index > -1) {
                chatClients.splice(index, 1);
            }
            if (chatClients.length === 0) {
                clients.delete(chatId);
            }
        }
    });
    
    ws.on('error', (error) => {
        console.error('WebSocket error:', error);
    });
});

// Handle server shutdown
process.on('SIGINT', async () => {
    console.log('\nShutting down WebSocket server...');
    wss.close(() => {
        console.log('WebSocket server closed');
        if (dbPool) {
            dbPool.end();
        }
        process.exit(0);
    });
});

console.log(`WebSocket server running on ws://localhost:${PORT}`);


