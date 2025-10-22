import express, { Express, Request, Response, NextFunction } from 'express';
import cors from 'cors';
import helmet from 'helmet';
import morgan from 'morgan';
import dotenv from 'dotenv';

// Load environment variables
dotenv.config();

// Create Express application
const app: Express = express();
const PORT = process.env.PORT || 3001;

// ============================================================================
// MIDDLEWARE
// ============================================================================

// Security middleware - protects against common vulnerabilities
app.use(helmet());

// CORS middleware - allows frontend to communicate with backend
app.use(cors({
  origin: process.env.FRONTEND_URL || 'http://localhost:3000',
  credentials: true
}));

// Body parsing middleware
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true, limit: '10mb' }));

// Logging middleware - logs all requests
app.use(morgan('dev'));

// ============================================================================
// ROUTES
// ============================================================================

// Health check endpoint - confirms server is running
app.get('/health', (req: Request, res: Response) => {
  res.json({
    status: 'ok',
    message: 'FleetForge API is running',
    timestamp: new Date().toISOString(),
    environment: process.env.NODE_ENV || 'development'
  });
});

// API version info
app.get('/api/v1', (req: Request, res: Response) => {
  res.json({
    name: 'FleetForge API',
    version: '1.0.0',
    description: 'Equipment Rental & Leasing Platform API',
    endpoints: {
      health: '/health',
      equipment: '/api/v1/equipment',
      customers: '/api/v1/customers',
      leases: '/api/v1/leases',
      invoices: '/api/v1/invoices'
    }
  });
});

// ============================================================================
// ERROR HANDLING
// ============================================================================

// 404 handler - route not found
app.use((req: Request, res: Response) => {
  res.status(404).json({
    error: 'Not Found',
    message: `Route ${req.method} ${req.path} not found`,
    path: req.path
  });
});

// Global error handler
app.use((err: Error, req: Request, res: Response, next: NextFunction) => {
  console.error('Error:', err);

  res.status(500).json({
    error: 'Internal Server Error',
    message: process.env.NODE_ENV === 'production'
      ? 'An error occurred'
      : err.message
  });
});

// ============================================================================
// SERVER START
// ============================================================================

app.listen(PORT, () => {
  console.log('='.repeat(60));
  console.log(`🚀 FleetForge API Server Started`);
  console.log('='.repeat(60));
  console.log(`📡 Port: ${PORT}`);
  console.log(`🌍 Environment: ${process.env.NODE_ENV || 'development'}`);
  console.log(`🕐 Started at: ${new Date().toLocaleString()}`);
  console.log('='.repeat(60));
  console.log('Available endpoints:');
  console.log(`  GET  http://localhost:${PORT}/health`);
  console.log(`  GET  http://localhost:${PORT}/api/v1`);
  console.log('='.repeat(60));
});

export default app;
