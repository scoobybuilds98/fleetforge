# FleetForge - Complete Equipment Rental & Leasing Platform

## 🚀 Project Overview

**FleetForge** is a comprehensive, commercial-grade platform designed for equipment rental and leasing operations. Built for Mainland Truck and Trailer Sales with multi-tenant commercial capability.

### Key Features
- End-to-end rental lifecycle management (lead → reservation → lease → billing → reporting)
- Multi-equipment support (chassis, trailers, trucks, construction equipment)
- GPS integration with Samsara for real-time tracking and mileage billing
- QuickBooks integration for seamless accounting
- Customer portal for self-service operations
- Mobile app for field operations
- Advanced analytics with Claude AI integration (planned)

## 🏗️ Technology Stack

### Frontend
- **React 18** + **TypeScript** for type safety
- **Material-UI (MUI)** for professional UI components
- **Redux Toolkit** + **RTK Query** for state management
- **React Router v6** for navigation
- **AG-Grid** for data-heavy tables
- **Chart.js** for analytics and reporting

### Backend
- **Node.js** + **Express** + **TypeScript**
- **PostgreSQL** with **Prisma ORM**
- **Redis** for caching and job queues
- **Bull** for background job processing
- **Auth0** for authentication and RBAC

### Infrastructure
- **AWS Lightsail** (development)
- **AWS S3** for document storage
- **AWS SES** for email delivery
- **Docker** for containerization

### External Integrations
- **Samsara GPS** - Equipment tracking and mileage
- **QuickBooks Online** - Accounting synchronization
- **Auth0** - Authentication and user management
- **Claude AI** - Business intelligence (future)

## 📊 Business Model

### Equipment Management
- Multi-yard inventory tracking
- Equipment templates for standardization
- Financial tracking (purchase price, depreciation, ROI)
- Maintenance scheduling and cost tracking

### Customer Operations
- B2B customer management with custom rate structures
- Flexible billing cycles (daily/weekly/monthly proration)
- Mileage-based charging with GPS integration
- Document management with expiration tracking

### Financial Management
- Custom pro-rating calculations
- Multi-rate structures per customer/equipment
- Automated invoice generation and delivery
- Complete audit trail for compliance

## 📁 Project Structure

```
fleetforge/
├── README.md                     # This file
├── PROJECT_SPECIFICATION.md      # Detailed requirements
├── DEVELOPMENT_ROADMAP.md        # Phase-by-phase development plan
├── docker-compose.yml           # Development environment
├── .env.example                 # Environment variables template
├── database/
│   ├── schema/                  # Enhanced PostgreSQL schemas
│   ├── migrations/              # Database migration files
│   └── seeds/                   # Initial data
├── backend/
│   ├── package.json
│   ├── tsconfig.json
│   ├── prisma/
│   ├── src/
│   │   ├── controllers/         # API route handlers
│   │   ├── middleware/          # Auth, validation, etc.
│   │   ├── models/              # Database models
│   │   ├── services/            # Business logic
│   │   ├── routes/              # API route definitions
│   │   ├── utils/               # Helper functions
│   │   ├── types/               # TypeScript type definitions
│   │   └── app.ts               # Express app setup
│   └── tests/                   # Backend tests
├── frontend/
│   ├── package.json
│   ├── tsconfig.json
│   ├── public/
│   ├── src/
│   │   ├── components/          # Reusable UI components
│   │   ├── pages/               # Page components
│   │   ├── hooks/               # Custom React hooks
│   │   ├── services/            # API calls
│   │   ├── store/               # Redux store
│   │   ├── types/               # TypeScript types
│   │   ├── utils/               # Helper functions
│   │   └── App.tsx              # Main app component
│   └── build/                   # Production build
├── customer-portal/
│   ├── package.json             # Separate customer-facing app
│   └── src/
├── mobile-app/
│   ├── package.json             # React Native mobile app
│   └── src/
├── shared/
│   ├── types/                   # Shared TypeScript types
│   └── utils/                   # Shared utility functions
├── docs/
│   ├── api/                     # API documentation
│   ├── user-guide/              # User documentation
│   ├── technical/               # Technical documentation
│   └── business/                # Business requirements
└── deployment/
    ├── aws/                     # AWS deployment configs
    ├── docker/                  # Docker configurations
    └── scripts/                 # Deployment scripts
```

## 🎯 Development Phases

### Phase 1: Foundation (Weeks 1-4)
- ✅ Project setup and infrastructure
- ✅ Database schema and migrations
- ✅ Authentication with Auth0
- ✅ Core CRUD operations

### Phase 2: Core Business Logic (Weeks 5-8)
- Equipment templates and units management
- Customer management with rate structures
- Reservation system with conflict detection
- Lease management with state machines

### Phase 3: Billing & Operations (Weeks 9-12)
- Billing engine with pro-rating
- Invoice generation and delivery
- Maintenance and inspection modules
- Reporting and analytics

### Phase 4: Integrations (Weeks 13-16)
- Samsara GPS integration
- QuickBooks synchronization
- Customer portal development
- Mobile app for field operations

### Phase 5: Advanced Features (Weeks 17-20)
- Advanced analytics and reporting
- Claude AI integration
- Performance optimization
- Security hardening and testing

## 🔧 Development Setup

### Prerequisites
- Node.js 18+
- PostgreSQL 14+
- Redis 6+
- Docker & Docker Compose

### Quick Start
```bash
# Clone the repository
git clone <repository-url>
cd fleetforge

# Copy environment variables
cp .env.example .env

# Start development environment
docker-compose up -d

# Install dependencies and run migrations
cd backend && npm install && npx prisma migrate dev
cd ../frontend && npm install

# Start development servers
npm run dev:backend    # Starts on port 3001
npm run dev:frontend   # Starts on port 3000
```

## 🔒 Security & Compliance

- **Authentication**: Auth0 with JWT tokens and MFA support
- **Authorization**: Role-based access control (RBAC)
- **Data Protection**: Encryption at rest and in transit
- **File Security**: PDF-only uploads with virus scanning
- **API Security**: Rate limiting, input validation, CORS
- **Audit Trail**: Complete logging of all system changes

## 📈 Key Business Metrics

### Operational KPIs
- Equipment utilization rates by type and yard
- Revenue per unit and customer profitability
- Maintenance costs and downtime analysis
- On-time delivery and customer satisfaction

### Financial KPIs
- Monthly recurring revenue (MRR)
- Accounts receivable aging
- Equipment ROI and payback periods
- Cash flow and profitability analysis

## 🤝 Contributing

This is a private commercial project. All development is tracked through:
1. **GitHub Issues** for feature requests and bugs
2. **Pull Requests** for code review and integration
3. **Project Boards** for sprint planning and tracking

## 📄 License

Proprietary software owned by Mainland Truck and Trailer Sales.

## 📞 Support

For technical support or business inquiries:
- **Project Lead**: [Your Name]
- **Email**: [Your Email]
- **Documentation**: See `/docs` folder

---

**Built with ❤️ for the equipment rental industry**
