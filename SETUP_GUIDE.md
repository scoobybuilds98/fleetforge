# FleetForge Setup Guide - For Non-Coders
**Complete Step-by-Step Instructions for Week 1, Day 1**

---

## 📋 What You Need Before Starting

### Required Software (We'll install together)
1. **Node.js** - JavaScript runtime (like an engine that runs the code)
2. **PostgreSQL** - Database system (where all data is stored)
3. **Visual Studio Code** - Code editor (optional, for viewing/editing files)
4. **Git** - Version control (already set up since you have GitHub)

### Information You'll Need
- AWS Lightsail server IP address
- PostgreSQL database credentials (we're switching from MySQL)

---

## 🚀 STEP 1: Install Node.js

### Windows:
1. Go to https://nodejs.org/
2. Download the **LTS (Long Term Support)** version
3. Run the installer
4. Keep clicking "Next" with default settings
5. Click "Finish"

### Verify Installation:
1. Press `Windows + R`
2. Type `cmd` and press Enter
3. In the black window (Command Prompt), type:
   ```bash
   node --version
   ```
4. You should see something like `v18.19.0` or `v20.11.0`
5. Also check npm (Node Package Manager):
   ```bash
   npm --version
   ```
6. You should see something like `10.2.4`

✅ **Success!** Node.js is installed if you see version numbers.

---

## 🗄️ STEP 2: Install PostgreSQL Locally (For Testing)

### Why Local PostgreSQL?
You have PostgreSQL on AWS Lightsail, but during development you'll want a local copy for testing without affecting your live server.

### Windows Installation:
1. Go to https://www.postgresql.org/download/windows/
2. Click "Download the installer"
3. Download from **EnterpriseDB**
4. Run the installer
5. **IMPORTANT - Remember These Settings:**
   - **Password**: Use `Fleet@2024!` (same as your AWS password for simplicity)
   - **Port**: Keep default `5432`
   - Click "Next" for everything else

### Verify Installation:
1. In Start Menu, search for "pgAdmin 4"
2. Open it (a web browser window will open)
3. Enter the password you just set: `Fleet@2024!`
4. You should see "PostgreSQL 14" or higher in the left sidebar

✅ **Success!** PostgreSQL is installed.

---

## 💻 STEP 3: Install Visual Studio Code (Optional but Recommended)

### Why VS Code?
It makes viewing and editing code files much easier with color-coding and helpful features.

### Installation:
1. Go to https://code.visualstudio.com/
2. Click "Download for Windows"
3. Run the installer
4. Keep default settings, click "Next" until "Finish"

✅ **Success!** VS Code is installed.

---

## 📁 STEP 4: Navigate to Your Project Folder

### Using Command Prompt:
1. Press `Windows + R`
2. Type `cmd` and press Enter
3. Navigate to your project:
   ```bash
   cd C:\path\to\fleetforge_claude
   ```

   **Example:** If your project is in `C:\Users\YourName\Documents\GitHub\fleetforge_claude`, type:
   ```bash
   cd C:\Users\YourName\Documents\GitHub\fleetforge_claude
   ```

### Verify You're in the Right Place:
```bash
dir
```
You should see folders like: `backend`, `frontend`, `database`, etc.

---

## 📦 STEP 5: Install Backend Dependencies

### What are Dependencies?
These are pre-built code packages that other developers created. We use them so we don't have to build everything from scratch.

### Install Command:
1. Make sure you're in the project folder
2. Navigate to backend:
   ```bash
   cd backend
   ```

3. Install all dependencies (this will take 2-3 minutes):
   ```bash
   npm install
   ```

### What You'll See:
- A progress bar
- Lots of text scrolling by (this is normal!)
- "added XXX packages" when done
- A new folder `node_modules` will appear (this contains all the dependencies)

✅ **Success!** All backend packages are installed.

---

## 🗄️ STEP 6: Create Local PostgreSQL Database

### Using pgAdmin:
1. Open pgAdmin 4 (search in Start Menu)
2. Enter your password: `Fleet@2024!`
3. Right-click on "PostgreSQL 14" → "Create" → "Database"
4. **Database name**: `fleetforge`
5. **Owner**: postgres
6. Click "Save"

### Alternative - Using Command Line:
If you prefer using commands:
```bash
psql -U postgres
```
Enter password: `Fleet@2024!`

Then type:
```sql
CREATE DATABASE fleetforge;
CREATE USER fleetforge_user WITH PASSWORD 'Fleet@2024!';
GRANT ALL PRIVILEGES ON DATABASE fleetforge TO fleetforge_user;
\q
```

✅ **Success!** Database created.

---

## 🔧 STEP 7: Update Environment Variables

### What is .env?
This file stores sensitive information like passwords and API keys. It's like a settings file for your app.

### Steps:
1. In your project folder, you should see a file called `.env`
2. Open it with VS Code or Notepad
3. Find this line:
   ```
   DATABASE_URL="postgresql://fleetforge_user:Fleet@2024!@localhost:5432/fleetforge"
   ```
4. This is already correct for local development!

### For AWS Lightsail (Later):
When you're ready to deploy, change `localhost` to your AWS server IP:
```
DATABASE_URL="postgresql://fleetforge_user:Fleet@2024!@YOUR_AWS_IP:5432/fleetforge"
```

✅ **Success!** Environment configured.

---

## 🏗️ STEP 8: Set Up Database with Prisma

### What is Prisma?
Prisma is a tool that creates database tables automatically based on our schema file. You don't have to write SQL!

### Generate Prisma Client:
```bash
npm run prisma:generate
```
You'll see: "✔ Generated Prisma Client"

### Create Database Tables:
```bash
npm run prisma:migrate
```
- It will ask for a migration name
- Type: `initial_setup`
- Press Enter

### What Just Happened?
Prisma created 20+ tables in your PostgreSQL database based on the schema we defined!

### Verify:
1. Open pgAdmin 4
2. Expand: PostgreSQL 14 → Databases → fleetforge → Schemas → public → Tables
3. You should see tables like: `Customer`, `EquipmentUnit`, `Lease`, `Invoice`, etc.

✅ **Success!** Database tables created.

---

## 🚀 STEP 9: Start the Development Server

### Run the Server:
```bash
npm run dev
```

### What You'll See:
```
============================================================
🚀 FleetForge API Server Started
============================================================
📡 Port: 3001
🌍 Environment: development
🕐 Started at: [current time]
============================================================
Available endpoints:
  GET  http://localhost:3001/health
  GET  http://localhost:3001/api/v1
============================================================
```

### Test It's Working:
1. Open your web browser
2. Go to: `http://localhost:3001/health`
3. You should see:
   ```json
   {
     "status": "ok",
     "message": "FleetForge API is running",
     "timestamp": "2024-01-15T10:30:00.000Z",
     "environment": "development"
   }
   ```

✅ **Success!** Your backend server is running!

---

## 🎉 STEP 10: What You've Accomplished

### You Just Built:
✅ Complete project folder structure
✅ Backend Node.js application with TypeScript
✅ PostgreSQL database with 20+ tables
✅ Express.js API server
✅ Environment configuration
✅ Database migrations system

### Your Backend Stack:
- **Node.js + Express** - Web server
- **TypeScript** - Type-safe JavaScript
- **PostgreSQL** - Relational database
- **Prisma** - Database toolkit
- **20+ Tables** - Complete data structure

---

## 📝 Common Issues & Solutions

### Issue 1: "npm is not recognized"
**Solution:** Node.js not installed or not in PATH
- Restart your computer after installing Node.js
- Reinstall Node.js and check "Add to PATH"

### Issue 2: "Cannot connect to database"
**Solution:** PostgreSQL not running
- Open Services (Windows + R, type `services.msc`)
- Find "postgresql-x64-14"
- Right-click → Start

### Issue 3: "Port 3001 already in use"
**Solution:** Another app is using port 3001
- Change PORT in `.env` to `3002` or `3003`
- Or find and close the app using port 3001

### Issue 4: "Permission denied"
**Solution:** Run Command Prompt as Administrator
- Right-click Command Prompt
- Select "Run as administrator"

---

## 🔜 Next Steps - Week 1, Day 2

Tomorrow we'll build:
1. **API Routes** - CRUD operations for Equipment
2. **API Routes** - CRUD operations for Customers
3. **Validation** - Input validation middleware
4. **Testing** - Test our API endpoints
5. **Documentation** - Auto-generated API docs

---

## 📞 Quick Reference Commands

### Start Development Server:
```bash
cd C:\path\to\fleetforge_claude\backend
npm run dev
```

### Stop Server:
Press `Ctrl + C` in the Command Prompt

### View Database:
```bash
npm run prisma:studio
```
This opens a visual database browser at `http://localhost:5555`

### Generate Prisma Client (after schema changes):
```bash
npm run prisma:generate
```

### Create New Migration (after schema changes):
```bash
npm run prisma:migrate
```

---

## 🎯 Project Structure Quick Reference

```
fleetforge_claude/
├── backend/
│   ├── src/
│   │   ├── app.ts              ← Main server file
│   │   ├── controllers/        ← API logic (tomorrow)
│   │   ├── routes/             ← API endpoints (tomorrow)
│   │   ├── middleware/         ← Security & validation (tomorrow)
│   │   └── services/           ← Business logic (later)
│   ├── prisma/
│   │   └── schema.prisma       ← Database structure
│   ├── package.json            ← Dependencies list
│   ├── tsconfig.json           ← TypeScript settings
│   └── .env                    ← Your settings (passwords, etc.)
├── frontend/                   ← Week 3
├── database/                   ← SQL files
└── docs/                       ← Documentation
```

---

## ✅ Checklist - Did Everything Work?

- [ ] Node.js installed (v18 or higher)
- [ ] PostgreSQL installed locally
- [ ] Project dependencies installed (`node_modules` folder exists)
- [ ] Database created (visible in pgAdmin)
- [ ] Database tables created (20+ tables visible)
- [ ] Server starts without errors
- [ ] Health check endpoint returns "ok"
- [ ] You can view database in Prisma Studio

If you checked all boxes: **Congratulations! Day 1 Complete! 🎉**

---

## 🆘 Need Help?

### Check These First:
1. Make sure you're in the right folder (`backend` folder)
2. Make sure PostgreSQL service is running
3. Check `.env` file has correct database URL
4. Try restarting your computer

### Still Stuck?
1. Take a screenshot of the error
2. Note what command you ran
3. Note what step you're on
4. Contact support with these details

---

**Remember:** Programming is 90% debugging and 10% writing code. Errors are normal and expected! Each error you fix makes you better at this. 💪
