#!/bin/bash

echo "Installing export libraries for Trainee Management System..."
echo

# Check if composer is installed
if ! command -v composer &> /dev/null; then
    echo "ERROR: Composer is not installed!"
    echo "Please install Composer from https://getcomposer.org/download/"
    exit 1
fi

echo "Composer found. Installing libraries..."
echo

# Install libraries
composer install --no-dev --optimize-autoloader

if [ $? -eq 0 ]; then
    echo
    echo "SUCCESS: Export libraries installed successfully!"
    echo
    echo "You can now use Excel and PDF export features."
    echo
else
    echo
    echo "ERROR: Failed to install libraries!"
    echo "Please check the error messages above."
    echo
    exit 1
fi


