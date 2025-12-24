# Chat System Setup Instructions

## Overview
The chat system allows real-time communication between customers and saloons using WebSockets.

## Prerequisites
- Node.js installed on your system
- MySQL database running (XAMPP includes this)
- PHP server running (XAMPP includes this)

## Setup Steps

### 1. Install Node.js Dependencies
Open a terminal/command prompt in the project directory and run:

```bash
npm install
```

This will install:
- `ws` - WebSocket server library
- `mysql2` - MySQL database driver for Node.js

### 2. Start the WebSocket Server
Run the WebSocket server:

```bash
node websocket_server.js
```

Or use npm:

```bash
npm start
```

The server will start on port 8080. You should see:
```
WebSocket server running on ws://localhost:8080
```

### 3. Keep Server Running
The WebSocket server must be running continuously for real-time chat to work. You can:

**Option A: Run in background (Windows PowerShell)**
```powershell
Start-Process node -ArgumentList "websocket_server.js" -WindowStyle Hidden
```

**Option B: Use PM2 (Recommended for production)**
```bash
npm install -g pm2
pm2 start websocket_server.js
pm2 save
pm2 startup
```

**Option C: Run in a separate terminal window**
Keep the terminal window open while using the application.

### 4. Access Chat Feature

**For Customers:**
1. Log in as a customer
2. Browse saloons and click "View Details" on any saloon
3. Click the "Chat" tab
4. Start chatting with the saloon

**For Saloons:**
1. Log in as a saloon
2. Click the "Chat" tab in the dashboard
3. Select a customer from the conversation list
4. Start chatting

## Features

- ✅ Real-time bidirectional messaging
- ✅ Message history persistence
- ✅ WhatsApp-like chat bubbles
- ✅ Auto-scroll to latest messages
- ✅ Automatic reconnection on disconnect
- ✅ Multiple concurrent conversations

## Troubleshooting

### WebSocket connection fails
- Ensure the WebSocket server is running on port 8080
- Check firewall settings
- Verify database connection in `websocket_server.js`

### Messages not appearing in real-time
- Check browser console for WebSocket errors
- Verify WebSocket server is running
- Check database connection

### Port 8080 already in use
Edit `websocket_server.js` and change the PORT constant to an available port (e.g., 8081, 3000).

## File Structure

- `chat_api.php` - PHP API for chat operations (get/send messages)
- `websocket_server.js` - Node.js WebSocket server for real-time messaging
- `package.json` - Node.js dependencies
- `customer_dashboard.php` - Customer chat interface (modified)
- `saloon_dashboard.php` - Saloon chat interface (modified)

## Database Tables Used

- `chat` - Stores chat sessions between saloon_id and customer_id
- `message` - Stores individual messages with sender_type, message_text, time_stamp, and chat_id






