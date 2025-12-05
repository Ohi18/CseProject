<?php
// Helper script to generate password hash for SQL INSERT
// Run this file to get the hashed password for "123456"

$password = '123456';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Password: " . $password . "\n";
echo "Hash: " . $hash . "\n";
echo "\nSQL INSERT statement:\n";
echo "INSERT INTO `users` (`email`, `password_hash`, `user_type`) \n";
echo "VALUES ('test@example.com', '" . $hash . "', 'customer');\n";
?>



