# FleetForge - Deployment Instructions via FileZilla
**How to Upload Your Code to AWS Lightsail Server**

---

## 🎯 Overview

Since you'll be copying/pasting code and deploying via FileZilla to AWS Lightsail, this guide shows you exactly how to deploy your FleetForge application.

---

## 📋 Prerequisites

### What You Need:
1. **FileZilla Client** - FTP/SFTP software for file transfer
2. **AWS Lightsail Server** - Your server IP address
3. **SSH Credentials** - Username and private key or password
4. **PostgreSQL** - Installed on your Lightsail server

---

## 🚀 PART 1: Prepare Your Lightsail Server

### Step 1: Connect to Your Server via SSH

#### Using PuTTY (Windows):
1. Open PuTTY
2. Enter your Lightsail IP address
3. Port: 22
4. Click "Open"
5. Login with your credentials

#### Commands to Run on Server:
```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install Node.js 18 LTS
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs

# Verify installation
node --version  # Should show v18.x.x
npm --version   # Should show 9.x.x or higher

# Install PostgreSQL
sudo apt install -y postgresql postgresql-contrib

# Start PostgreSQL
sudo systemctl start postgresql
sudo systemctl enable postgresql

# Install PM2 (Process Manager for Node.js)
sudo npm install -g pm2
```

### Step 2: Create PostgreSQL Database

```bash
# Switch to postgres user
sudo -u postgres psql

# Inside PostgreSQL prompt, run:
CREATE DATABASE fleetforge;
CREATE USER fleetforge_user WITH PASSWORD 'Fleet@2024!';
GRANT ALL PRIVILEGES ON DATABASE fleetforge TO fleetforge_user;
ALTER DATABASE fleetforge OWNER TO fleetforge_user;
\q
```

### Step 3: Configure PostgreSQL for Network Access

```bash
# Edit PostgreSQL config
sudo nano /etc/postgresql/14/main/postgresql.conf

# Find and change:
listen_addresses = '*'

# Save (Ctrl+X, Y, Enter)

# Edit authentication config
sudo nano /etc/postgresql/14/main/pg_hba.conf

# Add this line at the end:
host    all             all             0.0.0.0/0               md5

# Save and restart PostgreSQL
sudo systemctl restart postgresql
```

### Step 4: Create Application Directory

```bash
# Create directory for your app
sudo mkdir -p /var/www/fleetforge
sudo chown -R $USER:$USER /var/www/fleetforge
cd /var/www/fleetforge
```

---

## 📁 PART 2: Install FileZilla and Connect

### Step 1: Install FileZilla

1. Download from: https://filezilla-project.org/
2. Download **FileZilla Client**
3. Run installer with default settings

### Step 2: Connect to Your Server

1. Open FileZilla
2. Click **File** → **Site Manager**
3. Click **New Site**
4. Name it: `FleetForge AWS Lightsail`

**Configure Connection:**
- **Protocol**: SFTP - SSH File Transfer Protocol
- **Host**: Your Lightsail IP (e.g., 44.123.456.789)
- **Port**: 22
- **Logon Type**: Normal (or Key file if using SSH key)
- **User**: ubuntu (or your AWS username)
- **Password**: Your password (or browse for .ppk key file)

5. Click **Connect**
6. If prompted about unknown host key, click **OK**

### Step 3: Navigate to Application Directory

**In FileZilla:**
- **Left side** (Local): Your computer
- **Right side** (Remote): Your server

**Remote site path:** Navigate to `/var/www/fleetforge`

---

## 📤 PART 3: Upload Your Files

### What to Upload:

#### From Your Local Computer, Upload These Folders/Files:

```
Your Computer                    →    Server /var/www/fleetforge/
====================================================================
backend/src/                     →    backend/src/
backend/prisma/                  →    backend/prisma/
backend/package.json             →    backend/package.json
backend/tsconfig.json            →    backend/tsconfig.json
.env.example                     →    .env
.gitignore                       →    .gitignore
```

### Step-by-Step Upload:

1. **Create backend folder on server:**
   - Right-click in Remote site panel
   - Click "Create directory"
   - Name it: `backend`

2. **Upload backend files:**
   - On your computer, navigate to your `fleetforge_claude/backend` folder
   - Select all files and folders
   - **Drag and drop** from left panel to right panel
   - FileZilla will upload everything

3. **Create .env file:**
   - After upload, right-click on `.env.example` on server
   - Click "View/Edit"
   - Your text editor will open
   - Update the DATABASE_URL:
     ```
     DATABASE_URL="postgresql://fleetforge_user:Fleet@2024!@localhost:5432/fleetforge"
     ```
   - Save the file as `.env` (remove .example)
   - Upload to server

---

## 🔧 PART 4: Set Up Application on Server

### Connect via SSH and run:

```bash
# Navigate to application directory
cd /var/www/fleetforge/backend

# Install dependencies
npm install

# Install TypeScript globally (needed for build)
sudo npm install -g typescript tsx

# Generate Prisma Client
npx prisma generate

# Run database migrations
npx prisma migrate deploy

# Build the TypeScript code
npm run build

# Test if it works
node dist/app.js
```

If you see the server start message, press `Ctrl+C` to stop it.

### Set Up PM2 (Keep Server Running):

```bash
# Start application with PM2
pm2 start dist/app.js --name fleetforge-api

# Save PM2 configuration
pm2 save

# Set PM2 to start on server reboot
pm2 startup
# Copy and run the command it gives you

# Check status
pm2 status

# View logs
pm2 logs fleetforge-api
```

---

## 🌐 PART 5: Configure Nginx (Reverse Proxy)

