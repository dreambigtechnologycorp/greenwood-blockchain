#!/bin/bash
# 
# Greenwood Testnet Deployment Helper
# 
# This script provides common deployment commands for the testnet.
#
# Usage:
#   ./deploy.sh <command>
#
# Commands:
#   init      - Initialize Terraform
#   validate  - Validate Terraform configuration
#   plan      - Show Terraform plan
#   inventory - Generate Ansible inventory
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TERRAFORM_DIR="$SCRIPT_DIR/terraform"
ANSIBLE_DIR="$SCRIPT_DIR/ansible"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

print_header() {
    echo -e "\n${YELLOW}=== $1 ===${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

# Check if terraform is installed
check_terraform() {
    if ! command -v terraform &> /dev/null; then
        print_error "Terraform not found. Please install Terraform first."
        exit 1
    fi
}

# Check if ansible is installed
check_ansible() {
    if ! command -v ansible &> /dev/null; then
        print_error "Ansible not found. Please install Ansible first."
        exit 1
    fi
}

# Initialize Terraform
cmd_init() {
    print_header "Initializing Terraform"
    check_terraform
    
    terraform -chdir="$TERRAFORM_DIR" init
    
    print_success "Terraform initialized"
}

# Validate Terraform configuration
cmd_validate() {
    print_header "Validating Terraform configuration"
    check_terraform
    
    terraform -chdir="$TERRAFORM_DIR" validate
    
    print_success "Terraform configuration valid"
}

# Show Terraform plan
cmd_plan() {
    print_header "Terraform Plan"
    check_terraform
    
    terraform -chdir="$TERRAFORM_DIR" plan
    
    print_success "Terraform plan complete"
}

# Generate Ansible inventory
cmd_inventory() {
    print_header "Generating Ansible Inventory"
    check_terraform
    check_ansible
    
    mkdir -p "$ANSIBLE_DIR/inventory"
    
    terraform -chdir="$TERRAFORM_DIR" output -json | \
        php "$ANSIBLE_DIR/generate_inventory.php" > "$ANSIBLE_DIR/inventory/generated.ini"
    
    print_success "Inventory generated: $ANSIBLE_DIR/inventory/generated.ini"
}

# Main
case "${1:-help}" in
    init)
        cmd_init
        ;;
    validate)
        cmd_validate
        ;;
    plan)
        cmd_plan
        ;;
    inventory)
        cmd_inventory
        ;;
    help|--help|-h)
        echo "Greenwood Testnet Deployment Helper"
        echo ""
        echo "Usage: $0 <command>"
        echo ""
        echo "Commands:"
        echo "  init      - Initialize Terraform"
        echo "  validate  - Validate Terraform configuration"
        echo "  plan      - Show Terraform plan"
        echo "  inventory - Generate Ansible inventory"
        echo "  help      - Show this help message"
        ;;
    *)
        print_error "Unknown command: $1"
        echo "Use '$0 help' for usage information"
        exit 1
        ;;
esac
