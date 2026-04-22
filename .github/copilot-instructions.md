# Project Infrastructure & Deployment Rules

## Cloud Platform
- Azure

## Container & Image Flow
- Build Docker images locally / in CI
- Push Docker images to **Azure Container Registry (ACR)**
- Deploy images from ACR to **Azure Web App (Container)**
- No Kubernetes, no AKS; use Azure Web App direct container deployment

## Environment
- Docker-based deployment
- Azure Web App runs container directly
- Use environment variables configured in Azure Portal

## Coding & Script Expectations
- Generate Dockerfile optimized for Azure Web App
- Generate deployment commands for az cli (az acr build, az webapp create, etc.)
- When writing CI/CD scripts, target Azure ACR + Azure Web App
- Do not suggest Kubernetes, AWS, GCP unless explicitly asked
 