### Why Nginx?
So users can access your API via a domain name instead of IP:port

### Install and Configure:

```bash
# Install Nginx
sudo apt install -y nginx

# Create Nginx configuration
sudo nano /etc/nginx/sites-available/fleetforge

# Paste this configuration:
```

```nginx
server {
    listen 80;
    server_name your-domain.com;  # Replace with your domain or IP

    location / {
        proxy_pass http://localhost:3001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_cache_bypass $http_upgrade;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
```

```bash
# Save (Ctrl+X, Y, Enter)

# Enable the site
sudo ln -s /etc/nginx/sites-available/fleetforge /etc/nginx/sites-enabled/

# Test configuration
sudo nginx -t

# Restart Nginx
sudo systemctl restart nginx

# Enable Nginx to start on boot
sudo systemctl enable nginx
```

---

## 🔥 PART 6: Configure Firewall

```bash
# Allow SSH (Important! Don't lock yourself out!)
sudo ufw allow 22/tcp

# Allow HTTP
sudo ufw allow 80/tcp

# Allow HTTPS (for future SSL)
sudo ufw allow 443/tcp

# Allow Node.js app port (just in case)
sudo ufw allow 3001/tcp

# Enable firewall
sudo ufw enable

# Check status
sudo ufw status
```

---

## ✅ PART 7: Verify Deployment

### Test Your API:

1. **From your browser**, go to:
   ```
   http://YOUR_SERVER_IP/health
   ```

2. You should see:
   ```json
   {
     "status": "ok",
     "message": "FleetForge API is running",
     "timestamp": "2024-01-15T10:30:00.000Z",
     "environment": "development"
   }
   ```

### Check Server Status:

```bash
# Check if app is running
pm2 status

# View app logs
pm2 logs fleetforge-api

# View last 50 lines
pm2 logs fleetforge-api --lines 50

# Stop app
pm2 stop fleetforge-api

# Restart app
pm2 restart fleetforge-api
```

---

## 🔄 PART 8: How to Deploy Updates

### Every Time You Make Changes:

#### On Your Local Computer:
1. Edit your code
2. Test locally (`npm run dev`)
3. Open FileZilla
4. Upload changed files to server

#### On Your Server (via SSH):
```bash
cd /var/www/fleetforge/backend

# If you changed package.json:
npm install

# If you changed Prisma schema:
npx prisma generate
npx prisma migrate deploy

# Rebuild
npm run build

# Restart application
pm2 restart fleetforge-api

# Check logs for errors
pm2 logs fleetforge-api --lines 20
```

---

## 📊 PART 9: Monitoring & Maintenance

### View Application Logs:
```bash
# Real-time logs
pm2 logs fleetforge-api

# Stop watching logs: Ctrl+C

# View error logs only
pm2 logs fleetforge-api --err

# Clear logs
pm2 flush
```

### Monitor Server Resources:
```bash
# Check CPU and memory
pm2 monit

# Check disk space
df -h

# Check memory
free -h
```

### Database Backup (Important!):
```bash
# Backup database
pg_dump -U fleetforge_user fleetforge > backup_$(date +%Y%m%d).sql

# Restore from backup
psql -U fleetforge_user fleetforge < backup_20240115.sql
```

---

## 🆘 Troubleshooting

### Issue: "Cannot connect to database"
```bash
# Check if PostgreSQL is running
sudo systemctl status postgresql

# Start PostgreSQL
sudo systemctl start postgresql

# Check database exists
sudo -u postgres psql -l
```

### Issue: "Port 3001 already in use"
```bash
# Find what's using the port
sudo lsof -i :3001

# Kill the process
pm2 stop fleetforge-api
pm2 delete fleetforge-api
pm2 start dist/app.js --name fleetforge-api
```

### Issue: "502 Bad Gateway" from Nginx
```bash
# Check if app is running
pm2 status

# Check Nginx error logs
sudo tail -f /var/log/nginx/error.log

# Check app logs
pm2 logs fleetforge-api
```

### Issue: FileZilla won't connect
1. Check server is running (try SSH)
2. Verify IP address is correct
3. Check firewall allows port 22
4. Verify username/password

---

## 🔐 Security Checklist

- [ ] Change default passwords
- [ ] Keep `.env` file secure (never upload to GitHub)
- [ ] Enable firewall (ufw)
- [ ] Keep system updated (`sudo apt update && sudo apt upgrade`)
- [ ] Set up SSL certificate (use Certbot for free SSL)
- [ ] Regular database backups
- [ ] Monitor logs for suspicious activity

---

## 📝 Quick Reference Commands

### Application Management:
```bash
pm2 start dist/app.js --name fleetforge-api  # Start
pm2 stop fleetforge-api                      # Stop
pm2 restart fleetforge-api                   # Restart
pm2 logs fleetforge-api                      # View logs
pm2 status                                   # Check status
pm2 monit                                    # Monitor resources
```

### Database:
```bash
sudo -u postgres psql                        # Access PostgreSQL
\l                                           # List databases
\c fleetforge                               # Connect to database
\dt                                         # List tables
\q                                          # Quit
```

### Nginx:
```bash
sudo systemctl status nginx                  # Check status
sudo systemctl restart nginx                 # Restart
sudo nginx -t                               # Test configuration
```

---

## 🎉 Success!

If you can access `http://YOUR_SERVER_IP/health` and see the API response, **you've successfully deployed FleetForge!**

Next steps:
1. Set up domain name (optional)
2. Install SSL certificate
3. Set up automated backups
4. Deploy frontend (Week 3)

---

**Remember:** This is a development setup. Before going to production with real customers, you'll need additional security hardening and SSL certificates.
