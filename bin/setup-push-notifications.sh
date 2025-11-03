#!/bin/bash
#
# Quick Push Notification Setup Script
# 
# Description: Interactive helper script to set up push notifications
# Usage: bash bin/setup-push-notifications.sh
#

set -e

echo ""
echo "╔════════════════════════════════════════════════════════════════════╗"
echo "║         Push Notification Setup - Interactive Helper              ║"
echo "╚════════════════════════════════════════════════════════════════════╝"
echo ""

# Check if .env file exists
if [ ! -f .env ]; then
    echo "✗ Error: .env file not found"
    echo "  Please create a .env file first (copy from .env.example)"
    exit 1
fi

# Check if VAPID keys are already configured
VAPID_PUBLIC=$(grep -E "^VAPID_PUBLIC_KEY=" .env | cut -d '=' -f 2 | tr -d '"' || echo "")
VAPID_PRIVATE=$(grep -E "^VAPID_PRIVATE_KEY=" .env | cut -d '=' -f 2 | tr -d '"' || echo "")

if [ -n "$VAPID_PUBLIC" ] && [ "$VAPID_PUBLIC" != "CHANGE_ME_VAPID_PUBLIC" ] && \
   [ -n "$VAPID_PRIVATE" ] && [ "$VAPID_PRIVATE" != "CHANGE_ME_VAPID_PRIVATE" ]; then
    echo "✓ VAPID keys already configured"
    echo ""
    echo "Current keys:"
    echo "  Public:  ${VAPID_PUBLIC:0:30}..."
    echo "  Private: ${VAPID_PRIVATE:0:30}..."
    echo ""
    read -p "Do you want to regenerate them? (y/N): " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo ""
        echo "Skipping key generation. Current keys will be used."
        echo ""
    else
        GENERATE_KEYS="yes"
    fi
else
    echo "⚠ VAPID keys not configured"
    echo ""
    GENERATE_KEYS="yes"
fi

if [ "$GENERATE_KEYS" = "yes" ]; then
    echo "Step 1: Generating VAPID keys..."
    echo "────────────────────────────────────────────────────────────────────"
    
    # Generate new VAPID keys
    php generate_vapid.php > /tmp/vapid_output.txt 2>&1
    
    if [ $? -ne 0 ]; then
        echo "✗ Error generating VAPID keys"
        cat /tmp/vapid_output.txt
        exit 1
    fi
    
    # Extract keys from output
    NEW_PUBLIC=$(grep 'VAPID_PUBLIC_KEY=' /tmp/vapid_output.txt | cut -d '"' -f 2)
    NEW_PRIVATE=$(grep 'VAPID_PRIVATE_KEY=' /tmp/vapid_output.txt | cut -d '"' -f 2)
    
    if [ -z "$NEW_PUBLIC" ] || [ -z "$NEW_PRIVATE" ]; then
        echo "✗ Error: Could not extract VAPID keys from output"
        cat /tmp/vapid_output.txt
        exit 1
    fi
    
    echo "✓ New VAPID keys generated"
    echo ""
    echo "  Public:  ${NEW_PUBLIC}"
    echo "  Private: ${NEW_PRIVATE}"
    echo ""
    
    # Update .env file
    echo "Step 2: Updating .env file..."
    echo "────────────────────────────────────────────────────────────────────"
    
    # Backup .env
    cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
    echo "✓ Backed up .env file"
    
    # Update VAPID keys in .env
    if grep -q "^VAPID_PUBLIC_KEY=" .env; then
        sed -i "s|^VAPID_PUBLIC_KEY=.*|VAPID_PUBLIC_KEY=\"$NEW_PUBLIC\"|" .env
    else
        echo "VAPID_PUBLIC_KEY=\"$NEW_PUBLIC\"" >> .env
    fi
    
    if grep -q "^VAPID_PRIVATE_KEY=" .env; then
        sed -i "s|^VAPID_PRIVATE_KEY=.*|VAPID_PRIVATE_KEY=\"$NEW_PRIVATE\"|" .env
    else
        echo "VAPID_PRIVATE_KEY=\"$NEW_PRIVATE\"" >> .env
    fi
    
    echo "✓ Updated .env file with new VAPID keys"
    echo ""
fi

# Check VAPID_SUBJECT
echo "Step 3: Checking VAPID subject..."
echo "────────────────────────────────────────────────────────────────────"

VAPID_SUBJECT=$(grep -E "^VAPID_SUBJECT=" .env | cut -d '=' -f 2 | tr -d '"' || echo "")

if [ -z "$VAPID_SUBJECT" ] || [ "$VAPID_SUBJECT" = "mailto:ops@defecttracker.uk" ]; then
    echo "⚠ VAPID_SUBJECT needs to be configured"
    echo ""
    echo "Enter your contact email or website URL:"
    read -p "  (e.g., mailto:admin@example.com or https://example.com): " SUBJECT_INPUT
    
    if [ -n "$SUBJECT_INPUT" ]; then
        if grep -q "^VAPID_SUBJECT=" .env; then
            sed -i "s|^VAPID_SUBJECT=.*|VAPID_SUBJECT=\"$SUBJECT_INPUT\"|" .env
        else
            echo "VAPID_SUBJECT=\"$SUBJECT_INPUT\"" >> .env
        fi
        echo "✓ Updated VAPID_SUBJECT in .env"
    else
        echo "⚠ Skipping VAPID_SUBJECT update"
    fi
else
    echo "✓ VAPID_SUBJECT already configured: $VAPID_SUBJECT"
fi

echo ""
echo "Step 4: Verification"
echo "────────────────────────────────────────────────────────────────────"
echo ""

# Display final configuration
FINAL_PUBLIC=$(grep -E "^VAPID_PUBLIC_KEY=" .env | cut -d '=' -f 2 | tr -d '"')
FINAL_PRIVATE=$(grep -E "^VAPID_PRIVATE_KEY=" .env | cut -d '=' -f 2 | tr -d '"')
FINAL_SUBJECT=$(grep -E "^VAPID_SUBJECT=" .env | cut -d '=' -f 2 | tr -d '"')

echo "✓ Push notification configuration complete!"
echo ""
echo "Current configuration:"
echo "  Public Key: ${FINAL_PUBLIC:0:40}..."
echo "  Private Key: ${FINAL_PRIVATE:0:40}..."
echo "  Subject: $FINAL_SUBJECT"
echo ""

# Test notifications
echo "────────────────────────────────────────────────────────────────────"
echo ""
read -p "Would you like to test push notifications now? (y/N): " -n 1 -r
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo ""
    echo "Testing push notifications..."
    echo ""
    php bin/test-push-notification.php "Test from setup script"
else
    echo ""
    echo "Skipping test. You can test later with:"
    echo "  php bin/test-push-notification.php"
fi

echo ""
echo "Next steps:"
echo "  1. Subscribe to notifications in your browser:"
echo "     - Open browser console (F12)"
echo "     - Run: window.subscribeToPush()"
echo "     - Grant permission when prompted"
echo ""
echo "  2. Test notifications:"
echo "     php bin/test-push-notification.php"
echo ""
echo "  3. Set up cron job for automated notifications:"
echo "     */15 * * * * php $(pwd)/bin/reminders.php 60"
echo ""
echo "  4. Read full documentation:"
echo "     cat PUSH_NOTIFICATIONS.md"
echo ""
echo "╔════════════════════════════════════════════════════════════════════╗"
echo "║                      Setup Complete! 🎉                            ║"
echo "╚════════════════════════════════════════════════════════════════════╝"
echo ""
