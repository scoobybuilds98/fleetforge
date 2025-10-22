# FleetForge - Quick Start Guide
**Get Running in 5 Minutes**

---

## 🚀 Installation (One-Time Setup)

### 1. Install Software (if not already installed):
- **Node.js 18+**: https://nodejs.org/ (download LTS version)
- **PostgreSQL 14+**: https://www.postgresql.org/download/
- **VS Code** (optional): https://code.visualstudio.com/

### 2. Install Dependencies:
```bash
# Navigate to backend folder
cd backend

# Install all packages (this takes 2-3 minutes)
npm install
```

### 3. Create Database:
```bash
# Option A: Using pgAdmin
1. Open pgAdmin
2. Right-click PostgreSQL → Create → Database
3. Name: fleetforge
4. Click Save

# Option B: Using command line
psql -U postgres
CREATE DATABASE fleetforge;
\q
```

### 4. Set Up Database Tables:
```bash
# Generate Prisma Client
npm run prisma:generate

# Create all tables
npm run prisma:migrate
# When prompted for migration name, type: initial_setup
```

---

## ▶️ Daily Workflow (Every Day)

### Start Your Development Server:
```bash
# 1. Navigate to backend folder
cd backend

# 2. Start the server
npm run dev

# 3. You should see:
# 🚀 FleetForge API Server Started
# 📡 Port: 3001
```

### Test It's Working:
Open browser → http://localhost:3001/health

Should see: `{"status": "ok", ...}`

### Stop the Server:
Press `Ctrl + C` in terminal

---

## 🗄️ View Your Database

### Option 1: Prisma Studio (Recommended)
```bash
npm run prisma:studio
```
Opens http://localhost:5555 with visual database browser

### Option 2: pgAdmin
1. Open pgAdmin
2. Navigate: PostgreSQL → Databases → fleetforge → Schemas → public → Tables

---

## 📁 Important Files

### Files You'll Edit Often:
- `backend/src/app.ts` - Main server file
- `backend/prisma/schema.prisma` - Database structure
- `backend/.env` - Your settings (passwords, etc.)

### Files to Read:
- `SETUP_GUIDE.md` - Complete setup instructions
- `DEPLOYMENT_INSTRUCTIONS.md` - How to deploy to AWS
- `WEEK1_DAY1_SUMMARY.md` - What you accomplished

### Don't Touch (Generated Automatically):
- `node_modules/` - Dependencies
- `dist/` - Compiled code
- `prisma/migrations/` - Database migrations

---

## 🔧 Common Commands

### Development:
```bash
npm run dev              # Start development server
npm run build            # Build for production
npm run start            # Run production build
```

### Database:
```bash
npm run prisma:generate  # Regenerate Prisma Client
npm run prisma:migrate   # Create/run database migrations
npm run prisma:studio    # Open database viewer
```

### Code Quality:
```bash
npm run lint             # Check code for errors
npm run format           # Format code nicely
npm test                 # Run tests
```

---

## 🐛 Something Not Working?

### "Cannot connect to database"
→ Make sure PostgreSQL is running
→ Check Start Menu → Services → postgresql

### "Port 3001 already in use"
→ Another program is using that port
→ Change PORT in `.env` to 3002

### "Cannot find module"
→ Dependencies not installed
→ Run: `npm install`

### "Prisma Client not found"
→ Prisma Client not generated
→ Run: `npm run prisma:generate`

---

## 📞 Quick Help

### Check if things are installed:
```bash
node --version    # Should show v18.x.x or higher
npm --version     # Should show 9.x.x or higher
psql --version    # Should show PostgreSQL 14.x or higher
```

### Reset everything (if really stuck):
```bash
# Delete node_modules and reinstall
rm -rf node_modules
npm install

# Regenerate Prisma
npm run prisma:generate
```

---

## ✅ Daily Checklist

Before starting work:
- [ ] PostgreSQL is running
- [ ] In the `backend` folder
- [ ] Dependencies installed (`node_modules` exists)
- [ ] `.env` file exists and has correct database URL

Start coding:
- [ ] Run `npm run dev`
- [ ] See server started message
- [ ] Open http://localhost:3001/health
- [ ] See `"status": "ok"`

---

## 🎯 What's Next?

### Today (Day 1):
✅ Project setup - DONE!
✅ Database created - DONE!
✅ Server running - DONE!

### Tomorrow (Day 2):
- Build Equipment API (Create, Read, Update, Delete)
- Build Customer API (Create, Read, Update, Delete)
- Add input validation
- Test with Postman/Insomnia

### This Week:
- Day 1: ✅ Foundation
- Day 2: CRUD APIs
- Day 3: Authentication
- Day 4: Security & Testing
- Day 5: Deployment

---

## 💡 Pro Tips

1. **Keep the server running** while you code - it auto-reloads on changes
2. **Check browser/terminal** for errors after changes
3. **Commit often** to Git - saves your progress
4. **Read error messages** - they tell you what's wrong
5. **Use Prisma Studio** - easiest way to see your data

---

## 🎉 You're All Set!

You now have:
✅ Professional backend architecture
✅ PostgreSQL database with 20+ tables
✅ TypeScript for type safety
✅ Express.js API server
✅ Complete documentation

**Ready to build something amazing! 🚀**

---

*Need more details? Check SETUP_GUIDE.md*
*Ready to deploy? Check DEPLOYMENT_INSTRUCTIONS.md*
*Want to understand what you built? Check WEEK1_DAY1_SUMMARY.md*
