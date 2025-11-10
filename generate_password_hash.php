<?php
// Script để generate password hash cho admin123
echo "Password hash cho 'admin123':\n";
echo password_hash('admin123', PASSWORD_DEFAULT) . "\n";
